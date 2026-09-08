<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Modules\Recommerce\Services\Intelligence\IntelligenceJobs;
final class RunTradeInIntelligence extends Command {
 protected $signature='recommerce:tradein-intelligence {--limit=3}';
 protected $description='Process bounded staging market, native signal and model jobs.';
 public function handle(IntelligenceJobs $jobs): int {$this->line(json_encode($jobs->run((int)config('recommerce.cohort.business_id'),(int)$this->option('limit'))));return self::SUCCESS;}
}
