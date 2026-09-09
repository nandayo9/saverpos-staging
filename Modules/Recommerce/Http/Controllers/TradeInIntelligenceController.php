<?php
namespace Modules\Recommerce\Http\Controllers;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Recommerce\Services\Intelligence\IntelligenceService;
use Modules\Recommerce\Services\Intelligence\IntelligenceJobs;
use Modules\Recommerce\Services\TradeInService;
use Modules\Recommerce\Support\AuthorizationGate;
final class TradeInIntelligenceController {
 public function __construct(private IntelligenceService $service,private AuthorizationGate $authorization){}
 private function scope(bool $write=false): array {
  $u=auth()->user();$business=(int)$u->business_id;$branch=(int)config('recommerce.cohort.location_id');
  abort_unless(app()->environment('staging')&&User::can_access_this_location($branch,$business)&&($write?$this->authorization->allowsWriteLocation($u,TradeInService::PERMISSION_APPROVE,$business,$branch):$this->authorization->allowsRead($u,TradeInService::PERMISSION_VIEW,$business,$branch)),404);
  return [$business,$branch,(int)$u->id];
 }
 public function index() {
  [$business,$branch]=$this->scope();$records=[];foreach(['VARIANT','CATALOGUE_MODEL','REFERENCE','SOURCE','POLICY','MARKET','SIGNAL','MODEL','MODEL_EVENT','JOB','ESTIMATE'] as $kind)$records[$kind]=$this->service->store->all($business,$kind,null,30);
  $release=[];$file=storage_path('app/tradein-release.json');if(is_file($file)){$release=json_decode(file_get_contents($file),true)?:[];$release['runtime_matches']=true;foreach($release['files']??[] as $path=>$hash)if(!is_file(base_path($path))||!hash_equals($hash,hash_file('sha256',base_path($path))))$release['runtime_matches']=false;}
  $native=DB::table('variations as v')->join('products as p','p.id','=','v.product_id')->where('p.business_id',$business)->where(function($q){$q->where('p.name','like','%S22%')->orWhere('p.name','like','%iPad%');})->limit(30)->get(['v.id','v.name','v.sub_sku','p.name as product']);
  $canManage=$this->authorization->allowsWriteLocation(auth()->user(),TradeInService::PERMISSION_APPROVE,$business,$branch);
  return response()->view('recommerce::tradein.intelligence',compact('records','release','native','branch','canManage'))->header('Cache-Control','no-store')->header('X-Robots-Tag','noindex, nofollow');
 }
 public function store(Request $request,IntelligenceJobs $jobs) {
  [$business,$branch,$actor]=$this->scope(true);
  $input=$request->validate(['action'=>'required|in:IMPORT,REFRESH,SIGNALS,TRAIN,PROMOTE,ROLLBACK','reason'=>'required|string|max:500','target'=>'nullable|string|max:150','model_id'=>'nullable|uuid','document'=>'nullable|file|max:1024','payload'=>'nullable|string|max:1000000']);
  try {
   if($input['action']==='IMPORT'){$raw=$request->hasFile('document')?file_get_contents($request->file('document')->getRealPath()):($input['payload']??'');$payload=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($payload))throw new LogicException('Upload a structured JSON document.');if(($payload['kind']??'')==='VARIANT')abort_unless($this->authorization->allowsWrite(auth()->user(),TradeInService::PERMISSION_APPROVE,$business,$branch,$payload['data']['native_variation_id']??0),403);if(($payload['kind']??'')==='CATALOGUE_MODEL'){
    $ids=DB::table(\Modules\Recommerce\Services\CanonicalDeviceCatalogue::MAPPINGS)->where('business_id',$business)->where('model_id',$payload['target']??'')->where('source','VARIANT')->pluck('native_variation_id');
    abort_if($ids->isEmpty(),422);
    foreach($ids as $id)abort_unless($this->authorization->allowsWrite(auth()->user(),TradeInService::PERMISSION_APPROVE,$business,$branch,(int)$id),403);
   }$this->service->import($business,$payload,$actor,$input['reason']);}
   elseif(in_array($input['action'],['PROMOTE','ROLLBACK'],true))$this->service->transition($business,$input['model_id']??'',$input['action'],$actor,$input['reason']);
   else $jobs->request($business,$input['action'],$input['target']??'resale',$branch,$actor,$input['reason']);
   return back()->with('status',['success'=>true,'msg'=>'Recorded. Scheduled work runs separately from customer requests.']);
  }catch(\JsonException $e){return back()->with('status',['success'=>false,'msg'=>'The document is not valid JSON.']);}
  catch(LogicException $e){return back()->with('status',['success'=>false,'msg'=>$e->getMessage()]);}
 }
}
