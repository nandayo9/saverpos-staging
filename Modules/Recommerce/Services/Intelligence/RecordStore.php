<?php
namespace Modules\Recommerce\Services\Intelligence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
/** Append-only valuation evidence, never an inventory/accounting ledger. */
final class RecordStore {
 const TABLE='recommerce_trade_in_intelligence';
 public function all(int $business,string $kind,?string $target=null,int $limit=1000): array {
  if(!Schema::hasTable(self::TABLE)) return [];
  $q=DB::table(self::TABLE)->where('business_id',$business)->where('kind',$kind);
  if($target!==null)$q->where('target',$target);
  return $q->orderByDesc('id')->limit(min(5000,$limit))->get()->map(function($r){$p=json_decode($r->payload_json,true,64,JSON_THROW_ON_ERROR); return ['id'=>$r->record_uuid,'target'=>$r->target,'status'=>$r->status,'created_at'=>$r->created_at,'reason'=>$r->reason,'actor_id'=>$r->actor_id,'data'=>$p];})->all();
 }
 public function latest(int $business,string $kind,string $target): ?array {return $this->all($business,$kind,$target,1)[0]??null;}
 public function append(int $business,string $kind,string $target,array $data,string $status,?int $actor,string $reason): array {
  if($business<1||!preg_match('/^[A-Z_]{2,24}$/',$kind)||$target===''||strlen($target)>150||!preg_match('/^[A-Z_]{2,32}$/D',$status)||trim($reason)===''||strlen($reason)>500)throw new LogicException('Invalid evidence record.');
  $json=json_encode($data,JSON_THROW_ON_ERROR);if(strlen($json)>2000000)throw new LogicException('Evidence record is too large.');
  $uuid=(string)Str::uuid();DB::table(self::TABLE)->insert(['record_uuid'=>$uuid,'business_id'=>$business,'kind'=>$kind,'target'=>$target,'status'=>$status,'payload_json'=>$json,'actor_id'=>$actor,'reason'=>$reason,'created_at'=>now()]);
  // Return this insert, not a concurrently inserted record for the same target.
  return ['id'=>$uuid,'target'=>$target,'status'=>$status,'created_at'=>now()->toDateTimeString(),'reason'=>$reason,'actor_id'=>$actor,'data'=>$data];
 }
}
