<?php
namespace Modules\Recommerce\Services\Intelligence;
use Illuminate\Support\Facades\DB;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\TradeInValuation;
final class NativeSignals {
 public function commercial(int $business): bool {
  $name=(string)DB::table('business')->where('id',$business)->value('name');
  return $name!==''&&!preg_match('/demo|fixture|synthetic|\bQA\b|testing/i',$name);
 }
 public function inventory(int $business,int $branch,array $variant): array {
  $result=['observed_at'=>gmdate('c'),'branch_id'=>$branch,'variant_id'=>$variant['variant_id'],'status'=>'UNAVAILABLE','sellable_count'=>null,'sales_30d'=>null,'commitments_count'=>null,'availability_history_complete'=>false];
  if(!$this->commercial($business))return array_replace($result,['status'=>'QA_ESTATE_EXCLUDED']);
  $devices=Device::query()->where('business_id',$business)->where('current_location_id',$branch)->where('variation_id',$variant['native_variation_id'])->where('lifecycle_state','AVAILABLE')->where('stock_participation','ON_HAND')->where('custody_kind','LOCATION')->where('transfer_state','NONE')->whereHas('openOwnershipPeriod',fn($q)=>$q->where('owner_kind','BUSINESS'));
  $sales=DB::table('recommerce_device_sale_dispositions as s')->join('recommerce_devices as d','d.id','=','s.device_id')->join('transactions as t','t.id','=','s.sale_transaction_id')->where('s.business_id',$business)->where('d.business_id',$business)->where('t.business_id',$business)->where('t.location_id',$branch)->where('d.variation_id',$variant['native_variation_id'])->where('t.type','sell')->where('t.status','final')->whereNull('s.reversed_at')->whereNotNull('s.active_sale_key')->where('s.sold_at','>=',now()->subDays(30));
  $ages=$devices->get(['acquired_at'])->map(fn($d)=>$d->acquired_at?max(0,$d->acquired_at->diffInDays(now())):null)->filter(fn($n)=>$n!==null)->all();sort($ages);
  // No authoritative stock-availability history yet: report counts, keep effect neutral.
  return array_replace($result,['status'=>'ELIGIBLE','sellable_count'=>$devices->count(),'sales_30d'=>$sales->distinct()->count('s.device_id'),'median_stock_age_days'=>$ages?$ages[(int)floor(count($ages)/2)]:null,'limitations'=>['No complete sellable-exposure history. Missing acquisition commitments are unknown.']]);
 }
 public function dataset(int $business,array $variants): array {
  if(!$this->commercial($business))return ['rows'=>[],'exclusions'=>['QA_ESTATE_EXCLUDED'=>1]];
  $rows=[];$excluded=[];
  foreach($variants as $variant){
   $sales=DB::table('recommerce_device_sale_dispositions as s')->join('recommerce_devices as d','d.id','=','s.device_id')->join('transactions as t','t.id','=','s.sale_transaction_id')->join('transaction_sell_lines as l','l.id','=','s.sell_line_id')->where('s.business_id',$business)->where('d.business_id',$business)->where('t.business_id',$business)->where('d.variation_id',$variant['native_variation_id'])->where('t.type','sell')->where('t.status','final')->whereNull('s.reversed_at')->whereNotNull('s.active_sale_key')->where('t.discount_amount',0)->where('l.quantity_returned',0)->orderBy('s.sold_at')->limit(5000)->get(['s.device_id','s.sold_at','l.unit_price','l.line_discount_amount','d.acquired_at','d.device_code']);
   foreach($sales as $sale){
    if(preg_match('/demo|fixture|synthetic|\bQA\b|test/i',$sale->device_code)||$sale->line_discount_amount!=0){$excluded[]='QA_OR_UNRESOLVED_DISCOUNT';continue;}
    $valuation=TradeInValuation::query()->where('business_id',$business)->where('device_id',$sale->device_id)->where('created_at','<',$sale->sold_at)->orderBy('created_at')->first();
    $snapshot=$valuation?(array)data_get($valuation->pricing_snapshot_json,'saver_value',[]):[];
    // Only immutable features available at valuation. Current Device specs would leak edits.
    $f=$snapshot['model_features']??null;$baseline=$snapshot['expected_resale_minor']??null;
    if(!$valuation||!is_array($f)||!$baseline){$excluded[]='MISSING_IMMUTABLE_PRE_SALE_FEATURES';continue;}
    $rows[]=['device_key'=>(string)$sale->device_id,'variant_id'=>$variant['variant_id'],'origin'=>'NATIVE_COMPLETED_SALE','target_basis'=>'NET_ITEM_EX_TAX_SHIPPING','sold_at'=>$sale->sold_at,'feature_at'=>$valuation->created_at->toIso8601String(),'valued_at'=>$valuation->created_at->toIso8601String(),'sale_minor'=>$this->saleMinor((string)$sale->unit_price),'baseline_minor'=>(int)$baseline,'features'=>$f,'fixture'=>false];
   }
  }
  return ['rows'=>$rows,'exclusions'=>array_count_values($excluded)];
 }
 private function saleMinor(string $amount): int {
  if(!preg_match('/^(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,4}))?$/D',$amount,$m))throw new \LogicException('Unsupported native sale amount.');
  $fraction=str_pad($m[2]??'',4,'0');
  return (int)$m[1]*100+(int)substr($fraction,0,2)+((int)$fraction[2]>=5?1:0);
 }

}
