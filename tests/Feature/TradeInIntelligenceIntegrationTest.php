<?php
namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Recommerce\Services\Intelligence\IntelligenceService;
use Modules\Recommerce\Services\Intelligence\RecordStore;
use Modules\Recommerce\Services\SaverValueService;
use Tests\TestCase;

class TradeInIntelligenceIntegrationTest extends TestCase
{
    private IntelligenceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:',
            'recommerce.tradein_acquisition_command.business_id'=>7,'recommerce.tradein_acquisition_command.variation_ids'=>[303],'recommerce.cohort.location_id'=>101]);
        DB::purge('sqlite');
        Schema::create('products',function(Blueprint $t){$t->unsignedInteger('id')->primary();$t->unsignedInteger('business_id');});
        Schema::create('variations',function(Blueprint $t){$t->unsignedInteger('id')->primary();$t->unsignedInteger('product_id');});
        DB::table('products')->insert(['id'=>202,'business_id'=>7]);
        DB::table('variations')->insert(['id'=>303,'product_id'=>202]);
        (require base_path('Modules/Recommerce/Database/Migrations/2026_09_08_000001_create_trade_in_intelligence_records.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_09_08_000003_create_canonical_device_catalogue.php'))->up();
        $this->service=app(IntelligenceService::class);
    }

    private function policy(): array
    {
        $commercial=config('recommerce.saver_value.policy');
        $commercial['version']='TEST-ONLY';$commercial['status']='APPROVED';
        return ['version'=>'TEST-ONLY','status'=>'APPROVED','approval_reference'=>'ISOLATED-TEST-ONLY',
            'categories'=>['PHONE','TABLET'],'commercial'=>$commercial,'reference_order'=>['APPROVED'],
            'condition_basis'=>'excellent','market_target'=>['condition'=>'excellent','segment'=>'PRIVATE_USED','region'=>'SABAH','warranty'=>'NONE'],
            'market_min_confidence'=>'MEDIUM','demand'=>[]];
    }

    private function seedEvidence(string $category='PHONE'): void
    {
        $spec=$category==='TABLET'?['storage'=>'128GB','connectivity'=>'wifi']:['storage'=>'128GB'];
        $this->service->import(7,['kind'=>'VARIANT','target'=>'TEST-VARIANT','data'=>[
            'variant_id'=>'TEST-VARIANT','model_id'=>'TEST-MODEL','category'=>$category,'brand'=>'Test',
            'model_label'=>'Isolated pricing fixture','specification'=>$spec,'native_variation_id'=>303,
            'identity_provenance'=>'ISOLATED-TEST-ONLY','status'=>'VERIFIED']],900,'Isolated integration test.');
        $this->service->import(7,['kind'=>'POLICY','target'=>'TEST-POLICY','data'=>$this->policy()],900,'Isolated test policy, never commercial approval.');
        $this->service->import(7,['kind'=>'REFERENCE','target'=>'TEST-VARIANT','data'=>[
            'currency'=>'MYR','amount_minor'=>150000,'condition_basis'=>'excellent','source'=>'ISOLATED_TEST',
            'approval_reference'=>'ISOLATED-TEST-ONLY','policy_id'=>'TEST-POLICY','observed_at'=>gmdate('c',time()-3600),
            'expires_at'=>gmdate('c',time()+3600),'status'=>'APPROVED']],900,'Isolated reference fixture.');
    }

    private function input(string $category='PHONE'): array
    {
        return ['category'=>$category,'model_id'=>'TEST-MODEL','model_label'=>'Isolated pricing fixture',
            'configuration'=>['variant_id'=>'TEST-VARIANT','storage'=>'128GB','connectivity'=>'wifi','charger'=>'yes'],
            'condition'=>['power'=>'yes','screen'=>'perfect','physical'=>'excellent','battery'=>'good',
                'charging'=>'working','camera'=>'working','wifi'=>'working','repair_history'=>'no','liquid_damage'=>'no',
                'buttons'=>'working','body'=>'working','activation_lock'=>'no','biometrics'=>'working','network'=>'working']];
    }

    public static function supportedCategories(): array { return [['PHONE'], ['TABLET']]; }

    /** @dataProvider supportedCategories */
    public function test_phone_and_tablet_quotes_use_shared_policy_and_immutable_snapshot(string $category): void
    {
            $this->seedEvidence($category);$r=(new SaverValueService)->indicative($this->input($category));
            self::assertSame('AUTOMATIC_QUOTE',$r['decision']);
            self::assertSame('APPROVED',$r['pricing_trace']['selected']);
            self::assertSame('TEST-ONLY',$r['pricing_policy_version']);
            self::assertLessThanOrEqual(time()+3600,strtotime($r['valid_until']));
            self::assertNotEmpty($r['pricing_trace']['snapshot_id']);
            self::assertSame('NO_APPROVED_POLICY',$r['pricing_trace']['demand']['reason']);
        self::assertCount(1,$this->service->store->all(7,'ESTIMATE'));
        self::assertFalse(Schema::hasTable('purchase_lines'));
        self::assertFalse(Schema::hasTable('recommerce_devices'));
    }

    public function test_wrong_variant_never_uses_nearby_reference(): void
    {
        $this->seedEvidence();$input=$this->input();$input['configuration']['storage']='256GB';
        $this->expectException(LogicException::class);
        (new SaverValueService)->indicative($input);
    }

    public function test_missing_reference_returns_review_without_zero_offer(): void
    {
        $this->expectException(LogicException::class);
        (new SaverValueService)->indicative($this->input());
    }

    public function test_unknown_functional_condition_requires_inspection(): void
    {
        $this->seedEvidence();$input=$this->input();$input['condition']['activation_lock']='not_sure';
        self::assertSame('MANUAL_REVIEW_REQUIRED',(new SaverValueService)->indicative($input)['decision']);
    }

    public function test_records_are_tenant_scoped(): void
    {
        $this->seedEvidence();self::assertSame([],$this->service->catalogue(8));
        self::assertSame([],$this->service->store->all(8,'REFERENCE'));
        $this->expectException(LogicException::class);
        $this->service->variant(8,'TEST-VARIANT');
    }

    public function test_duplicate_condition_deduction_is_rejected(): void
    {
        $p=$this->policy();$p['condition_basis']='good';$p['market_target']['condition']='good';
        $this->expectException(LogicException::class);
        $this->service->import(7,['kind'=>'POLICY','target'=>'TEST','data'=>$p],900,'Test duplicate condition rejection.');
    }

    public function test_reference_refresh_cannot_rewrite_existing_estimate(): void
    {
        $this->seedEvidence();$r=(new SaverValueService)->indicative($this->input());
        $old=$this->service->store->latest(7,'ESTIMATE','TEST-VARIANT');
        $reference=$this->service->store->latest(7,'REFERENCE','TEST-VARIANT')['data'];$reference['amount_minor']=190000;
        $this->service->import(7,['kind'=>'REFERENCE','target'=>'TEST-VARIANT','data'=>$reference],900,'New isolated reference.');
        self::assertSame($old,$this->service->store->latest(7,'ESTIMATE','TEST-VARIANT'));
        self::assertSame(150000,$old['data']['expected_resale_minor']);
    }

    public function test_migration_down_and_restore_preserves_record_contents(): void
    {
        $this->seedEvidence();$before=DB::table(RecordStore::TABLE)->get()->map(fn($r)=>(array)$r)->all();
        $migration=require base_path('Modules/Recommerce/Database/Migrations/2026_09_08_000001_create_trade_in_intelligence_records.php');
        $migration->down();self::assertFalse(Schema::hasTable(RecordStore::TABLE));
        $migration->up();DB::table(RecordStore::TABLE)->insert($before);
        self::assertSame($before,DB::table(RecordStore::TABLE)->get()->map(fn($r)=>(array)$r)->all());
    }
    public function test_market_snapshot_respects_disable_revocation_and_baseline_fallback(): void
    {
        $this->seedEvidence();$policy=$this->policy();$policy['reference_order']=['MARKET','APPROVED'];
        $this->service->import(7,['kind'=>'POLICY','target'=>'TEST-POLICY','data'=>$policy],900,'Test explicit market-first selection.');
        $source=['upstream_source'=>'TEST_PLATFORM','permission_status'=>'VERIFIED','permission_reference'=>'TEST-LICENCE',
            'derived_estimates_allowed'=>true,'retention_days'=>30,'valid_until'=>gmdate('c',time()+86400),
            'allowed_hosts'=>['evidence.example'],'owner'=>'Isolated test','access_method'=>'STRUCTURED_IMPORT'];
        $this->service->import(7,['kind'=>'SOURCE','target'=>'test-feed','data'=>$source],900,'Isolated test permission, not actual source access.');
        $rows=[];foreach(range(1,5) as $n)$rows[]=['listing_id'=>'TEST-'.$n,'variant_id'=>'TEST-VARIANT','source'=>'test-feed',
            'upstream_source'=>'TEST_PLATFORM','seller_id'=>'TEST-SELLER-'.$n,'duplicate_group'=>'TEST-GROUP-'.$n,
            'title'=>'Isolated test phone 128GB','url'=>'https://evidence.example/'.$n,'observed_at'=>gmdate('c',time()-100),
            'evidence_reference'=>'TEST-ONLY','segment'=>'PRIVATE_USED','region'=>'SABAH','condition'=>'excellent','warranty'=>'NONE',
            'price_type'=>'UNCONDITIONAL_ITEM','price'=>'1700.00','availability'=>'IN_STOCK','verification'=>'EXACT_VARIANT_VERIFIED',
            'specification'=>['storage'=>'128GB'],'defects'=>'NONE_DECLARED','lock_status'=>'UNLOCKED'];
        $this->service->import(7,['kind'=>'OBSERVATIONS','target'=>'test-feed','data'=>$rows],900,'Isolated test observations.');
        $snapshot=$this->service->refresh(7,'TEST-VARIANT',900);
        self::assertSame('READY',$snapshot['status']);
        config(['recommerce.intelligence.market_enabled'=>false]);
        self::assertSame(150000,(new SaverValueService)->indicative($this->input())['expected_resale_minor']);
        config(['recommerce.intelligence.market_enabled'=>true]);
        $market=(new SaverValueService)->indicative($this->input());
        self::assertSame(170000,$market['expected_resale_minor']);
        self::assertSame($snapshot['id'],$market['pricing_trace']['market_snapshot_id']);
        $source['permission_status']='REVOKED';
        $this->service->import(7,['kind'=>'SOURCE','target'=>'test-feed','data'=>$source],900,'Test revoked licence.');
        self::assertSame(150000,(new SaverValueService)->indicative($this->input())['expected_resale_minor']);
    }

    public function test_expired_evidence_is_deleted_by_retention_job(): void
    {
        $record=$this->service->store->append(7,'OBSERVATION','TEST',[
            'retain_until'=>gmdate('c',time()-1),'title'=>'PRIVATE TEST EVIDENCE'],'ELIGIBLE',900,'Test retention expiry.');
        self::assertSame(1,$this->service->purgeExpiredEvidence(7));
        self::assertSame(0,DB::table(RecordStore::TABLE)->where('record_uuid',$record['id'])->count());
    }

    public function test_model_promotion_and_rollback_are_audited_and_do_not_write_prices(): void
    {
        $model=$this->service->store->append(7,'MODEL','resale',['promotable'=>true,'expires_at'=>gmdate('c',time()+3600)],'SHADOW',900,'Isolated promotion mechanics fixture.');
        $promoted=$this->service->transition(7,$model['id'],'PROMOTE',900,'Test eligible candidate promotion.');
        self::assertSame($model['id'],$promoted['data']['model_id']);
        $rolledBack=$this->service->transition(7,'','ROLLBACK',900,'Test immediate fallback.');
        self::assertNull($rolledBack['data']['model_id']);
        self::assertSame([],$this->service->store->all(7,'ESTIMATE'));
        self::assertFalse(Schema::hasTable('purchase_lines'));
    }

    public function test_jobs_coalesce_and_stop_after_three_failed_attempts(): void
    {
        $this->app['env']='staging';config(['recommerce.intelligence.jobs_enabled'=>true]);
        $jobs=app(\Modules\Recommerce\Services\Intelligence\IntelligenceJobs::class);
        $one=$jobs->request(7,'REFRESH','MISSING-VARIANT',101,900,'Test bounded failure.');
        $two=$jobs->request(7,'REFRESH','MISSING-VARIANT',101,900,'Test coalescing.');
        self::assertSame($one['id'],$two['id']);
        foreach(range(1,3) as $attempt)self::assertSame(1,$jobs->run(7)['failed']);
        self::assertSame('FAILED',$this->service->store->latest(7,'JOB','REFRESH:MISSING-VARIANT:101')['status']);
        self::assertSame(0,$jobs->run(7)['failed']);
    }

}
