<?php

use Illuminate\Support\Facades\Route;
use Modules\Recommerce\Http\Middleware\CustomerProjectionToken;
use Modules\Recommerce\Http\Middleware\TradeInAcquisitionCommandToken;

Route::middleware([CustomerProjectionToken::class, 'throttle:60,1'])
    ->prefix('customer-projection/v1')
    ->group(function (): void {
        Route::get('/listings', 'CustomerProjectionController@listings');
        Route::get('/models', 'CustomerProjectionController@models');
        Route::get('/models/{slug}', 'CustomerProjectionController@model')
            ->where('slug', '[a-z0-9-]+');
        Route::get('/models/{slug}/specifications', 'CustomerProjectionController@specifications')
            ->where('slug', '[a-z0-9-]+');
        Route::get('/specifications/{publicId}', 'CustomerProjectionController@specification')
            ->where('publicId', '[A-Za-z0-9][A-Za-z0-9_.:-]{0,149}');
        Route::get('/specifications/{publicId}/devices', 'CustomerProjectionController@devices')
            ->where('publicId', '[A-Za-z0-9][A-Za-z0-9_.:-]{0,149}');
        Route::get('/devices/{publicDeviceId}', 'CustomerProjectionController@device')
            ->where('publicDeviceId', '[A-Za-z0-9-]+');
        Route::get('/devices/{publicDeviceId}/status', 'CustomerProjectionController@status')
            ->where('publicDeviceId', '[A-Za-z0-9-]+');
    });

Route::middleware([TradeInAcquisitionCommandToken::class, 'throttle:10,1'])
    ->prefix('trade-in/v2')
    ->group(function (): void {
        Route::get('/catalogue', 'TradeInWebsiteApiController@catalogue');
        Route::post('/valuations/indicative', 'TradeInWebsiteApiController@indicative');
        Route::post('/intakes', 'TradeInWebsiteApiController@intake');
        Route::get('/intakes/{externalCaseReference}/projection', 'TradeInWebsiteApiController@projection')
            ->where('externalCaseReference', 'SB-TI-[0-9]{8}-[0-9]{5}');
        Route::post('/intakes/{externalCaseReference}/decisions', 'TradeInWebsiteApiController@decide')
            ->where('externalCaseReference', 'SB-TI-[0-9]{8}-[0-9]{5}');
    });
