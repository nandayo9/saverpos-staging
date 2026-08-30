<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'SetSessionData'])->prefix('recommerce')->group(function () {
    Route::get('/', 'DashboardController@index')
        ->middleware('throttle:30,1')
        ->name('recommerce.dashboard');

    Route::get('/health', function () {
        abort_unless(config('recommerce.enabled', false) === true, 404);

        return response()->json([
            'module' => 'recommerce',
            'writes_enabled' => (bool) config('recommerce.writes_enabled', false),
            'status' => 'native-pos-integrated',
            'navigation' => 'ultimate-pos-admin-sidebar',
            'operational_writes' => 'cohort-and-permission-gated',
        ]);
    })->name('recommerce.health');

    Route::get('/devices', 'DeviceController@index')
        ->middleware('throttle:30,1')
        ->name('recommerce.devices.index');

    Route::get('/devices/{deviceCode}', 'DeviceController@show')
        ->where('deviceCode', '[A-Za-z0-9-]+')
        ->name('recommerce.devices.show');

    Route::get('/devices/{deviceCode}/events', 'DeviceEventController@index')
        ->where('deviceCode', '[A-Za-z0-9-]+')
        ->middleware('throttle:60,1')
        ->name('recommerce.devices.events');

    Route::post('/devices/{deviceId}/label', 'LabelController@issue')
        ->whereNumber('deviceId')
        ->middleware('throttle:30,1')
        ->name('recommerce.devices.label');

    Route::post('/devices/{deviceId}/label/print', 'LabelController@print')
        ->whereNumber('deviceId')
        ->middleware('throttle:10,1')
        ->name('recommerce.devices.label.print');

    Route::post('/devices/{deviceId}/certification', 'DeviceCertificationController@store')
        ->whereNumber('deviceId')
        ->middleware('throttle:20,1')
        ->name('recommerce.devices.certification.store');

    Route::get('/scans', 'ScanController@index')
        ->middleware('throttle:30,1')
        ->name('recommerce.scans.index');

    Route::post('/scans/resolve', 'ScanController@resolve')
        ->middleware('throttle:60,1')
        ->name('recommerce.scans.resolve');

    Route::get('/receiving', 'ReceivingController@index')
        ->middleware('throttle:30,1')
        ->name('recommerce.receiving.index');

    Route::post('/receiving/prepare', 'ReceivingController@prepare')
        ->middleware('throttle:30,1')
        ->name('recommerce.receiving.prepare');

    Route::post('/receiving/post', 'ReceivingController@post')
        ->middleware('throttle:20,1')
        ->name('recommerce.receiving.post');

    Route::post('/receiving/attach-purchase', 'ReceivingController@attachPurchase')
        ->middleware('throttle:20,1')
        ->name('recommerce.receiving.attach_purchase');

    Route::get('/trade-ins', 'TradeInController@index')
        ->middleware('throttle:30,1')
        ->name('recommerce.tradeins.index');
    Route::post('/trade-ins', 'TradeInController@store')
        ->middleware('throttle:20,1')
        ->name('recommerce.tradeins.store');
    Route::post('/trade-ins/rules', 'TradeInController@createRule')
        ->middleware('throttle:10,1')
        ->name('recommerce.tradeins.rules.store');
    Route::post('/trade-ins/{valuationId}/approve', 'TradeInController@approve')
        ->whereNumber('valuationId')
        ->middleware('throttle:20,1')
        ->name('recommerce.tradeins.approve');
    Route::post('/trade-ins/{valuationId}/accept', 'TradeInController@accept')
        ->whereNumber('valuationId')
        ->middleware('throttle:10,1')
        ->name('recommerce.tradeins.accept');
    Route::post('/trade-ins/{valuationId}/reject', 'TradeInController@reject')
        ->whereNumber('valuationId')
        ->middleware('throttle:20,1')
        ->name('recommerce.tradeins.reject');

    Route::get('/transfers/{transferId}/exceptions', 'TransferExceptionController@show')
        ->whereNumber('transferId')
        ->middleware('throttle:30,1')
        ->name('recommerce.transfers.exceptions');

    Route::post('/transfers/{transferId}/exceptions/receive', 'TransferExceptionController@receive')
        ->whereNumber('transferId')
        ->middleware('throttle:20,1')
        ->name('recommerce.transfers.exceptions.receive');

    Route::post('/transfers/exceptions/{exceptionId}/resolve', 'TransferExceptionController@resolve')
        ->whereNumber('exceptionId')
        ->middleware('throttle:20,1')
        ->name('recommerce.transfers.exceptions.resolve');

    Route::get('/repair', 'RepairJobController@index')
        ->middleware('throttle:30,1')
        ->name('recommerce.repair.index');

    Route::get('/customer-repairs', 'RepairJobController@customerIndex')
        ->middleware('throttle:30,1')
        ->name('recommerce.customer_repairs.index');

    Route::get('/repair/new', 'RepairJobController@createPage')
        ->middleware('throttle:30,1')
        ->name('recommerce.repair.new');

    Route::get('/repair/internal/new', 'RepairJobController@createInternalPage')
        ->middleware('throttle:30,1')
        ->name('recommerce.repair.internal.create');

    Route::get('/repair/customers', 'RepairJobController@customers')
        ->middleware('throttle:60,1')
        ->name('recommerce.repair.customers');

    Route::get('/repair/devices/search', 'RepairJobController@devices')
        ->middleware('throttle:60,1')
        ->name('recommerce.repair.devices.search');

    Route::post('/repair/intake', 'RepairJobController@intake')
        ->middleware('throttle:20,1')
        ->name('recommerce.repair.intake');

    Route::get('/repair/legacy-archive', 'LegacyRepairArchiveController@index')
        ->middleware('throttle:30,1')
        ->name('recommerce.repair.legacy_archive.index');

    Route::get('/repair/legacy-archive/{archiveId}', 'LegacyRepairArchiveController@show')
        ->whereNumber('archiveId')
        ->middleware('throttle:60,1')
        ->name('recommerce.repair.legacy_archive.show');

    Route::get('/repair/{jobCode}', 'RepairJobController@show')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->middleware('throttle:60,1')
        ->name('recommerce.repair.show');

    Route::post('/repair/{jobCode}/transition', 'RepairJobController@transition')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->middleware('throttle:30,1')
        ->name('recommerce.repair.transition');

    Route::get('/repair/{jobCode}/parts', 'PartsController@show')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->middleware('throttle:30,1')
        ->name('recommerce.repair.parts.show');

    Route::post('/repair/{jobCode}/parts/reserve', 'PartsController@reserve')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->middleware('throttle:20,1')
        ->name('recommerce.repair.parts.reserve');

    Route::post('/repair/{jobCode}/parts/{reservationId}/issue', 'PartsController@issue')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->whereNumber('reservationId')
        ->middleware('throttle:20,1')
        ->name('recommerce.repair.parts.issue');

    Route::post('/repair/{jobCode}/parts/{reservationId}/release', 'PartsController@release')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->whereNumber('reservationId')
        ->middleware('throttle:20,1')
        ->name('recommerce.repair.parts.release');

    Route::post('/repair/{jobCode}/parts/usages/{usageId}/install', 'PartsController@install')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->whereNumber('usageId')
        ->middleware('throttle:20,1')
        ->name('recommerce.repair.parts.install');

    Route::post('/repair/{jobCode}/parts/usages/{usageId}/resolve', 'PartsController@resolve')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->whereNumber('usageId')
        ->middleware('throttle:20,1')
        ->name('recommerce.repair.parts.resolve');

    Route::post('/repair/{jobCode}/parts/usages/{usageId}/consume-internal', 'PartsController@consumeInternal')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->whereNumber('usageId')
        ->middleware('throttle:20,1')
        ->name('recommerce.repair.parts.consume-internal');

    Route::get('/repair/{jobCode}/diagnostics', 'DiagnosticController@show')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->middleware('throttle:30,1')
        ->name('recommerce.repair.diagnostics.show');

    Route::post('/repair/{jobCode}/diagnostics/start', 'DiagnosticController@start')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->middleware('throttle:20,1')
        ->name('recommerce.repair.diagnostics.start');

    Route::post('/repair/{jobCode}/diagnostics/{sessionId}/submit', 'DiagnosticController@submit')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->whereNumber('sessionId')
        ->middleware('throttle:20,1')
        ->name('recommerce.repair.diagnostics.submit');

    Route::get('/diagnostic-templates', 'DiagnosticTemplateController@index')
        ->middleware('throttle:30,1')
        ->name('recommerce.diagnostic.templates.index');
    Route::get('/diagnostic-templates/new', 'DiagnosticTemplateController@create')
        ->middleware('throttle:30,1')
        ->name('recommerce.diagnostic.templates.create');
    Route::post('/diagnostic-templates', 'DiagnosticTemplateController@store')
        ->middleware('throttle:20,1')
        ->name('recommerce.diagnostic.templates.store');
    Route::get('/diagnostic-templates/{templateId}/versions/{versionId}/edit', 'DiagnosticTemplateController@edit')
        ->whereNumber('templateId')->whereNumber('versionId')
        ->middleware('throttle:30,1')
        ->name('recommerce.diagnostic.templates.edit');
    Route::put('/diagnostic-templates/{templateId}/versions/{versionId}', 'DiagnosticTemplateController@update')
        ->whereNumber('templateId')->whereNumber('versionId')
        ->middleware('throttle:20,1')
        ->name('recommerce.diagnostic.templates.update');
    Route::post('/diagnostic-templates/{templateId}/versions/{versionId}/publish', 'DiagnosticTemplateController@publish')
        ->whereNumber('templateId')->whereNumber('versionId')
        ->middleware('throttle:20,1')
        ->name('recommerce.diagnostic.templates.publish');
    Route::post('/diagnostic-templates/{templateId}/versions/{versionId}/retire', 'DiagnosticTemplateController@retire')
        ->whereNumber('templateId')->whereNumber('versionId')
        ->middleware('throttle:20,1')
        ->name('recommerce.diagnostic.templates.retire');
    Route::post('/diagnostic-templates/{templateId}/revision', 'DiagnosticTemplateController@revision')
        ->whereNumber('templateId')
        ->middleware('throttle:20,1')
        ->name('recommerce.diagnostic.templates.revision');

    Route::post('/repair/{jobCode}/quotes', 'QuoteController@store')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->middleware('throttle:20,1')
        ->name('recommerce.repair.quotes.store');

    Route::put('/repair/{jobCode}/quotes/{quoteId}', 'QuoteController@update')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->whereNumber('quoteId')
        ->middleware('throttle:20,1')
        ->name('recommerce.repair.quotes.update');

    Route::post('/repair/{jobCode}/quotes/{quoteId}/send', 'QuoteController@send')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->whereNumber('quoteId')
        ->middleware('throttle:20,1')
        ->name('recommerce.repair.quotes.send');

    Route::post('/repair/{jobCode}/quotes/{quoteId}/decision', 'QuoteController@decide')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->whereNumber('quoteId')
        ->middleware('throttle:20,1')
        ->name('recommerce.repair.quotes.decide');

    Route::get('/repair/{jobCode}/billing', 'RepairBillingController@project')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->middleware('throttle:30,1')
        ->name('recommerce.repair.billing.project');

    Route::post('/repair/{jobCode}/billing/link', 'RepairBillingController@link')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->middleware('throttle:20,1')
        ->name('recommerce.repair.billing.link');

    Route::post('/repair/{jobCode}/billing/release', 'RepairBillingController@release')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->middleware('throttle:20,1')
        ->name('recommerce.repair.billing.release');

    Route::get('/repair/{jobCode}/collection', 'RepairCollectionController@summary')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->middleware('throttle:30,1')
        ->name('recommerce.repair.collection.show');

    Route::post('/repair/{jobCode}/collection', 'RepairCollectionController@collect')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->middleware('throttle:20,1')
        ->name('recommerce.repair.collection.collect');

    Route::post('/repair/{jobCode}/repeat', 'RepairCollectionController@repeat')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->middleware('throttle:20,1')
        ->name('recommerce.repair.collection.repeat');

    Route::post('/repair/legacy-archive', 'LegacyRepairArchiveController@store')
        ->middleware('throttle:20,1')
        ->name('recommerce.repair.legacy_archive.store');

    Route::post('/repair/{jobCode}/warranty/claim', 'WarrantyClaimController@store')
        ->where('jobCode', '[A-Za-z0-9-]+')
        ->middleware('throttle:20,1')
        ->name('recommerce.repair.warranty.store');

    Route::get('/reconciliation', 'ReconciliationController@index')
        ->middleware('throttle:30,1')
        ->name('recommerce.reconciliation.index');

    Route::get('/reconciliation/{variationId}', 'ReconciliationController@show')
        ->whereNumber('variationId')
        ->middleware('throttle:60,1')
        ->name('recommerce.reconciliation.show');

    Route::post('/reconciliation/{variationId}/runs', 'ReconciliationController@store')
        ->whereNumber('variationId')
        ->middleware('throttle:30,1')
        ->name('recommerce.reconciliation.runs.store');
});

Route::get('/s/d/{token}', 'ScanController@device')
    ->where('token', '[A-Fa-f0-9]{64}')
    ->middleware('throttle:60,1')
    ->name('recommerce.scan.device');

Route::get('/recommerce/repair/status/{jobCode}/{token}', 'RepairJobController@publicStatus')
    ->where('jobCode', '[A-Za-z0-9-]+')
    ->where('token', '[A-Fa-f0-9]{64}')
    ->middleware('throttle:30,1')
    ->name('recommerce.repair.public_status');
