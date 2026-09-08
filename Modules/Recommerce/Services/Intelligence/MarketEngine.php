<?php
namespace Modules\Recommerce\Services\Intelligence;
use LogicException;
/** No network access. The same bounded pipeline handles authorized structured imports. */
final class MarketEngine {
 public static function minor($amount): int {
  if(!is_string($amount)||!preg_match('/^(0|[1-9][0-9]{0,6})(\.[0-9]{1,2})?$/D',$amount))throw new LogicException('Use a decimal MYR item price.');
  $p=explode('.',$amount);return ((int)$p[0])*100+(int)str_pad($p[1]??'',2,'0');
 }
 public function observation(array $r,array $source,array $variant,int $now): array {
  if(($source['permission_status']??'')!=='VERIFIED'||empty($source['permission_reference'])||($source['derived_estimates_allowed']??false)!==true||empty($source['retention_days'])||strtotime($source['valid_until']??'')<$now)throw new LogicException('Source permission is unavailable or expired.');
  foreach(['listing_id','variant_id','source','upstream_source','seller_id','duplicate_group','title','url','observed_at','evidence_reference','segment','region','condition','warranty','price_type','price','availability','verification','specification','defects','lock_status'] as $f)if(!array_key_exists($f,$r))throw new LogicException('Observation is missing '.$f.'.');
  foreach($r as $k=>$v)if($k!=='specification'&&(!is_scalar($v)&&$v!==null))throw new LogicException('Invalid observation field.');
  foreach(['listing_id','variant_id','source','upstream_source','title','url','observed_at','evidence_reference','segment','region','condition','warranty','price_type','price','availability','verification','defects','lock_status'] as $f)if(!is_string($r[$f])||trim($r[$f])===''||strlen($r[$f])>2000)throw new LogicException('Invalid observation '.$f.'.');
  foreach(['seller_id','duplicate_group'] as $f)if($r[$f]!==null&&(!is_string($r[$f])||strlen($r[$f])>200))throw new LogicException('Invalid independence identifier.');
  if(!is_array($r['specification']))throw new LogicException('Invalid specification.');
  foreach($r['specification'] as $v)if(!is_string($v)||strlen($v)>200)throw new LogicException('Invalid specification value.');
  if(($r['variant_id']??'')!==($variant['variant_id']??''))throw new LogicException('Unknown canonical variant.');
  $url=parse_url($r['url']);$host=strtolower($url['host']??'');
  if(($url['scheme']??'')!=='https'||isset($url['user'])||isset($url['pass'])||isset($url['port'])||!in_array($host,$source['allowed_hosts']??[],true))throw new LogicException('Evidence URL is outside permitted source hosts.');
  if(strlen(json_encode($r))>12000)throw new LogicException('Observation is too large.');
  $observed=strtotime($r['observed_at']);if(!$observed||$observed>$now)throw new LogicException('Invalid observation time.');
  $r['observed_at']=gmdate('c',$observed);
  $reasons=[];$minor=self::minor($r['price']);
  foreach($variant['specification'] as $k=>$v)if(($r['specification'][$k]??null)!==$v)$reasons[]='SPECIFICATION_MISMATCH';
  if(($r['verification']??'')!=='EXACT_VARIANT_VERIFIED')$reasons[]='UNVERIFIED_VARIANT';
  if($r['availability']!=='IN_STOCK')$reasons[]='UNAVAILABLE';
  if($r['price_type']!=='UNCONDITIONAL_ITEM')$reasons[]='CONDITIONAL_OR_NON_ITEM_PRICE';
  if($minor<1)$reasons[]='PLACEHOLDER_PRICE';
  if($r['defects']!=='NONE_DECLARED'||$r['lock_status']!=='UNLOCKED')$reasons[]='FAULT_LOCK_OR_UNKNOWN';
  if(!in_array($r['segment'],['PRIVATE_USED','RETAIL_USED','RETAIL_REFURBISHED','RETAIL_NEW'],true))$reasons[]='UNKNOWN_SEGMENT';
  if(!in_array($r['region'],['SABAH','SARAWAK','PENINSULAR'],true))$reasons[]='UNKNOWN_REGION';
  if(preg_match('/\b(accessor(y|ies)|parts[ -]?only|deposit|instalment|installment|repair service|wanted|casing only|ansuran|alat ganti)\b/i',$r['title']))$reasons[]='NON_COMPARABLE_ITEM';
  if(($r['own_listing']??false))$reasons[]='OWN_LISTING';
  if(($r['fixture']??false))$reasons[]='FIXTURE';
  if($observed<$now-14*86400)$reasons[]='STALE';
  // Whitelist retained fields; no seller contacts or arbitrary raw response.
  $clean=array_intersect_key($r,array_flip(['listing_id','variant_id','source','upstream_source','seller_id','duplicate_group','title','url','observed_at','evidence_reference','segment','region','condition','warranty','price_type','availability','verification','specification','defects','lock_status','shipping_destination','fixture']));
  return $clean+['price_minor'=>$minor,'shipping_minor'=>isset($r['shipping'])?self::minor($r['shipping']):null,'mandatory_fees_minor'=>isset($r['mandatory_fees'])?self::minor($r['mandatory_fees']):null,'fetched_at'=>gmdate('c',$now),'retain_until'=>gmdate('c',min(strtotime($source['valid_until']),$observed+(int)$source['retention_days']*86400)),'permission_reference'=>$source['permission_reference'],'excluded'=>array_values(array_unique($reasons)),'match_grade'=>$reasons===[]?'EXACT':'REFERENCE_ONLY','extraction_version'=>'structured-1'];
 }
 public function snapshot(array $rows,array $target,int $now): array {
  $latest=[];$excluded=[];$groups=[];$sellers=[];$platforms=[];
  usort($rows,fn($a,$b)=>strtotime($b['observed_at'])<=>strtotime($a['observed_at']));
  foreach($rows as $r){$key=$r['source'].':'.$r['listing_id'].':'.$r['variant_id'];$why=$r['excluded']??[];
   foreach(['variant_id','segment','region','condition','warranty'] as $f)if(($r[$f]??null)!==($target[$f]??null))$why[]='DIFFERENT_SEGMENT';
   if(strtotime($r['observed_at'])<$now-14*86400)$why[]='STALE';
   if(strtotime($r['retain_until']??'')<$now)$why[]='PERMISSION_RETENTION_EXPIRED';
   if(isset($latest[$key]))$why[]='REPEATED_OBSERVATION';$latest[$key]=true;
   $group=$r['duplicate_group']??'';$seller=$r['seller_id']??'';
   if($group&&isset($groups[$group]))$why[]='DUPLICATE_GROUP';
   if(!$seller)$why[]='UNKNOWN_SELLER_INDEPENDENCE';
   // Conservative one-per-independent-seller cap, after segmentation.
   if($seller&&isset($sellers[$seller]))$why[]='SELLER_INFLUENCE_CAP';
   if($why){$excluded[]=['id'=>$r['id']??$key,'reasons'=>array_values(array_unique($why))];continue;}
   if($group)$groups[$group]=true;$sellers[$seller]=$r;$platforms[$r['upstream_source']]=true;
  }
  $eligible=array_values($sellers);$prices=array_column($eligible,'price_minor');sort($prices);$n=count($prices);$confidence='INSUFFICIENT';$range=null;$limitations=[];$flags=[];
  if($n>=5){$range=['low_minor'=>$this->quantile($prices,.25),'central_minor'=>$this->quantile($prices,.5),'high_minor'=>$this->quantile($prices,.75)];$confidence='MEDIUM';
   $iqr=$range['high_minor']-$range['low_minor'];foreach($eligible as $r)if($r['price_minor']<$range['low_minor']-1.5*$iqr||$r['price_minor']>$range['high_minor']+1.5*$iqr)$flags[]=$r['id']??$r['listing_id'];
   $fresh=min(array_map(fn($r)=>strtotime($r['observed_at']),$eligible))>=$now-7*86400;
   if($n>=10&&count($platforms)>=2&&$fresh&&$iqr/max(1,$range['central_minor'])<=.35&&$flags===[])$confidence='HIGH';
  }else $limitations[]='Fewer than five independent eligible listings.';
  if(count($platforms)<2)$limitations[]='Single-source evidence or no eligible source.';
  if($flags)$limitations[]='Extreme asking prices retained for reviewer inspection.';
  return ['version'=>'market-range-1','target'=>$target,'price_basis'=>'UNCONDITIONAL_ITEM_EXCLUDING_SHIPPING_FEES','range'=>$range,'confidence'=>$confidence,'listing_count'=>$n,'independent_sellers'=>$n,'sources'=>array_keys($platforms),'permission_source_ids'=>array_values(array_unique(array_column($eligible,'source'))),'included'=>array_column($eligible,'id'),'excluded'=>$excluded,'outlier_review'=>$flags,'limitations'=>$limitations,'oldest_observed_at'=>$n?min(array_column($eligible,'observed_at')):null,'newest_observed_at'=>$n?max(array_column($eligible,'observed_at')):null,'expires_at'=>$n?gmdate('c',min(array_map(fn($r)=>min(strtotime($r['observed_at'])+14*86400,strtotime($r['retain_until'])),$eligible))):gmdate('c',$now)];
 }
 public function quantile(array $sorted,float $q): int {if(!$sorted)throw new LogicException('No prices.');$i=(count($sorted)-1)*$q;$lo=(int)floor($i);return (int)round($sorted[$lo]+($sorted[(int)ceil($i)]-$sorted[$lo])*($i-$lo));}
}
