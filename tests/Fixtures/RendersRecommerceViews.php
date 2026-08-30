<?php

namespace Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Minimum needed to render a Recommerce screen outside a request: the module's
 * view namespace, a stand-in for the Ultimate POS layout, the module routes the
 * views build links from, and a `system` table -- a global view composer
 * (ModuleAssetServiceProvider -> System) reads app settings on every render, so
 * even a layout-less document needs it.
 */
trait RendersRecommerceViews
{
    protected function bootRecommerceViewRendering(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            // RouteServiceProvider::map() is a no-op while the module is off,
            // and every screen builds links with route().
            'recommerce.enabled' => true,
        ]);
        DB::purge('sqlite');
        Schema::connection('sqlite')->create('system', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('key');
            $table->text('value')->nullable();
        });

        app('view')->addNamespace('recommerce', base_path('Modules/Recommerce/Resources/views'));
        app('view')->getFinder()->prependLocation(base_path('tests/Fixtures/views'));
        app('view')->flushFinderCache();

        (new \Modules\Recommerce\Providers\RouteServiceProvider(app()))->map();
        app('router')->getRoutes()->refreshNameLookups();
        app('url')->setRoutes(app('router')->getRoutes());
    }

    protected function renderRecommerceView(string $view, array $data): string
    {
        return (string) view($view, $data)->render();
    }
}
