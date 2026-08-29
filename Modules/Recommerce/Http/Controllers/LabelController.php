<?php

namespace Modules\Recommerce\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\LabelJob;
use Modules\Recommerce\Entities\LabelJobItem;
use Modules\Recommerce\Services\LabelRenderer;
use Modules\Recommerce\Services\ScanTokenIssuanceService;
use Modules\Recommerce\Support\LabelPayloadBuilder;

class LabelController extends Controller
{
    public function issue(
        Request $request,
        int $deviceId,
        ScanTokenIssuanceService $tokenIssuanceService,
        LabelPayloadBuilder $labelPayloadBuilder
    ) {
        try {
            $payload = $this->issuePayload($request, $deviceId, $tokenIssuanceService, $labelPayloadBuilder);
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (InvalidArgumentException|LogicException $exception) {
            return response()->json([
                'message' => 'Label request was rejected.',
            ], 422)->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        }

        return response()->json([
            'status' => 'READY_TO_PRINT',
            'label' => $payload,
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Return a standalone print-ready HTML label. The initial print consumes
     * the one-time raw token returned by issuance inside this response; a
     * deliberate rotate request is required to render another QR label later.
     */
    public function print(
        Request $request,
        int $deviceId,
        ScanTokenIssuanceService $tokenIssuanceService,
        LabelPayloadBuilder $labelPayloadBuilder,
        LabelRenderer $labelRenderer
    ) {
        try {
            $user = auth()->user();
            $device = $this->deviceForUser($deviceId);
            $rotate = filter_var($request->input('rotate', false), FILTER_VALIDATE_BOOLEAN);
            $renderedResult = $tokenIssuanceService->issueAndRender(
                $user,
                $device,
                $rotate,
                function (array $issued, Device $lockedDevice) use ($labelPayloadBuilder, $labelRenderer, $rotate, $user): array {
                    $payload = $labelPayloadBuilder->forDevice(
                        $lockedDevice->fresh(['product']),
                        $issued['raw_token']
                    );

                    $rendered = $labelRenderer->render($payload);
                    $job = LabelJob::create([
                        'job_uuid' => (string) Str::uuid(),
                        'business_id' => $lockedDevice->business_id,
                        'label_type' => 'DEVICE',
                        'format' => 'HTML',
                        'template_version' => (string) config('recommerce.label_template_version', 'alpha-1'),
                        'requested_by' => $user->id,
                        'status' => 'READY_TO_PRINT',
                        'item_count' => 1,
                        'request_json' => [
                            'device_id' => (int) $lockedDevice->id,
                            'device_code' => $lockedDevice->device_code,
                            'rotate' => $rotate,
                            'reason' => $rotate ? 'ROTATION_REPRINT' : 'INITIAL_PRINT',
                        ],
                    ]);
                    LabelJobItem::create([
                        'label_job_id' => $job->id,
                        'device_id' => $lockedDevice->id,
                        'scan_token_id' => $issued['token_id'],
                        'ordinal' => 1,
                        'status' => 'READY_TO_PRINT',
                    ]);

                    return [
                        'payload' => $payload,
                        'rendered' => $rendered,
                        'label_job_uuid' => $job->job_uuid,
                    ];
                }
            );

            if (! isset($renderedResult['payload'], $renderedResult['rendered'])
                || ! is_array($renderedResult['payload'])
                || ! is_array($renderedResult['rendered'])) {
                throw new LogicException('Label renderer returned an invalid result.');
            }

            $payload = $renderedResult['payload'];
            $rendered = $renderedResult['rendered'];
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (InvalidArgumentException|LogicException $exception) {
            return response()->json([
                'message' => 'Label request was rejected.',
            ], 422)->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        }

        return response()->view('recommerce::labels.device', [
            'label' => $payload,
            'rendered' => $rendered,
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    protected function issuePayload(
        Request $request,
        int $deviceId,
        ScanTokenIssuanceService $tokenIssuanceService,
        LabelPayloadBuilder $labelPayloadBuilder
    ): array {
        $user = auth()->user();
        $device = $this->deviceForUser($deviceId);
        $rotate = filter_var($request->input('rotate', false), FILTER_VALIDATE_BOOLEAN);
        $preparedResult = $tokenIssuanceService->issueAndPrepare(
            $user,
            $device,
            $rotate,
            function (array $issued, Device $lockedDevice) use ($labelPayloadBuilder): array {
                return [
                    'payload' => $labelPayloadBuilder->forDevice(
                        $lockedDevice->fresh(['product']),
                        $issued['raw_token']
                    ),
                ];
            }
        );

        if (! isset($preparedResult['payload']) || ! is_array($preparedResult['payload'])) {
            throw new LogicException('Label payload builder returned an invalid result.');
        }

        return $preparedResult['payload'];
    }

    protected function deviceForUser(int $deviceId): Device
    {
        return Device::query()
            ->where('business_id', auth()->user()->business_id)
            ->where('id', $deviceId)
            ->firstOrFail();
    }
}
