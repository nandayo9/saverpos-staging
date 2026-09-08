<?php
/** Read existing governed variant evidence, trial-map it transactionally, always roll back. */
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment(['local', 'staging', 'testing'])) throw new RuntimeException('Catalogue dry-run is restricted to local/staging/testing.');
$business = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT);
if (! $business || $business < 1) throw new RuntimeException('Pass the staging business ID.');
$catalogue = app(Modules\Recommerce\Services\CanonicalDeviceCatalogue::class);
if (! $catalogue->ready()) throw new RuntimeException('Apply the additive catalogue migration to the disposable/staging database first.');
$store = app(Modules\Recommerce\Services\Intelligence\RecordStore::class);
$records = $store->all($business, 'VARIANT', null, 5000);
if (count($records) === 5000) throw new RuntimeException('Evidence exceeds the bounded dry-run. Paginate before claiming complete mapping.');
$latest = []; foreach ($records as $r) if (! isset($latest[$r['target']])) $latest[$r['target']] = $r;
$report = ['mode' => 'DRY_RUN_ALWAYS_ROLLBACK', 'business_id' => $business, 'mapped' => [], 'ambiguous' => []];
Illuminate\Support\Facades\DB::beginTransaction();
try {
    foreach ($latest as $id => $r) {
        if (($r['data']['status'] ?? '') !== 'VERIFIED') continue;
        try {
            app(Modules\Recommerce\Services\Intelligence\IntelligenceService::class)->import($business,
                ['kind' => 'VARIANT', 'target' => $id, 'data' => $r['data']], (int) ($r['actor_id'] ?? 0), 'Catalogue dry-run; always rolled back.');
            $report['mapped'][] = $id;
        } catch (LogicException $e) { $report['ambiguous'][] = ['variant_id' => $id, 'reason' => $e->getMessage()]; }
    }
} finally { Illuminate\Support\Facades\DB::rollBack(); }
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
exit($report['ambiguous'] === [] ? 0 : 2);
