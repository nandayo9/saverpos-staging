<?php

namespace Tests\Unit;

use LogicException;
use Modules\Recommerce\Services\Intelligence\DemandAdjustment;
use Modules\Recommerce\Services\Intelligence\MarketEngine;
use Modules\Recommerce\Services\Intelligence\ResaleModel;
use PHPUnit\Framework\TestCase;

/** Isolated fixtures test mechanics only; no market observations or production accuracy. */
class TradeInIntelligenceEngineTest extends TestCase
{
    private const NOW = 1788825600;

    private function source(): array
    {
        return ['permission_status'=>'VERIFIED','permission_reference'=>'TEST-ONLY-LICENCE',
            'derived_estimates_allowed'=>true,'retention_days'=>30,'valid_until'=>gmdate('c',self::NOW+86400*30),
            'allowed_hosts'=>['evidence.example'],'upstream_source'=>'TEST_PLATFORM'];
    }

    private function variant(): array
    {
        return ['variant_id'=>'TEST-PHONE-128','specification'=>['storage'=>'128GB']];
    }

    private function row(int $n=1): array
    {
        return ['listing_id'=>'listing-'.$n,'variant_id'=>'TEST-PHONE-128','source'=>'licensed-feed',
            'upstream_source'=>'TEST_PLATFORM','seller_id'=>'seller-'.$n,'duplicate_group'=>'group-'.$n,
            'title'=>'Test phone 128GB','url'=>'https://evidence.example/listing/'.$n,
            'observed_at'=>gmdate('c',self::NOW-3600),'evidence_reference'=>'TEST-ONLY-'.$n,
            'segment'=>'PRIVATE_USED','region'=>'SABAH','condition'=>'good','warranty'=>'NONE',
            'price_type'=>'UNCONDITIONAL_ITEM','price'=>(string)(900+20*$n),'availability'=>'IN_STOCK',
            'verification'=>'EXACT_VARIANT_VERIFIED','specification'=>['storage'=>'128GB'],
            'defects'=>'NONE_DECLARED','lock_status'=>'UNLOCKED'];
    }

    private function observations(int $n=5): array
    {
        $engine=new MarketEngine;
        return array_map(fn($i)=>$engine->observation($this->row($i),$this->source(),$this->variant(),self::NOW)+['id'=>'observation-'.$i],range(1,$n));
    }

    private function target(): array
    {
        return ['variant_id'=>'TEST-PHONE-128','segment'=>'PRIVATE_USED','region'=>'SABAH','condition'=>'good','warranty'=>'NONE'];
    }

    public function test_decimal_money_preserves_sen_and_rejects_float(): void
    {
        self::assertSame(105001,MarketEngine::minor('1050.01'));
        $this->expectException(LogicException::class);
        MarketEngine::minor(1050.01);
    }

    public function test_permission_is_not_inferred_from_public_visibility(): void
    {
        $this->expectException(LogicException::class);
        (new MarketEngine)->observation($this->row(),array_replace($this->source(),['derived_estimates_allowed'=>'false']),$this->variant(),self::NOW);
    }

    public function test_unsafe_url_is_rejected_without_fetching(): void
    {
        $this->expectException(LogicException::class);
        (new MarketEngine)->observation(array_replace($this->row(),['url'=>'https://evidence.example@127.0.0.1/private']),$this->source(),$this->variant(),self::NOW);
    }

    public function test_unknown_shipping_and_fees_remain_unknown(): void
    {
        $observation=$this->observations(1)[0];
        self::assertNull($observation['shipping_minor']);
        self::assertNull($observation['mandatory_fees_minor']);
    }

