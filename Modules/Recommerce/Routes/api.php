<?php

use Illuminate\Support\Facades\Route;
use Modules\Recommerce\Http\Middleware\CustomerProjectionToken;

Route::middleware([CustomerProjectionToken::class, 'throttle:60,1'])
    ->prefix('customer-projection/v1')
    ->group(function (): void {
        Route::get('/models', 'CustomerProjectionController@models');
        Route::get('/models/{slug}', 'CustomerProjectionController@model')
            ->where('slug', '[a-z0-9-]+');
        Route::get('/models/{slug}/specifications', 'CustomerProjectionController@specifications')
            ->where('slug', '[a-z0-9-]+');
        Route::get('/specifications/{publicId}', 'CustomerProjectionController@specification')
            ->where('publicId', '[A-Za-z0-9-]+');
        Route::get('/specifications/{publicId}/devices', 'CustomerProjectionController@devices')
            ->where('publicId', '[A-Za-z0-9-]+');
        Route::get('/devices/{publicDeviceId}', 'CustomerProjectionController@device')
            ->where('publicDeviceId', '[A-Za-z0-9-]+');
    });
