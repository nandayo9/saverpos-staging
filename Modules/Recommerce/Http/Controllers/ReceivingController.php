<?php

namespace Modules\Recommerce\Http\Controllers;

use App\Product;
use App\User;
use App\Variation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Modules\Recommerce\Exceptions\ReceivingInProgressException;
use Modules\Recommerce\Exceptions\ReceivingReconciliationBlockedException;
use Modules\Recommerce\Services\DeviceReceivingProgressService;
use Modules\Recommerce\Services\TrackedReceivingService;
use Modules\Recommerce\Entities\DeviceIdentifier;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\Identity\StrongIdentifierHasher;

class ReceivingController extends Controller
{
    public function index(
        AuthorizationGate $authorizationGate,
        ?DeviceReceivingProgressService $progressService = null,
        ?Request $request = null
    )
    {
        $request = $request ?: request();
        $progressService = $progressService ?: app(DeviceReceivingProgressService::class);
        $user = auth()->user();
        $businessId = (int) $user->business_id;
        $purchaseContext = $this->receivedPurchaseContext($request, $authorizationGate, $user, $businessId, $progressService);
        $selectedLine = $purchaseContext['selected_line'] ?? null;
        $locationId = $purchaseContext['purchase']->location_id ?? config('recommerce.cohort.location_id');

        if (! is_numeric($locationId) || ! User::can_access_this_location((int) $locationId, $businessId)) {
            abort(404);
        }

        $postEnabled = $selectedLine !== null && $authorizationGate->allowsWrite(
            $user,
            'recommerce.receiving.post',
            $businessId,
            (int) $locationId,
            (int) $selectedLine->variation_id
        );
        $canOverrideCost = $selectedLine !== null && $authorizationGate->allowsWrite(
            $user,
            'recommerce.device.override_acquisition_cost',
            $businessId,
            (int) $locationId,
            (int) $selectedLine->variation_id
        );
        $canViewInspection = $authorizationGate->allowsRead(
            $user,
            'recommerce.inspection.view',
            $businessId,
            (int) $locationId
        );
        $registeredDevices = $selectedLine === null ? collect() : DB::table('recommerce_device_purchase_assignments as dpa')
            ->join('recommerce_devices as d', 'd.id', '=', 'dpa.device_id')
            ->where('dpa.business_id', $businessId)
            ->where('dpa.purchase_line_id', $selectedLine->id)
            ->orderBy('dpa.unit_ordinal')
            ->get(['d.id as device_id', 'dpa.unit_ordinal', 'dpa.unit_acquisition_cost', 'd.device_code', 'd.lifecycle_state']);

        return response()->view('recommerce::receiving.index', [
            'businessId' => (int) $businessId,
            'locationId' => (int) $locationId,
            'purchaseContext' => $purchaseContext,
            'postEnabled' => $postEnabled,
            'canOverrideCost' => $canOverrideCost,
            'canViewInspection' => $canViewInspection,
            // Retained for integrations that render this workspace through an
            // existing response decorator. Reconciliation is linked from the
            // purchase-led screen; it is not a receiving prerequisite.
            'reconciliationRecordEnabled' => false,
            'registeredDevices' => $registeredDevices,
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /** Product policy decides which purchase lines need Device registration. */
    protected function receivedPurchaseContext(
        Request $request,
        AuthorizationGate $authorizationGate,
        User $user,
        int $businessId,
        DeviceReceivingProgressService $progressService
    ): array {
        $purchaseId = (int) $request->query('purchase_id', 0);
        if ($purchaseId < 1) {
            return [
                'purchase' => null,
                'lines' => collect(),
                'selected_line' => null,
                'expected_count' => 0,
                'registered_count' => 0,
                'remaining_count' => 0,
            ];
        }

        $context = $progressService->forPurchase($businessId, $purchaseId);
        if (! $context || ! User::can_access_this_location((int) $context['purchase']->location_id, $businessId)) {
            abort(404);
        }

        $locationId = (int) $context['purchase']->location_id;
        $context['lines'] = collect($context['lines'])->filter(function ($line) use ($authorizationGate, $user, $businessId, $locationId) {
            return $line->tracking_mode !== DeviceReceivingProgressService::TRACKING_SERIALIZED_DEVICE
                || $authorizationGate->allowsRead($user, 'recommerce.receiving.prepare', $businessId, $locationId, (int) $line->variation_id);
        })->values();

        $visibleSerializedLines = $context['lines']->where('tracking_mode', DeviceReceivingProgressService::TRACKING_SERIALIZED_DEVICE);
        foreach (['expected_count', 'registered_count', 'remaining_count', 'inspection_cleared_count', 'inspection_open_count', 'inspection_failed_count', 'label_view_opened_count', 'label_confirmed_count', 'label_remaining_count'] as $field) {
            $context[$field] = (int) $visibleSerializedLines->sum($field);
        }
        $context['serialized_line_count'] = $visibleSerializedLines->count();

        $selectedLineId = (int) $request->query('purchase_line_id', 0);
        $serializedLines = $visibleSerializedLines;
        $selectedLine = $selectedLineId > 0
            ? $serializedLines->firstWhere('id', $selectedLineId)
            : ($serializedLines->count() === 1 ? $serializedLines->first() : $serializedLines->firstWhere('remaining_count', '>', 0));
        if ($selectedLineId > 0 && ! $selectedLine) {
            abort(404);
        }

        $context['selected_line'] = $selectedLine;

        return $context;
    }

    public function prepare(Request $request, AuthorizationGate $authorizationGate)
    {
        try {
            $validated = $request->validate([
                'location_id' => ['required', 'integer', 'min:1'],
                'product_id' => ['required', 'integer', 'min:1'],
                'variation_id' => ['required', 'integer', 'min:1'],
                'units' => ['required', 'array', 'min:1', 'max:'.(int) config('recommerce.receive_batch_limit', 50)],
                'units.*.identifier_type' => ['required', 'string', 'regex:/^[A-Z0-9_]{1,40}$/'],
                'units.*.identifier_value' => ['required', 'string', 'max:255'],
                'units.*.unit_acquisition_cost' => ['nullable', 'numeric', 'min:0'],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'Receiving request was rejected.',
            ], 422)->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        }

        $user = auth()->user();
        $businessId = $user->business_id;

        if (! User::can_access_this_location($validated['location_id'], $businessId)
            || ! $authorizationGate->allowsRead(
                $user,
                'recommerce.receiving.prepare',
                $businessId,
                $validated['location_id'],
                $validated['variation_id']
            )) {
            abort(404);
        }

        $variation = Variation::query()
            ->where('id', $validated['variation_id'])
            ->where('product_id', $validated['product_id'])
            ->whereHas('product', function ($query) use ($businessId) {
                $query->where('business_id', $businessId);
            })
            ->first();

        if (! $variation || ! Product::query()->where('id', $validated['product_id'])->where('business_id', $businessId)->exists()) {
            abort(404);
        }

        $identifierHashes = [];
        $hints = [];
        try {
            foreach ($validated['units'] as $unit) {
                $normalized = StrongIdentifierHasher::normalize($unit['identifier_value']);
                $hash = StrongIdentifierHasher::hash($normalized);
                $identifierKey = $unit['identifier_type'].'|'.$hash;

                if (isset($identifierHashes[$identifierKey])) {
                    return response()->json([
                        'message' => 'Receiving batch contains a duplicate identifier.',
                    ], 422)->header('Cache-Control', 'no-store')
                        ->header('Referrer-Policy', 'no-referrer');
                }

                $identifierHashes[$identifierKey] = true;
                $existing = DeviceIdentifier::query()
                    ->with(['device.product'])
                    ->where('business_id', $businessId)
                    ->where('identifier_type', $unit['identifier_type'])
                    ->where('normalized_hash', $hash)
                    ->first();
                if ($existing) {
                    $device = $existing->device;
                    $safeDevice = $device
                        && $device->current_location_id
                        && $authorizationGate->allowsRead(
                            $user,
                            'recommerce.device.view',
                            $businessId,
                            (int) $device->current_location_id,
                            (int) $device->variation_id
                        );

                    return response()->json([
                        'message' => 'Identifier already exists. Remove the scan or resolve the supplier discrepancy before continuing.',
                        'exception' => array_filter([
                            'type' => 'DUPLICATE_IDENTIFIER',
                            'device_code' => $safeDevice ? $device->device_code : null,
                            'model' => $safeDevice ? optional($device->product)->name : null,
                            'lifecycle_state' => $safeDevice ? $device->lifecycle_state : null,
                            'device_url' => $safeDevice ? route('recommerce.devices.show', $device->device_code) : null,
                        ], static fn ($value) => $value !== null),
                    ], 409)->header('Cache-Control', 'no-store')
                        ->header('Referrer-Policy', 'no-referrer');
                }
                $hints[] = [
                    'identifier_type' => $unit['identifier_type'],
                    'identifier_hint' => $this->identifierHint($normalized),
                ];
            }
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => 'Receiving batch contains an invalid identifier.',
            ], 422)->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        }

        $postUrl = null;
        if ($authorizationGate->allowsWrite(
            $user,
            'recommerce.receiving.post',
            $businessId,
            $validated['location_id'],
            $validated['variation_id']
        )) {
            $postUrl = route('recommerce.receiving.post');
        }

        return response()->json([
            'status' => 'PREPARED_NO_WRITE',
            'business_id' => $businessId,
            'location_id' => (int) $validated['location_id'],
            'product_id' => (int) $validated['product_id'],
            'variation_id' => (int) $validated['variation_id'],
            'unit_count' => count($hints),
            'identifiers' => $hints,
            'post_url' => $postUrl,
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    protected function identifierHint(string $normalized): string
    {
        $length = strlen($normalized);

        if ($length <= 2) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', min(6, $length - 2)).substr($normalized, -2);
    }

    public function post(Request $request, TrackedReceivingService $trackedReceivingService)
    {
        try {
            $validated = $request->validate([
                'command_uuid' => ['required', 'uuid'],
                'location_id' => ['required', 'integer', 'min:1'],
                'product_id' => ['required', 'integer', 'min:1'],
                'variation_id' => ['required', 'integer', 'min:1'],
                'category_code' => ['nullable', 'string', 'max:32'],
                'purchase' => ['required', 'array'],
                'purchase.contact_id' => ['required', 'integer', 'min:1'],
                'purchase.transaction_date' => ['required', 'string', 'max:80'],
                'purchase.unit_purchase_price' => ['required', 'numeric', 'min:0'],
                'purchase.unit_purchase_price_inc_tax' => ['required', 'numeric', 'min:0'],
                'purchase.unit_item_tax' => ['required', 'numeric', 'min:0'],
                'purchase.tax_id' => ['nullable', 'integer', 'min:1'],
                'purchase.shipping_charges' => ['nullable', 'numeric', 'min:0'],
                'purchase.additional_notes' => ['nullable', 'string', 'max:2000'],
                'units' => ['required', 'array', 'min:1', 'max:'.(int) config('recommerce.receive_batch_limit', 50)],
                'units.*.identifier_type' => ['required', 'string', 'regex:/^[A-Z0-9_]{1,40}$/'],
                'units.*.identifier_value' => ['required', 'string', 'max:255'],
                'units.*.unit_acquisition_cost' => ['nullable', 'numeric', 'min:0'],
                'units.*.cost_override_reason_code' => ['nullable', 'string', 'max:48'],
                'units.*.cost_override_reason_notes' => ['nullable', 'string', 'max:2000'],
                'units.*.intake_observations' => ['nullable', 'array', 'max:5'],
                'units.*.intake_observations.*.type' => ['required_with:units.*.intake_observations', 'string', 'max:48'],
                'units.*.intake_observations.*.notes' => ['nullable', 'string', 'max:2000'],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'Receiving request was rejected.',
            ], 422)->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        }

        $validated['business_id'] = auth()->user()->business_id;

        try {
            $result = $trackedReceivingService->executeWithUltimatePosPurchase(
                auth()->user(),
                $validated
            );
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (InvalidArgumentException|LogicException $exception) {
            return response()->json([
                'message' => 'Receiving command was rejected.',
            ], 422)->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        } catch (ReceivingReconciliationBlockedException $exception) {
            return response()->json([
                'message' => 'Receiving is blocked until reconciliation is resolved.',
            ], 409)->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        } catch (ReceivingInProgressException $exception) {
            return response()->json([
                'message' => 'Receiving command is already being processed.',
            ], 409)->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        }

        return response()->json([
            'status' => 'RECEIVED_TRACKED',
            'result' => $result,
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Attach device identity to a received POS purchase. This endpoint never
     * creates a second purchase or changes core stock, payments, or accounts.
     */
    public function attachPurchase(Request $request, TrackedReceivingService $trackedReceivingService)
    {
        try {
            $validated = $request->validate([
                'command_uuid' => ['required', 'uuid'],
                'location_id' => ['required', 'integer', 'min:1'],
                'product_id' => ['required', 'integer', 'min:1'],
                'variation_id' => ['required', 'integer', 'min:1'],
                'purchase_transaction_id' => ['required', 'integer', 'min:1'],
                'purchase_line_id' => ['required', 'integer', 'min:1'],
                'category_code' => ['nullable', 'string', 'max:32'],
                'units' => ['required', 'array', 'min:1', 'max:'.(int) config('recommerce.receive_batch_limit', 50)],
                'units.*.identifier_type' => ['required', 'string', 'regex:/^[A-Z0-9_]{1,40}$/'],
                'units.*.identifier_value' => ['required', 'string', 'max:255'],
                'units.*.unit_acquisition_cost' => ['nullable', 'numeric', 'min:0'],
                'units.*.cost_override_reason_code' => ['nullable', 'string', 'max:48'],
                'units.*.cost_override_reason_notes' => ['nullable', 'string', 'max:2000'],
                'units.*.intake_observations' => ['nullable', 'array', 'max:5'],
                'units.*.intake_observations.*.type' => ['required_with:units.*.intake_observations', 'string', 'max:48'],
                'units.*.intake_observations.*.notes' => ['nullable', 'string', 'max:2000'],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'Purchase attachment request was rejected.',
            ], 422)->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        }

        $validated['business_id'] = auth()->user()->business_id;

        try {
            $result = $trackedReceivingService->attachToExistingUltimatePosPurchase(
                auth()->user(),
                $validated
            );
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (InvalidArgumentException|LogicException $exception) {
            return response()->json([
                'message' => $this->safeAttachmentMessage($exception),
            ], 422)->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        } catch (ReceivingInProgressException $exception) {
            return response()->json([
                'message' => 'Purchase attachment is already being processed.',
            ], 409)->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        }

        return response()->json([
            'status' => 'PURCHASE_SERIALISED',
            'result' => $result,
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    protected function safeAttachmentMessage(\Throwable $exception): string
    {
        $message = $exception->getMessage();
        $messages = [
            'The supplied Device count exceeds the unassigned units on the selected POS purchase line.'
                => 'This batch is larger than the number of devices still needing identification. Refresh to see the latest progress.',
            'Receiving command contains an identifier already registered to a Device.'
                => 'This identifier is already registered to a Device.',
            'Purchase attachment contains an identifier already registered to a Device.'
                => 'This identifier is already registered to a Device.',
            'Purchase attachment contains a duplicate identifier.'
                => 'This identifier appears more than once in the current batch.',
            'The selected POS purchase line is not an eligible received stock line.'
                => 'This purchase line is not ready for device identification. Refresh the purchase and check its receiving status.',
            'The selected purchase line does not require Device registration.'
                => 'This product does not require individual device identification.',
            'Idempotency key was reused for a different request.'
                => 'This receiving request changed while it was being retried. Refresh and try again.',
            'An acquisition-cost override requires a reason.'
                => 'Choose a reason for the acquisition-cost change.',
            'Other acquisition-cost overrides require notes.'
                => 'Add a note explaining the acquisition-cost change.',
        ];

        return $messages[$message] ?? 'This batch could not be registered. Refresh the purchase and try again.';
    }
}