    public function test_invalid_comparables_are_excluded(): void
    {
        $cases=[
            ['specification'=>['storage'=>'256GB']], ['availability'=>'OUT_OF_STOCK'],
            ['price_type'=>'VOUCHER'], ['price_type'=>'LOWEST_VARIATION'], ['price'=>'0'],
            ['title'=>'phone deposit'], ['title'=>'phone instalment'], ['title'=>'parts-only phone'],
            ['title'=>'phone accessory'], ['lock_status'=>'UNKNOWN'], ['defects'=>'CRACKED'],
            ['own_listing'=>true], ['fixture'=>true], ['verification'=>'UNKNOWN'],
            ['observed_at'=>gmdate('c',self::NOW-15*86400)],
        ];
        foreach($cases as $change){
            $r=(new MarketEngine)->observation(array_replace($this->row(),$change),$this->source(),$this->variant(),self::NOW);
            self::assertNotEmpty($r['excluded'],json_encode($change));
        }
    }

    public function test_range_keeps_source_register_identity_separate_from_platform(): void
    {
        $result=(new MarketEngine)->snapshot($this->observations(),$this->target(),self::NOW);
        self::assertSame(['low_minor'=>94000,'central_minor'=>96000,'high_minor'=>98000],$result['range']);
        self::assertSame('MEDIUM',$result['confidence']);
        self::assertSame(['licensed-feed'],$result['permission_source_ids']);
        self::assertSame(['TEST_PLATFORM'],$result['sources']);
    }

    public function test_sparse_unknown_sellers_duplicates_and_own_listings_never_inflate_range(): void
    {
        $rows=$this->observations();
        $rows[1]['seller_id']=$rows[0]['seller_id'];
        $rows[2]['duplicate_group']=$rows[0]['duplicate_group'];
        $rows[3]['seller_id']=null;
        $rows[4]['excluded']=['OWN_LISTING'];
        $rows[]=$rows[0];
        $result=(new MarketEngine)->snapshot($rows,$this->target(),self::NOW);
        self::assertSame(1,$result['listing_count']);
        self::assertNull($result['range']);
    }

    public function test_reprocessing_does_not_refresh_old_evidence(): void
    {
        $rows=$this->observations();
        $result=(new MarketEngine)->snapshot($rows,$this->target(),self::NOW+15*86400);
        self::assertNull($result['range']);
        self::assertContains('STALE',$result['excluded'][0]['reasons']);
    }

    public function test_extreme_asking_prices_are_retained_and_flagged(): void
    {
        $rows=$this->observations(10);$rows[9]['price_minor']=10000000;
        $result=(new MarketEngine)->snapshot($rows,$this->target(),self::NOW);
        self::assertSame(10,$result['listing_count']);
        self::assertNotEmpty($result['outlier_review']);
        self::assertSame('MEDIUM',$result['confidence']);
    }

    private function policy(): array
    {
        return ['status'=>'APPROVED','version'=>'TEST-ONLY','max_age_seconds'=>3600,'max_adjustment_minor'=>1000,
            'overstock_threshold'=>10,'overstock_adjustment_minor'=>-1500,'low_stock_threshold'=>2,
            'low_stock_adjustment_minor'=>500,'minimum_sales_30d'=>5,'low_demand_sales_threshold'=>1,
            'low_demand_adjustment_minor'=>-300];
    }

    private function signal(): array
    {
        return ['status'=>'ELIGIBLE','observed_at'=>gmdate('c',self::NOW),'sellable_count'=>5,
            'commitments_count'=>0,'sales_30d'=>3,'availability_history_complete'=>true];
    }

    public function test_demand_adjustment_selects_one_reason_and_applies_cap(): void
    {
        $engine=new DemandAdjustment;
        $r=$engine->calculate(10000,array_replace($this->signal(),['sellable_count'=>20,'sales_30d'=>0]),$this->policy(),self::NOW);
        self::assertSame('OVERSTOCK',$r['reason']);self::assertSame(9000,$r['result_minor']);self::assertTrue($r['cap_applied']);
        $r=$engine->calculate(10000,array_replace($this->signal(),['sellable_count'=>1,'sales_30d'=>8]),$this->policy(),self::NOW);
        self::assertSame(10500,$r['result_minor']);
        $r=$engine->calculate(10000,array_replace($this->signal(),['sales_30d'=>0]),$this->policy(),self::NOW);
        self::assertSame('LOW_DEMAND_WITH_COMPLETE_EXPOSURE',$r['reason']);self::assertSame(9700,$r['result_minor']);
    }

