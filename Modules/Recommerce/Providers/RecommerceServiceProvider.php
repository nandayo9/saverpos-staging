<?php

namespace Modules\Recommerce\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\CohortPolicy;
use Modules\Recommerce\Support\Identity\OpaqueScanToken;
use Modules\Recommerce\Support\LabelPayloadBuilder;
use Modules\Recommerce\Services\TrackedReceivingService;
use Modules\Recommerce\Services\StockReconciliationService;
use Modules\Recommerce\Services\ReconciliationRunService;
use Modules\Recommerce\Services\ScanTokenIssuanceService;
use Modules\Recommerce\Services\UltimatePosPurchaseWriter;
use Modules\Recommerce\Services\DeviceEventRecorder;
use Modules\Recommerce\Services\RepairJobTransitionService;
use Modules\Recommerce\Services\DiagnosticTemplateService;
use Modules\Recommerce\Services\RepairJobIntakeService;
use Modules\Recommerce\Services\RepairPartService;
use Modules\Recommerce\Services\RepairBillingService;
use Modules\Recommerce\Services\RepairCollectionService;
use Modules\Recommerce\Services\UltimatePosStockAdjustmentWriter;
use Modules\Recommerce\Services\CustomerRepairDeviceService;
use Modules\Recommerce\Services\RepairPublicLookupService;
use Modules\Recommerce\Services\DeviceCertificationService;
use Modules\Recommerce\Services\DeviceTransferExceptionService;
use Modules\Recommerce\Services\DeviceReceivingProgressService;
use Modules\Recommerce\Services\DeviceInspectionService;
use Modules\Recommerce\Services\StockCountService;
use Modules\Recommerce\Services\UltimatePosStockCountAdjustmentWriter;

class RecommerceServiceProvider extends ServiceProvider
{
    protected $moduleName = 'Recommerce';

    protected $moduleNameLower = 'recommerce';

    public function register()
    {
        $this->mergeConfigFrom(
            module_path($this->moduleName, 'Config/config.php'),
            $this->moduleNameLower
        );

        $this->app->singleton(CohortPolicy::class, function () {
            return new CohortPolicy();
        });

        $this->app->singleton(AuthorizationGate::class, function ($app) {
            return new AuthorizationGate($app->make(CohortPolicy::class));
        });

        $this->app->singleton(OpaqueScanToken::class, function () {
            return new OpaqueScanToken();
        });

        $this->app->singleton(LabelPayloadBuilder::class, function () {
            return new LabelPayloadBuilder();
        });

        $this->app->singleton(TrackedReceivingService::class, function ($app) {
            return new TrackedReceivingService(
                $app->make(AuthorizationGate::class),
                $app->make(UltimatePosPurchaseWriter::class),
                $app->make(DeviceEventRecorder::class),
                $app->make(StockReconciliationService::class),
                $app->make(DeviceReceivingProgressService::class),
                $app->make(DeviceInspectionService::class)
            );
        });

        $this->app->singleton(DeviceReceivingProgressService::class, function () {
            return new DeviceReceivingProgressService();
        });

        $this->app->singleton(DeviceInspectionService::class, function ($app) {
            return new DeviceInspectionService(
                $app->make(AuthorizationGate::class),
                $app->make(DeviceEventRecorder::class)
            );
        });

        $this->app->singleton(StockReconciliationService::class, function ($app) {
            return new StockReconciliationService($app->make(AuthorizationGate::class));
        });

        $this->app->singleton(ReconciliationRunService::class, function ($app) {
            return new ReconciliationRunService(
                $app->make(AuthorizationGate::class),
                $app->make(StockReconciliationService::class)
            );
        });

        $this->app->singleton(ScanTokenIssuanceService::class, function ($app) {
            return new ScanTokenIssuanceService(
                $app->make(AuthorizationGate::class),
                $app->make(OpaqueScanToken::class),
                $app->make(DeviceEventRecorder::class)
            );
        });

        $this->app->singleton(UltimatePosPurchaseWriter::class, function ($app) {
            return new UltimatePosPurchaseWriter(
                $app->make(\App\Utils\ProductUtil::class),
                $app->make(\App\Utils\TransactionUtil::class)
            );
        });

        $this->app->singleton(DeviceEventRecorder::class, function () {
            return new DeviceEventRecorder();
        });

        $this->app->singleton(RepairJobTransitionService::class, function () {
            return new RepairJobTransitionService();
        });

        $this->app->singleton(DiagnosticTemplateService::class, function () {
            return new DiagnosticTemplateService($this->app->make(AuthorizationGate::class));
        });

        $this->app->singleton(RepairJobIntakeService::class, function ($app) {
            return new RepairJobIntakeService(
                $app->make(AuthorizationGate::class),
                $app->make(CustomerRepairDeviceService::class),
                $app->make(RepairPublicLookupService::class)
            );
        });

        $this->app->singleton(CustomerRepairDeviceService::class, function ($app) {
            return new CustomerRepairDeviceService($app->make(AuthorizationGate::class));
        });

        $this->app->singleton(RepairPublicLookupService::class, function ($app) {
            return new RepairPublicLookupService($app->make(OpaqueScanToken::class));
        });

        $this->app->singleton(DeviceCertificationService::class, function ($app) {
            return new DeviceCertificationService($app->make(AuthorizationGate::class));
        });

        $this->app->singleton(DeviceTransferExceptionService::class, function ($app) {
            return new DeviceTransferExceptionService($app->make(AuthorizationGate::class));
        });

        $this->app->singleton(RepairPartService::class, function ($app) {
            return new RepairPartService(
                $app->make(AuthorizationGate::class),
                $app->make(UltimatePosStockAdjustmentWriter::class)
            );
        });

        $this->app->singleton(RepairBillingService::class, function ($app) {
            return new RepairBillingService($app->make(AuthorizationGate::class));
        });

        $this->app->singleton(RepairCollectionService::class, function ($app) {
            return new RepairCollectionService(
                $app->make(AuthorizationGate::class),
                $app->make(DeviceEventRecorder::class)
            );
        });

        $this->app->singleton(UltimatePosStockAdjustmentWriter::class, function ($app) {
            return new UltimatePosStockAdjustmentWriter(
                $app->make(\App\Utils\ProductUtil::class),
                $app->make(\App\Utils\TransactionUtil::class)
            );
        });

        $this->app->singleton(UltimatePosStockCountAdjustmentWriter::class, function ($app) {
            return new UltimatePosStockCountAdjustmentWriter($app->make(\App\Utils\ProductUtil::class), $app->make(\App\Utils\TransactionUtil::class));
        });

        $this->app->singleton(StockCountService::class, function ($app) {
            return new StockCountService($app->make(AuthorizationGate::class), $app->make(UltimatePosStockCountAdjustmentWriter::class), $app->make(OpaqueScanToken::class));
        });

        if (config('recommerce.enabled', false) === true) {
            $this->app->register(RouteServiceProvider::class);
        }
    }

    public function boot()
    {
        // Migrations are discoverable for an explicit artisan migrate run even
        // while all HTTP routes and operational writes remain disabled. This
        // lets an operator prepare the schema and roles before opening the
        // feature gate; it does not execute a migration during ordinary web
        // requests.
        $this->loadMigrationsFrom(
            module_path($this->moduleName, 'Database/Migrations')
        );

        if (config('recommerce.enabled', false) !== true) {
            return;
        }

        $this->loadViewsFrom(
            module_path($this->moduleName, 'Resources/views'),
            $this->moduleNameLower
        );

    }
}
