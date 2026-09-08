<?php
namespace Modules\Recommerce\Services\Intelligence;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use LogicException;
use Throwable;
/** Bounded scheduled work, never invoked by a customer estimate request. */
final class IntelligenceJobs {
 public function __construct(private IntelligenceService $service){}
 public function request(int $business,string $action,string $target,int $branch,int $actor,string $reason): array {
  if(!in_array($action,['REFRESH','SIGNALS','TRAIN'],true))throw new LogicException('Unsupported research job.');
  $key=$action.':'.$target.':'.$branch;$prior=$this->service->store->latest($business,'JOB',$key);
  if($prior&&in_array($prior['status'],['PENDING','RUNNING','RETRY'],true)&&strtotime($prior['created_at'])>time()-900)return $prior;
  return $this->service->store->append($business,'JOB',$key,['job_id'=>(string)Str::uuid(),'action'=>$action,'target'=>$target,'branch'=>$branch,'attempt'=>0],'PENDING',$actor,$reason);
 }
 public function run(int $business,int $limit=3): array {
  if(!app()->environment('staging')||!config('recommerce.intelligence.jobs_enabled',false))return ['status'=>'DISABLED'];
  $lock=Cache::lock('tradein-intelligence:'.$business,900);if(!$lock->get())return ['status'=>'BUSY'];
  $done=0;$failed=0;
  try {$this->service->purgeExpiredEvidence($business);$latest=[];foreach($this->service->store->all($business,'JOB') as $r)if(!isset($latest[$r['target']]))$latest[$r['target']]=$r;
   foreach($latest as $job){if($done+$failed>=max(1,min(10,$limit)))break;
    if($job['status']==='RUNNING'&&strtotime($job['created_at'])>time()-300)continue;
    if(!in_array($job['status'],['PENDING','RUNNING','RETRY'],true))continue;
    $d=$job['data'];if(($d['attempt']??0)>=3){$this->service->store->append($business,'JOB',$job['target'],$d,'FAILED',$job['actor_id'],'Retry limit reached after interrupted attempt.');continue;}$d['attempt']=($d['attempt']??0)+1;
    $this->service->store->append($business,'JOB',$job['target'],$d,'RUNNING',$job['actor_id'],'Bounded scheduled attempt.');
    try {$result=match($d['action']){'REFRESH'=>$this->service->refresh($business,$d['target'],(int)$job['actor_id']),'SIGNALS'=>$this->service->signals($business,$d['target'],(int)$d['branch'],(int)$job['actor_id']),'TRAIN'=>$this->service->train($business,(int)$job['actor_id'])};$d['result_id']=$result['id'];$state='COMPLETE';$done++;}
    catch(Throwable $e){$d['failure_class']=$e instanceof LogicException?'VALIDATION':'SOURCE_FAILURE';$state=$d['attempt']>=3?'FAILED':'RETRY';$failed++;}
    $this->service->store->append($business,'JOB',$job['target'],$d,$state,$job['actor_id'],'Scheduled job outcome; raw error omitted.');
   }
  }finally{$lock->release();}
  return ['completed'=>$done,'failed'=>$failed];
 }
}