    public function test_missing_stale_future_and_extreme_signals_are_neutral(): void
    {
        foreach([null,['status'=>'UNAVAILABLE'],array_replace($this->signal(),['commitments_count'=>null]),
            array_replace($this->signal(),['observed_at'=>gmdate('c',self::NOW-3601)]),
            array_replace($this->signal(),['observed_at'=>gmdate('c',self::NOW+1)]),
            array_replace($this->signal(),['sellable_count'=>PHP_INT_MAX]),
            array_replace($this->signal(),['availability_history_complete'=>false])] as $signal){
            self::assertSame(10000,(new DemandAdjustment)->calculate(10000,$signal,$this->policy(),self::NOW)['result_minor']);
        }
        self::assertSame(10000,(new DemandAdjustment)->calculate(10000,$this->signal(),null,self::NOW)['result_minor']);
    }

    private function trainingRows(): array
    {
        // Synthetic outcomes exist solely inside this isolated mechanics test.
        return array_map(fn($n)=>['device_key'=>'TEST-'.$n,'variant_id'=>'TEST-PHONE-128',
            'origin'=>'NATIVE_COMPLETED_SALE','target_basis'=>'NET_ITEM_EX_TAX_SHIPPING',
            'feature_at'=>gmdate('c',self::NOW-200*86400),'valued_at'=>gmdate('c',self::NOW-190*86400),
            'sold_at'=>gmdate('c',self::NOW-(100-$n)*86400),'sale_minor'=>90000,'baseline_minor'=>100000,
            'features'=>['age_days'=>365,'storage_gb'=>128,'ram_gb'=>8,'physical'=>'good']],range(1,80));
    }

    public function test_training_is_reproducible_with_chronological_holdouts(): void
    {
        $engine=new ResaleModel;$a=$engine->train($this->trainingRows(),self::NOW);$b=$engine->train($this->trainingRows(),self::NOW);
        self::assertSame($a,$b);self::assertSame('SHADOW',$a['status']);
        self::assertLessThan($a['split']['test_from'],$a['split']['train_until']);
        self::assertSame(80,$a['eligible_count']);self::assertSame(64,strlen($a['dataset_sha256']));
        self::assertLessThan($a['metrics']['baseline_mae_minor'],$a['metrics']['model_mae_minor']);
        self::assertNotNull($engine->infer($a,$this->trainingRows()[0],self::NOW));
        self::assertNull($engine->infer($a,array_replace($this->trainingRows()[0],['variant_id'=>'UNSEEN']),self::NOW));
        self::assertNull($engine->infer($a,$this->trainingRows()[0],self::NOW+31*86400));
    }

    public function test_qa_leakage_duplicates_invalid_features_and_sparse_data_cannot_promote(): void
    {
        $rows=$this->trainingRows();$rows[0]['fixture']=true;$rows[1]['feature_at']=gmdate('c',self::NOW);
        $rows[2]['features']=[];$rows[3]['returned']=true;$rows[4]['device_key']=$rows[5]['device_key'];
        $r=(new ResaleModel)->train($rows,self::NOW);
        self::assertSame(75,$r['eligible_count']);self::assertArrayHasKey('INVALID_PRE_VALUATION_FEATURES',$r['exclusions']);
        $r=(new ResaleModel)->train(array_slice($rows,0,20),self::NOW);
        self::assertFalse($r['promotable']);self::assertSame('INSUFFICIENT_DATA',$r['status']);
    }

    public function test_inference_failure_returns_no_prediction(): void
    {
        $engine=new ResaleModel;$model=$engine->train($this->trainingRows(),self::NOW);
        self::assertNull($engine->infer($model,array_replace($this->trainingRows()[0],['features'=>[]]),self::NOW));
        $model['weights']=[INF,0,0,0,0];
        self::assertNull($engine->infer($model,$this->trainingRows()[0],self::NOW));
    }
}
