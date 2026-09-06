<?php

namespace Modules\Recommerce\Services;

use App\Transaction;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Recommerce\Entities\DeviceAcquisition;
use Modules\Recommerce\Entities\TradeInIntake;
use Modules\Recommerce\Entities\TradeInOutboxMessage;

final class TradeInOutboxService
{
    /** @param array<string, mixed> $projection */
    public function record(TradeInIntake $intake, string $eventType, array $projection): TradeInOutboxMessage
    {
        $eventId = (string) Str::uuid();
        $payload = [
            'contract_version' => 'trade-in-customer-projection.v1',
            'event_id' => $eventId,
            'event_type' => $eventType,
            'schema_version' => '1.0',
            'website_case_reference' => (string) $intake->external_case_reference,
            'pos_case_reference' => (string) $intake->intake_uuid,
            'aggregate_version' => (int) $intake->projection_version,
            'projection' => $projection,
            'occurred_at' => now()->toISOString(),
        ];

        return TradeInOutboxMessage::firstOrCreate([
            'intake_id' => $intake->id,
            'aggregate_version' => $intake->projection_version,
        ], [
            'event_uuid' => $eventId,
            'business_id' => $intake->business_id,
            'event_type' => $eventType,
            'schema_version' => '1.0',
            'payload_json' => $payload,
            'destination' => 'SAVERBRO_WEBSITE',
            'status' => 'PENDING',
            // A newly committed event is immediately eligible. Null avoids
            // coupling queue eligibility to a request-scoped branch timezone.
            'available_at' => null,
        ]);
    }

    /** Called from the native Transaction model observer while payment writes are still transactional. */
    public function recordSettlementChange(Transaction $transaction): ?TradeInOutboxMessage
    {
        if (! Schema::hasTable('recommerce_trade_in_outbox_messages') || ! $transaction->wasChanged('payment_status')) return null;
        $acquisition = DeviceAcquisition::query()->where('transaction_id', $transaction->id)->first();
        if (! $acquisition) return null;
        $intake = TradeInIntake::query()->where('valuation_id', $acquisition->trade_in_valuation_id)->lockForUpdate()->first();
        if (! $intake) return null;
        $intake->projection_version++;
        $intake->save();
        $projection = app(TradeInWebsiteCaseService::class)->projection($intake->fresh());
        return $this->record($intake->fresh(), 'SETTLEMENT_UPDATED', $projection);
    }
}
