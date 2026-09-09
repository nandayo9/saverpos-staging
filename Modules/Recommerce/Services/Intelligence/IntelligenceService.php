<?php
namespace Modules\Recommerce\Services\Intelligence;
use Illuminate\Support\Facades\DB;
use LogicException;
final class IntelligenceService {
 public function __construct(public RecordStore $store,private MarketEngine $market,private ResaleModel $model,private NativeSignals $native){}
 public function variants(int $business): array {
  $latest=[];foreach($this->store->all($business,'VARIANT') as $r)if(!isset($latest[$r['target']]))$latest[$r['target']]=$r;
  return array_values(array_filter(array_map(fn($r)=>$r['data']+['record_id'=>$r['id']],$latest),fn($v)=>($v['status']??'')==='VERIFIED'));
 }
 public function variant(int $business,string $id): array {foreach($this->variants($business) as $v)if($v['variant_id']===$id)return $v;throw new LogicException('Our team needs to review this device variant.');}
 public function catalogue(int $business): array {return array_map(fn($v)=>array_intersect_key($v,array_flip(['variant_id','model_id','category','brand','model_label','specification'])),$this->variants($business));}
 public function source(int $business,string $id): array {return $this->store->latest($business,'SOURCE',$id)['data']??['permission_status'=>'NOT_VERIFIED','health'=>'DISABLED'];}
 public function import(int $business,array $input,int $actor,string $reason): array {
  $kind=$input['kind']??'';$target=$input['target']??'';$data=$input['data']??[];
  if(!is_string($kind)||!is_array($data)||!is_string($target)||!preg_match('/^[A-Za-z0-9_.:-]{1,150}$/D',$target))throw new LogicException('Invalid import target.');
  if($kind==='OBSERVATIONS'){
   $source=$this->source($business,$target);$result=[];if(count($data)>250)throw new LogicException('Import up to 250 observations per batch.');
   return DB::transaction(function()use($business,$data,$source,$target,$actor,$reason){$out=[];foreach($data as $row){if(!is_array($row))throw new LogicException('Each observation must be an object.');if(($row['source']??'')!==$target||($row['upstream_source']??'')!==($source['upstream_source']??''))throw new LogicException('Source provenance mismatch.');$v=$this->variant($business,$row['variant_id']??'');$clean=$this->market->observation($row,$source,$v,time());$out[]=$this->store->append($business,'OBSERVATION',$v['variant_id'],$clean,$clean['excluded']?'EXCLUDED':'ELIGIBLE',$actor,$reason)['id'];}return ['imported'=>count($out)];});
  }
  if($kind==='CATALOGUE_MODEL')return app(\Modules\Recommerce\Services\CanonicalDeviceCatalogue::class)->publish($business,$target,$data,$actor,$reason);
  if($kind==='VARIANT'){
   foreach(['variant_id','model_id','category','brand','model_label','specification','native_variation_id','identity_provenance','status'] as $f)if(empty($data[$f]))throw new LogicException('Variant needs '.$f.'.');
   if($data['variant_id']!==$target||!is_array($data['specification']))throw new LogicException('Variant identity mismatch.');
   $native=DB::table('variations as v')->join('products as p','p.id','=','v.product_id')->where('p.business_id',$business)->where('v.id',$data['native_variation_id'])->exists();if(!$native)throw new LogicException('Native variation is outside this business.');
   foreach(CategorySchema::fields($data['category']) as $f)if(empty($data['specification'][$f])||in_array($data['specification'][$f],['not_sure','not_applicable'],true))throw new LogicException('Exact variant needs '.$f.'.');
  }elseif($kind==='REFERENCE'){
   $this->variant($business,$target);foreach(['currency','amount_minor','condition_basis','source','approval_reference','policy_id','observed_at','expires_at','status'] as $f)if(!isset($data[$f])||$data[$f]==='')throw new LogicException('Reference needs '.$f.'.');
   if($data['currency']!=='MYR'||!is_int($data['amount_minor'])||$data['amount_minor']<=0||$data['amount_minor']>100000000)throw new LogicException('Invalid reference amount.');
   if(!is_string($data['expires_at'])||!is_string($data['observed_at'])||!strtotime($data['expires_at'])||!strtotime($data['observed_at'])||strtotime($data['observed_at'])>time()||strtotime($data['expires_at'])<=strtotime($data['observed_at']))throw new LogicException('Reference dates are invalid.');
  }elseif($kind==='SOURCE'){
   foreach(['upstream_source','permission_status','permission_reference','derived_estimates_allowed','retention_days','valid_until','allowed_hosts','owner','access_method'] as $f)if(!isset($data[$f]))throw new LogicException('Source needs '.$f.'.');
   if(!in_array($data['permission_status'],['VERIFIED','REVOKED','NOT_VERIFIED'],true)||!is_array($data['allowed_hosts'])||!is_int($data['retention_days'])||$data['retention_days']<1||$data['retention_days']>365||!strtotime($data['valid_until']))throw new LogicException('Invalid source permission.');
   $data['health']=$data['permission_status']==='VERIFIED'?'AWAITING_IMPORT':'DISABLED';
  }elseif($kind==='POLICY'){
   PricingPolicyValidator::validate($data);
   foreach(['version','status','approval_reference','categories','commercial','reference_order','condition_basis','market_target','market_min_confidence','demand'] as $f)if(!isset($data[$f]))throw new LogicException('Policy needs '.$f.'.');
   if(!is_array($data['commercial'])||!is_array($data['categories'])||!is_array($data['reference_order'])||array_diff($data['reference_order'],['APPROVED','MARKET','MODEL']))throw new LogicException('Invalid pricing policy.');
   foreach(array_keys((array)config('recommerce.saver_value.policy')) as $f)if(!array_key_exists($f,$data['commercial']))throw new LogicException('Commercial policy is missing '.$f.'.');
   foreach(['minimum_margin_minor','warranty_reserve_minor','logistics_handling_minor','inventory_risk_minor','minimum_offer_minor','charger_missing_minor'] as $f)if(!is_int($data['commercial'][$f])||$data['commercial'][$f]<0)throw new LogicException('Invalid policy cost.');
   foreach(['target_margin_percent','maximum_acquisition_ratio'] as $f)if(!is_numeric($data['commercial'][$f])||$data['commercial'][$f]<0||$data['commercial'][$f]>1)throw new LogicException('Invalid policy ratio.');
  }else throw new LogicException('Unsupported import kind.');
  if($kind==='VARIANT')return DB::transaction(function()use($business,$kind,$target,$data,$actor,$reason){
   // Serialize identity imports within the native business; unique indexes also fail closed.
   DB::table('products')->where('business_id',$business)->orderBy('id')->lockForUpdate()->first();
   app(\Modules\Recommerce\Services\CanonicalDeviceCatalogue::class)->registerVariant($business,$data,$actor,$reason);
   return $this->store->append($business,$kind,$target,$data,$data['status'],$actor,$reason);
  });
  return $this->store->append($business,$kind,$target,$data,$data['status']??$data['permission_status']??'RECORDED',$actor,$reason);
 }
 public function refresh(int $business,string $variantId,int $actor): array {
  $v=$this->variant($business,$variantId);$ref=$this->store->latest($business,'REFERENCE',$variantId);$policy=$this->store->latest($business,'POLICY',$ref['data']['policy_id']??'');
  $target=($policy['data']['market_target']??[])+['variant_id'=>$variantId];
  $rows=[];foreach($this->store->all($business,'OBSERVATION',$variantId,5000) as $r){$source=$this->source($business,$r['data']['source']);$data=$r['data'];if(($source['permission_status']??'')!=='VERIFIED'||($source['derived_estimates_allowed']??false)!==true||($source['permission_reference']??null)!==($data['permission_reference']??null)||strtotime($source['valid_until']??'')<time())$data['excluded'][]='PERMISSION_UNAVAILABLE';$rows[]=$data+['id'=>$r['id']];}
  $snapshot=$this->market->snapshot($rows,$target,time());$previous=$this->store->latest($business,'MARKET',$variantId);
  $snapshot['previous_snapshot_id']=$previous['id']??null;$snapshot['central_change_minor']=isset($snapshot['range']['central_minor'],$previous['data']['range']['central_minor'])?$snapshot['range']['central_minor']-$previous['data']['range']['central_minor']:null;
  return $this->store->append($business,'MARKET',$variantId,$snapshot,$snapshot['range']?'READY':'INSUFFICIENT',$actor,'Reprocessed permitted evidence; observation dates preserved.');
 }
 public function train(int $business,int $actor): array {
  $dataset=$this->native->dataset($business,$this->variants($business));$record=$this->store->append($business,'DATASET','resale',$dataset,'EXTRACTED',$actor,'Native completed-sale extraction; QA and unsupported targets excluded.');
  $model=$this->model->train($dataset['rows'],time());$model['dataset_id']=$record['id'];$model['extraction_exclusions']=$dataset['exclusions'];
  return $this->store->append($business,'MODEL','resale',$model,$model['status'],$actor,'Reproducible chronological train/calibration/test evaluation.');
 }
 public function transition(int $business,string $modelId,string $action,int $actor,string $reason): array {
  if(!in_array($action,['PROMOTE','ROLLBACK'],true))throw new LogicException('Unsupported model action.');
  if($action==='PROMOTE'){$found=null;foreach($this->store->all($business,'MODEL','resale') as $r)if($r['id']===$modelId)$found=$r;
   if(!$found||($this->store->latest($business,'MODEL','resale')['id']??null)!==$modelId||!($found['data']['promotable']??false)||strtotime($found['data']['expires_at']??'')<time())throw new LogicException('Candidate has not met promotion criteria.');}
  return $this->store->append($business,'MODEL_EVENT','resale',['model_id'=>$action==='PROMOTE'?$modelId:null,'action'=>$action],$action,$actor,$reason);
 }
 public function selection(int $business,array $device,array $condition): array {
  $v=$this->variant($business,$device['configuration']['variant_id']??'');if(!CategorySchema::exact($v,$device))throw new LogicException('Our team needs to review this device variant.');
  if(!in_array((int)$v['native_variation_id'],array_map('intval',(array)config('recommerce.tradein_acquisition_command.variation_ids',[])),true))throw new LogicException('Our team needs to review this device.');
  $ref=$this->store->latest($business,'REFERENCE',$v['variant_id']);$r=$ref['data']??[];
  $policy=$this->store->latest($business,'POLICY',$r['policy_id']??'');$p=$policy['data']??[];
  PricingPolicyValidator::validate($p);
  if(($p['status']??'')!=='APPROVED'||!in_array($v['category'],$p['categories']??[],true))throw new LogicException('Our team needs to review this device.');
  $amount=null;$chosen=null;$market=$this->store->latest($business,'MARKET',$v['variant_id']);$m=$market['data']??[];
  $baseline=($r['status']??'')==='APPROVED'&&strtotime($r['expires_at']??'')>=time()&&($r['condition_basis']??null)===($p['condition_basis']??null)?($r['amount_minor']??null):null;
  $model=$this->store->latest($business,'MODEL','resale');$shadow=null;$features=ResaleModel::prepare($device,$condition);
  if($baseline&&is_array($features)&&$model) $shadow=$this->model->infer($model['data'],['variant_id'=>$v['variant_id'],'baseline_minor'=>$baseline,'features'=>$features],time());
  foreach($p['reference_order'] as $source){
   if($source==='APPROVED'&&$baseline){$amount=$baseline;$chosen='APPROVED';}
   if($source==='MARKET'&&config('recommerce.intelligence.market_enabled',false)&&($m['range']??null)&&!($m['outlier_review']??[])&&in_array($m['confidence']??'',['MEDIUM','HIGH'],true)&&strtotime($m['expires_at']??'')>=time()&&($m['target']['condition']??null)===($p['condition_basis']??null)){
    $valid=!empty($m['permission_source_ids']);foreach(['segment','region','condition','warranty'] as $f)if(($m['target'][$f]??null)!==($p['market_target'][$f]??null))$valid=false;foreach(($m['permission_source_ids']??[]) as $s){$src=$this->source($business,$s);if(($src['permission_status']??'')!=='VERIFIED'||($src['derived_estimates_allowed']??false)!==true||strtotime($src['valid_until']??'')<time())$valid=false;}
    if($valid&&(($p['market_min_confidence']??'HIGH')!=='HIGH'||$m['confidence']==='HIGH')){$amount=$m['range']['central_minor'];$chosen='MARKET';}
   }
   $event=$this->store->latest($business,'MODEL_EVENT','resale');
   if($source==='MODEL'&&config('recommerce.intelligence.model_enabled',false)&&$shadow&&($event['data']['model_id']??null)===($model['id']??null)&&($model['data']['promotable']??false)){$amount=$shadow['resale_minor'];$chosen='MODEL';}
   if($amount)break;
  }
  if(!$amount)throw new LogicException('Our team needs to review this device.');
  $validUntil=$chosen==='MARKET'?strtotime($m['expires_at']):($chosen==='MODEL'?min(strtotime($model['data']['expires_at']),strtotime($r['expires_at'])):strtotime($r['expires_at']));
  $commercial=$p['commercial'];$commercial['automatic_categories']=$p['categories'];
  return ['variant'=>$v,'policy'=>$commercial,'reference'=>['provider'=>$chosen,'expected_resale_minor'=>$amount,'confidence_score'=>72,'match_type'=>'EXACT_VARIANT','evidence'=>[['source_type'=>$chosen,'source_id'=>$chosen==='MARKET'?($market['id']??''):($chosen==='MODEL'?$model['id']:$ref['id'])]],'assumptions'=>[]], 'trace'=>['variant_record_id'=>$v['record_id'],'reference_record_id'=>$ref['id'],'market_snapshot_id'=>$market['id']??null,'policy_record_id'=>$policy['id'],'model_version'=>$model['id']??null,'model_shadow'=>$shadow,'model_features'=>$features,'selected'=>$chosen,'baseline_resale_minor'=>$baseline,'condition_basis'=>$p['condition_basis']],'valid_until'=>gmdate('c',$validUntil),'demand_policy'=>$p['demand']??[]];
 }
 public function adjustment(int $business,array $selection,int $base): array {
  $signal=$this->store->latest($business,'SIGNAL',$selection['variant']['variant_id'].':'.(int)config('recommerce.cohort.location_id'));
  return (new DemandAdjustment)->calculate($base,$signal['data']??null,config('recommerce.intelligence.demand_enabled',false)?$selection['demand_policy']:null,time());
 }
 public function signals(int $business,string $variant,int $branch,int $actor): array {
  $data=$this->native->inventory($business,$branch,$this->variant($business,$variant));return $this->store->append($business,'SIGNAL',$variant.':'.$branch,$data,$data['status'],$actor,'Read-only authoritative native stock/sale projection.');
 }
 public function purgeExpiredEvidence(int $business): int {
  $removed=0;
  // Retention deletion is the sole exception to append-only evidence history.
  // Do not retain a raw title, URL, seller identifier or price beyond permission.
  foreach($this->store->all($business,'OBSERVATION',null,5000) as $r){
   $expiry=strtotime($r['data']['retain_until']??'');
   if(!$expiry||$expiry<time())$removed+=DB::table(RecordStore::TABLE)->where('business_id',$business)->where('record_uuid',$r['id'])->where('kind','OBSERVATION')->delete();
  }
  return $removed;
 }

}
