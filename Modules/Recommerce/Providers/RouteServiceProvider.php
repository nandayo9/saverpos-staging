<?php

namespace Modules\Recommerce\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    protected $moduleNamespace = 'Modules\\Recommerce\\Http\\Controllers';

    public function map()
    {
        if (config('recommerce.enabled', false) !== true) {
            return;
        }

        Route::middleware('web')
            ->namespace($this->moduleNamespace)
            ->group(module_path('Recommerce', 'Routes/web.php'));

        Route::prefix('api')
            ->middleware('api')
            ->namespace($this->moduleNamespace)
            ->group(module_path('Recommerce', 'Routes/api.php'));
    }
}
