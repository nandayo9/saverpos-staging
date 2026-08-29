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
use Modules\Recommerce\Services\TrackedReceivingService;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\Identity\StrongIdentifierHasher;

class ReceivingController extends Controller
{
    public function index(AuthorizationGate $authorizationGate, ?Request $request = null)
    {
        $request = $request ?: request();
        $user = auth()->user();
        $businessId = $user->business_id;
        $locationId = config('recommerce.cohort.location_id');
        $variationId = collect(config('recommerce.cohort.variation_ids', []))->first();

        if (! is_numeric($locationId)
            || ! is_numeric($variationId)
            || ! User::can_access_this_location((int) $locationId, $businessId)
            || ! $authorizationGate->allowsRead(
                $user,
                'recommerce.receiving.prepare',
                $businessId,
                (int) $locationId,
                (int) $variationId
            )) {
            abort(404);
        }

        $variation = Variation::query()
            ->with('product')
            ->where('id', (int) $variationId)
            ->whereHas('product', function ($query) use ($businessId) {
                $query->where('business_id', $businessId);
            })
            ->first();

        if (! $variation || ! $variation->product) {
            abort(404);
        }

        $purchaseContext = $this->receivedPurchaseContext($request, $authorizationGate, $user, (int) $businessId, (int) $locationId);
        if (! empty($purchaseContext['selected_line'])) {
            $selectedLine = $purchaseContext['selected_line'];
            $variation = Variation::query()
                ->with('product')
                ->where('id', $selectedLine->variation_id)
                ->where('product_id', $selectedLine->product_id)
                ->whereHas('product', function ($query) use ($businessId) {
                    $query->where('business_id', $businessId);
                })
                ->first();

            if (! $variation || ! $variation->product) {
                abort(404);
            }
        }

        $postEnabled = $authorizationGate->allowsWrite(
            $user,
            'recommerce.receiving.post',
            $businessId,
            (int) $locationId,
            (int) $variation->id
        );

        $reconciliationRecordEnabled = $authorizationGate->allowsWrite(
            $user,
            'recommerce.stock.reconcile.record',
            $businessId,
            (int) $locationId,
            (int) $variation->id
        );

        return response()->view('recommerce::receiving.index', [
            'businessId' => (int) $businessId,
            'locationId' => (int) $locationId,
            'variation' => $variation,
            'purchaseContext' => $purchaseContext,
            'postEnabled' => $postEnabled,
            'reconciliationRecordEnabled' => $reconciliationRecordEnabled,
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Build a scoped handoff from the native Purchase list. Only unassigned,
     * whole-unit received lines in the approved cohort are selectable.
     */
    protected function receivedPurchaseContext(
        Request $request,
        AuthorizationGate $authorizationGate,
        User $user,
        int $businessId,
        int $locationId
    ): ?array {
        $purchaseId = (int) $request->query('purchase_id', 0);
        if ($purchaseId < 1) {
            return null;
        }

        $purchase = DB::table('transactions')
            ->where('id', $purchaseId)
            ->where('business_id', $businessId)
            ->where('location_id', $locationId)
            ->where('type', 'purchase')
            ->where('status', 'received')
            ->first(['id', 'ref_no', 'invoice_no', 'transaction_date']);
        if (! $purchase) {
            abort(404);
        }

        $cohortVariationIds = array_values(array_filter(array_map('intval', (array) config('recommerce.cohort.variation_ids', []))));
        $lines = DB::table('purchase_lines')
            ->join('products', 'products.id', '=', 'purchase_lines.product_id')
            ->where('purchase_lines.transaction_id', $purchase->id)
            ->whereIn('purchase_lines.variation_id', $cohortVariationIds)
            ->select([
                'purchase_lines.id',
                'purchase_lines.transaction_id',
                'purchase_lines.product_id',
                'purchase_lines.variation_id',
                'purchase_lines.quantity',
                'products.name as product_name',
            ])
            ->orderBy('purchase_lines.id')
            ->get()
            ->filter(function ($line) use ($authorizationGate, $user, $businessId, $locationId) {
                $isWholeUnit = (float) $line->quantity > 0
                    && abs((float) $line->quantity - round((float) $line->quantity)) <= 0.000001;
                $assignmentCount = DB::table('recommerce_device_purchase_assignments')
                    ->where('business_id', $businessId)
                    ->where('transaction_id', $line->transaction_id)
                    ->where('purchase_line_id', $line->id)
                    ->count();
                $line->assigned_count = $assignmentCount;
                $line->remaining_unit_count = max(0, (int) round((float) $line->quantity) - $assignmentCount);

                return $isWholeUnit
                    && $line->remaining_unit_count > 0
                    && $authorizationGate->allowsRead(
                        $user,
                        'recommerce.receiving.prepare',
                        $businessId,
                        $locationId,
                        (int) $line->variation_id
                    );
            })
            ->values();

        $selectedLineId = (int) $request->query('purchase_line_id', 0);
        $selectedLine = $selectedLineId > 0
            ? $lines->firstWhere('id', $selectedLineId)
            : ($lines->count() === 1 ? $lines->first() : null);
        if ($selectedLineId > 0 && ! $selectedLine) {
            abort(404);
        }

        return [
            'purchase' => $purchase,
            'lines' => $lines,
            'selected_line' => $selectedLine,
        ];
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
                'message' => 'Purchase attachment was rejected.',
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
}
