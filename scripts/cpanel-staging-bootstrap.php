<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SaverposDemoRuntimeSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ((string) config('app.env') !== 'staging') {
    fwrite(STDERR, "Refusing to bootstrap a non-staging environment.\n");
    exit(1);
}

if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
    fwrite(STDERR, Artisan::output());
    exit(1);
}

echo Artisan::output();

if (! Schema::hasTable('business')) {
    fwrite(STDERR, "The migrations completed but the business table is unavailable.\n");
    exit(1);
}

if (DB::table('business')->exists()) {
    echo "A business already exists; leaving the staging data untouched.\n";
    exit(0);
}

echo "Fresh staging database detected; creating the fictional SAVERPOS demo estate.\n";

foreach ([DatabaseSeeder::class, SaverposDemoRuntimeSeeder::class] as $seeder) {
    if (Artisan::call('db:seed', ['--class' => $seeder, '--force' => true, '--no-interaction' => true]) !== 0) {
        fwrite(STDERR, Artisan::output());
        exit(1);
    }

    echo Artisan::output();
}

echo "Demo fixture created. Configure the Recommerce cohort IDs to business=1, locations=1,2, variation=1.\n";
