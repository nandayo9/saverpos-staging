<?php
namespace Modules\Recommerce\Services\Intelligence;
use LogicException;
/** Deterministic CPU-only log-residual regression; training and inference run on cPanel PHP. */
final class ResaleModel {
 const CRITERIA=['version'=>'resale-evaluation-1','min_rows'=>60,'min_variant_train'=>8,'mae_ratio_max'=>.98,'overvaluation_p90_ratio_max'=>1.0];
 public function train(array $rows,int $now): array {
  $clean=[];$excluded=[];$devices=[];
  usort($rows,fn($a,$b)=>strtotime($a['sold_at']??'')<=>strtotime($b['sold_at']??''));
  foreach($rows as $r){$reason=null;$id=$r['device_key']??'';
   if(!$id||isset($devices[$id]))$reason='DUPLICATE_DEVICE';
   elseif(($r['origin']??'')!=='NATIVE_COMPLETED_SALE'||!empty($r['fixture'])||!empty($r['returned'])||!empty($r['cancelled']))$reason='NON_COMMERCIAL_OUTCOME';
   elseif(($r['target_basis']??'')!=='NET_ITEM_EX_TAX_SHIPPING'||($r['sale_minor']??0)<=0||($r['baseline_minor']??0)<=0)$reason='UNKNOWN_TARGET_OR_BASELINE';
   elseif(!strtotime($r['feature_at']??'')||!strtotime($r['valued_at']??'')||!strtotime($r['sold_at']??'')||strtotime($r['feature_at'])>strtotime($r['valued_at'])||strtotime($r['valued_at'])>=strtotime($r['sold_at'])||strtotime($r['sold_at'])>$now)$reason='TEMPORAL_LEAKAGE';
   if(!$reason){try{$this->features($r);}catch(LogicException $e){$reason='INVALID_PRE_VALUATION_FEATURES';}}
   if(!$reason&&(!is_string($r['variant_id']??null)||$r['variant_id']===''||!is_int($r['sale_minor'])||!is_int($r['baseline_minor'])||max($r['sale_minor'],$r['baseline_minor'])>100000000))$reason='INVALID_VARIANT_OR_MONEY';
   if($reason){$excluded[]=$reason;continue;}$devices[$id]=true;$r['sold_at']=gmdate('c',strtotime($r['sold_at']));$clean[]=$r;
  }
  $base=['algorithm'=>'log-residual-condition-storage-2','criteria'=>self::CRITERIA,'dataset_sha256'=>hash('sha256',json_encode($clean,JSON_THROW_ON_ERROR)),'eligible_count'=>count($clean),'exclusions'=>array_count_values($excluded),'target'=>'NET_ITEM_EX_TAX_SHIPPING','trained_at'=>gmdate('c',$now)];
  if(count($clean)<self::CRITERIA['min_rows'])return $base+['status'=>'INSUFFICIENT_DATA','promotable'=>false];
  $n=count($clean);$train=array_slice($clean,0,(int)floor(.6*$n));$cal=array_slice($clean,count($train),(int)floor(.2*$n));$test=array_slice($clean,count($train)+count($cal));
  // Keep identical timestamps on only one side of each split.
  $cut=$cal[0]['sold_at'];$train=array_values(array_filter($train,fn($r)=>$r['sold_at']<$cut));
  $testCut=$test[0]['sold_at'];$cal=array_values(array_filter($cal,fn($r)=>$r['sold_at']<$testCut));
  if(count($train)<30||count($cal)<8||count($test)<8)return $base+['status'=>'INSUFFICIENT_TEMPORAL_SPLIT','promotable'=>false];
  $weights=array_fill(0,5,0.0);$coverage=array_count_values(array_column($train,'variant_id'));
  for($epoch=0;$epoch<600;$epoch++){$gradient=array_fill(0,5,0.0);foreach($train as $r){$x=$this->features($r);$y=log($r['sale_minor']/$r['baseline_minor']);$prediction=$this->dot($weights,$x);foreach($x as $i=>$v)$gradient[$i]+=($prediction-$y)*$v;}foreach($weights as $i=>$w)$weights[$i]-=.025*($gradient[$i]/count($train)+($i?.01*$w:0));}
  $residual=[];foreach($cal as $r)$residual[]=abs(log($r['sale_minor']/$r['baseline_minor'])-$this->dot($weights,$this->features($r)));sort($residual);$radius=$residual[(int)floor(.9*(count($residual)-1))];
  $candidate=[];$baseline=[];$over=[];$baseOver=[];
  foreach($test as $r){$pred=$r['baseline_minor']*exp($this->dot($weights,$this->features($r)));$candidate[]=abs($pred-$r['sale_minor']);$baseline[]=abs($r['baseline_minor']-$r['sale_minor']);$over[]=max(0,($pred-$r['sale_minor'])/$r['sale_minor']);$baseOver[]=max(0,($r['baseline_minor']-$r['sale_minor'])/$r['sale_minor']);}
  sort($over);sort($baseOver);$mae=array_sum($candidate)/count($candidate);$baseMae=array_sum($baseline)/count($baseline);$p90=$over[(int)floor(.9*(count($over)-1))];$baseP90=$baseOver[(int)floor(.9*(count($baseOver)-1))];
  $pass=$mae<=$baseMae*self::CRITERIA['mae_ratio_max']&&$p90<=$baseP90*self::CRITERIA['overvaluation_p90_ratio_max'];
  return $base+['status'=>'SHADOW','promotable'=>$pass,'weights'=>$weights,'interval_log_radius'=>$radius,'variant_training_counts'=>$coverage,'metrics'=>['model_mae_minor'=>$mae,'baseline_mae_minor'=>$baseMae,'model_overvaluation_p90'=>$p90,'baseline_overvaluation_p90'=>$baseP90],'split'=>['train'=>count($train),'calibration'=>count($cal),'test'=>count($test),'train_until'=>end($train)['sold_at'],'test_from'=>$test[0]['sold_at']],'expires_at'=>gmdate('c',$now+30*86400)];
 }
 public function infer(array $model,array $input,int $now): ?array {
  if(($model['algorithm']??'')!=='log-residual-condition-storage-2'||($model['status']??'')!=='SHADOW'||strtotime($model['expires_at']??'')<$now||($model['variant_training_counts'][$input['variant_id']??'']??0)<self::CRITERIA['min_variant_train']||($input['baseline_minor']??0)<=0)return null;
  $w=$model['weights']??[];if(count($w)!==5||count(array_filter($w,fn($v)=>is_numeric($v)&&is_finite((float)$v)))!==5)return null;
  try{$x=$this->features($input);}catch(LogicException $e){return null;}
  $log=$this->dot($w,$x);if(!is_finite($log)||abs($log)>1.5)return null;
  $value=(int)round($input['baseline_minor']*exp($log));$radius=(float)($model['interval_log_radius']??0);
  if(!is_finite($radius)||$radius<0||$radius>1.5)return null;
  return ['resale_minor'=>$value,'low_minor'=>(int)round($value*exp(-$radius)),'high_minor'=>(int)round($value*exp($radius)),'interval_basis'=>'HELD_OUT_ABSOLUTE_LOG_RESIDUAL_P90','coverage_count'=>$model['variant_training_counts'][$input['variant_id']]];
 }
 public static function prepare(array $device,array $condition): ?array {
  $storage=$device['configuration']['storage']??null;
  if(!is_string($storage)||!preg_match('/^([0-9]+)\s*(GB|TB)$/iD',$storage,$match)||!in_array($condition['physical']??null,['excellent','good','fair','poor'],true))return null;
  $gb=(int)$match[1]*(strtoupper($match[2])==='TB'?1024:1);
  if($gb<1||$gb>8192)return null;
  return ['storage_gb'=>$gb,'physical'=>$condition['physical'],'feature_schema'=>'condition-storage-2'];
 }
 private function features(array $r): array {
  $f=$r['features']??[];
  if(!isset($f['storage_gb'])||!is_numeric($f['storage_gb'])||!is_finite((float)$f['storage_gb'])||$f['storage_gb']<1||$f['storage_gb']>8192||!in_array($f['physical']??null,['excellent','good','fair','poor'],true))throw new LogicException('Missing or invalid pre-valuation feature.');
  return [1.0,$f['storage_gb']/1024,$f['physical']==='excellent'?1.0:0.0,$f['physical']==='fair'?1.0:0.0,$f['physical']==='poor'?1.0:0.0];
 }
 private function dot(array $w,array $x): float {$v=0;foreach($w as $i=>$a)$v+=$a*$x[$i];return $v;}
}
