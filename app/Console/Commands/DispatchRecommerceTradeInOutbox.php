<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Recommerce\Services\TradeInOutboxDispatcher;

final class DispatchRecommerceTradeInOutbox extends Command
{
    protected $signature = 'recommerce:tradein-outbox:dispatch {--event=} {--limit=50}';
    protected $description = 'Deliver or safely retry pending SaverBro Trade-In customer projection events.';

    public function handle(TradeInOutboxDispatcher $dispatcher): int
    {
        $event = trim((string) $this->option('event')) ?: null;
        if ($event !== null && ! preg_match('/^[a-f0-9-]{36}$/', $event)) {
            $this->error('The event option must be a UUID.');
            return self::INVALID;
        }
        $result = $dispatcher->dispatchPending((int) $this->option('limit'), $event);
        $this->line(json_encode($result));
        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
