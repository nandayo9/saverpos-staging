<?php

namespace Modules\Recommerce\Services;

use Illuminate\Support\Collection;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceEvent;

class DeviceEventTimelineService
{
    public function forDevice(Device $device): Collection
    {
        return DeviceEvent::query()
            ->where('business_id', $device->business_id)
            ->where('device_id', $device->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (DeviceEvent $event): array => $this->present($event))
            ->values();
    }

    protected function present(DeviceEvent $event): array
    {
        return [
            'id' => (int) $event->id,
            'event_uuid' => $event->event_uuid,
            'event_version' => $event->event_version === null ? null : (int) $event->event_version,
            'event_type' => $event->event_type,
            'label' => $this->label($event->event_type),
            'source_command_uuid' => $event->source_command_uuid,
            'source_transaction_id' => $event->source_transaction_id === null
                ? null
                : (int) $event->source_transaction_id,
            'metadata' => $this->safeMetadata($event->metadata_json),
            'occurred_at' => $event->occurred_at?->toISOString(),
        ];
    }

    protected function safeMetadata($metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }

        return array_intersect_key($metadata, array_flip([
            'location_id',
            'variation_id',
            'lifecycle_state',
            'stock_participation',
            'purchase_line_id',
            'token_id',
            'replaced_token_id',
            'device_code',
        ]));
    }

    protected function label(string $eventType): string
    {
        return [
            'TRANSFER_PREPARED' => 'Selected for transfer',
            'TRANSFER_DISPATCHED' => 'Dispatched — in transit',
            'TRANSFER_RECEIVED_SCAN' => 'Received at destination',
            'TRANSFER_COMPLETED' => 'Transfer completed — available in destination inventory',
        ][$eventType] ?? ucwords(strtolower(str_replace('_', ' ', $eventType)));
    }
}
