<?php

namespace Modules\Recommerce\Services;

use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\LabelJob;
use Modules\Recommerce\Entities\LabelJobItem;
use Modules\Recommerce\Support\LabelPayloadBuilder;

/**
 * Bounded visible-page Device label printing.
 *
 * This service intentionally accepts only explicit Device IDs. It never
 * materialises an unbounded Registry search and it makes no lifecycle or
 * inventory changes. The permanent token service re-queries and authorises
 * every selected Device inside the enclosing transaction.
 */
class BulkDeviceLabelPrintService
{
    public function __construct(
        protected ScanTokenIssuanceService $tokenIssuanceService,
        protected LabelPayloadBuilder $labelPayloadBuilder,
        protected LabelRenderer $labelRenderer
    ) {
    }

    /**
     * @param array<int, int|string> $deviceIds
     * @return array{job: LabelJob, labels: array<int, array{label: array, rendered: array}>}
     */
    public function render(User $user, array $deviceIds): array
    {
        return DB::transaction(function () use ($user, $deviceIds): array {
            $labels = $this->tokenIssuanceService->issueOrReuseForLabels(
                $user,
                $deviceIds,
                function (array $issued, Device $device): array {
                    $payload = $this->labelPayloadBuilder->forDevice($device, $issued['raw_token']);

                    return [
                        'device_id' => (int) $device->id,
                        'device_code' => $device->device_code,
                        'token_id' => (int) $issued['token_id'],
                        'reprint' => (bool) ($issued['reprint'] ?? false),
                        'label' => $payload,
                        'rendered' => $this->labelRenderer->render($payload),
                    ];
                }
            );

            $job = LabelJob::create([
                'job_uuid' => (string) Str::uuid(),
                'business_id' => $user->business_id,
                'label_type' => 'DEVICE',
                'format' => 'HTML',
                'template_version' => (string) config('recommerce.label_template_version', 'alpha-1'),
                'requested_by' => $user->id,
                // This proves the operator opened a batch print view. It is
                // not printer telemetry or a claim that every physical label
                // was attached.
                'status' => 'PRINT_VIEW_OPENED',
                'item_count' => count($labels),
                'request_json' => [
                    'reason' => 'BULK_PRINT',
                    'device_codes' => array_column($labels, 'device_code'),
                    'initial_issuance_count' => count(array_filter($labels, fn (array $label): bool => ! $label['reprint'])),
                    'reprint_count' => count(array_filter($labels, fn (array $label): bool => $label['reprint'])),
                ],
            ]);

            foreach ($labels as $ordinal => $label) {
                LabelJobItem::create([
                    'label_job_id' => $job->id,
                    'device_id' => $label['device_id'],
                    'scan_token_id' => $label['token_id'],
                    'ordinal' => $ordinal + 1,
                    'status' => 'PRINT_VIEW_OPENED',
                ]);
            }

            return [
                'job' => $job,
                'labels' => array_map(fn (array $label): array => [
                    'label' => $label['label'],
                    'rendered' => $label['rendered'],
                ], $labels),
            ];
        });
    }
}
