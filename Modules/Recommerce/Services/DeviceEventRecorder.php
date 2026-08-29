<?php

namespace Modules\Recommerce\Services;

use Illuminate\Support\Str;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceEvent;
use Modules\Recommerce\Entities\OutboxMessage;

/**
 * Minimal append-only Device timeline writer for the Alpha receive slice.
 * Only safe operational metadata is accepted here; raw identifiers and token
 * material never enter the event payload.
 */
class DeviceEventRecorder
{
    public function recordReceive(
        Device $device,
        string $commandUuid,
        int $transactionId,
        int $purchaseLineId,
        int $actorId
    ): DeviceEvent {
        $event = DeviceEvent::create([
            'event_uuid' => (string) Str::uuid(),
            'event_version' => 1,
            'device_id' => $device->id,
            'business_id' => $device->business_id,
            'actor_id' => $actorId,
            'event_type' => 'RECEIVE_POSTED',
            'source_command_uuid' => $commandUuid,
            'source_transaction_id' => $transactionId,
            'metadata_json' => [
                'location_id' => (int) $device->current_location_id,
                'variation_id' => (int) $device->variation_id,
                'lifecycle_state' => $device->lifecycle_state,
                'stock_participation' => $device->stock_participation,
                'purchase_line_id' => $purchaseLineId,
            ],
            'occurred_at' => now(),
        ]);

        $this->appendOutbox($event);

        return $event;
    }

    public function recordLabelIssued(
        Device $device,
        int $tokenId,
        bool $rotated,
        int $actorId,
        ?int $replacedTokenId = null
    ): DeviceEvent {
        $event = DeviceEvent::create([
            'event_uuid' => (string) Str::uuid(),
            'event_version' => 1,
            'device_id' => $device->id,
            'business_id' => $device->business_id,
            'actor_id' => $actorId,
            'event_type' => $rotated ? 'LABEL_TOKEN_ROTATED' : 'LABEL_TOKEN_ISSUED',
            'metadata_json' => array_filter([
                'location_id' => $device->current_location_id === null
                    ? null
                    : (int) $device->current_location_id,
                'variation_id' => $device->variation_id === null
                    ? null
                    : (int) $device->variation_id,
                'token_id' => $tokenId,
                'replaced_token_id' => $replacedTokenId,
                'device_code' => $device->device_code,
            ], static fn ($value) => $value !== null),
            'occurred_at' => now(),
        ]);

        $this->appendOutbox($event);

        return $event;
    }

    public function recordCustomerRepairIntake(Device $device, string $commandUuid, int $actorId): DeviceEvent
    {
        $event = DeviceEvent::create([
            'event_uuid' => (string) Str::uuid(),
            'event_version' => 1,
            'device_id' => $device->id,
            'business_id' => $device->business_id,
            'actor_id' => $actorId,
            'event_type' => 'CUSTOMER_REPAIR_INTAKE',
            'source_command_uuid' => $commandUuid,
            'metadata_json' => [
                'location_id' => (int) $device->current_location_id,
                'lifecycle_state' => $device->lifecycle_state,
                'stock_participation' => $device->stock_participation,
                'device_code' => $device->device_code,
            ],
            'occurred_at' => now(),
        ]);

        $this->appendOutbox($event);

        return $event;
    }

    /**
     * Appends safe lifecycle evidence. Callers may only pass operational IDs
     * and state; identifiers, tokens, costs, and customer data stay out of
     * the event payload.
     */
    public function recordLifecycle(
        Device $device,
        string $eventType,
        int $actorId,
        ?int $transactionId,
        array $metadata = []
    ): DeviceEvent {
        $event = DeviceEvent::create([
            'event_uuid' => (string) Str::uuid(),
            'event_version' => 1,
            'device_id' => $device->id,
            'business_id' => $device->business_id,
            'actor_id' => $actorId,
            'event_type' => $eventType,
            'source_transaction_id' => $transactionId,
            'metadata_json' => array_filter([
                'location_id' => $device->current_location_id === null ? null : (int) $device->current_location_id,
                'variation_id' => $device->variation_id === null ? null : (int) $device->variation_id,
                'lifecycle_state' => $device->lifecycle_state,
                'stock_participation' => $device->stock_participation,
            ] + $metadata, static fn ($value) => $value !== null),
            'occurred_at' => now(),
        ]);

        $this->appendOutbox($event);

        return $event;
    }

    protected function appendOutbox(DeviceEvent $event): OutboxMessage
    {
        return OutboxMessage::create([
            'event_id' => $event->id,
            'business_id' => $event->business_id,
            'topic' => 'recommerce.device.event',
            'payload_json' => [
                'event_id' => (int) $event->id,
                'event_uuid' => $event->event_uuid,
                'event_version' => (int) $event->event_version,
                'device_id' => (int) $event->device_id,
                'event_type' => $event->event_type,
                'source_command_uuid' => $event->source_command_uuid,
                'source_transaction_id' => $event->source_transaction_id === null
                    ? null
                    : (int) $event->source_transaction_id,
                'metadata' => is_array($event->metadata_json) ? $event->metadata_json : [],
                'occurred_at' => $event->occurred_at?->toISOString(),
            ],
            'status' => 'PENDING',
            'attempts' => 0,
            'available_at' => now(),
        ]);
    }
}
