<?php

namespace Modules\Recommerce\Http\Controllers;

use App\BusinessLocation;
use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use LogicException;
use Modules\Recommerce\Services\ReconciliationRunService;
use Modules\Recommerce\Services\StockReconciliationService;
use Modules\Recommerce\Entities\SerializationProfile;
use Modules\Recommerce\Support\AuthorizationGate;

class ReconciliationController extends Controller
{
    /**
     * Native POS index for the existing read-only per-variation comparison.
     * Values are fetched from the guarded endpoint only after an operator
     * selects a configured serialization profile.
     */
    public function index(Request $request, AuthorizationGate $authorizationGate)
    {
        $user = auth()->user();
        $businessId = (int) $user->business_id;
        $configuredLocationIds = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) config('recommerce.cohort.location_ids', [config('recommerce.cohort.location_id')])
        ))));
        $locationId = (int) $request->query('location_id', config('recommerce.cohort.location_id'));
        if (! in_array($locationId, $configuredLocationIds, true)) {
            abort(404);
        }

        if (! User::can_access_this_location($locationId, $businessId)
            || ! $authorizationGate->allowsRead($user, 'recommerce.stock.reconcile', $businessId, $locationId)) {
            abort(404);
        }

        $profiles = SerializationProfile::query()
            ->with(['product', 'variation'])
            ->where('business_id', $businessId)
            ->whereIn('variation_id', (array) config('recommerce.cohort.variation_ids', []))
            ->orderBy('product_id')
            ->get()
            ->filter(fn (SerializationProfile $profile) => $authorizationGate->allowsRead(
                $user,
                'recommerce.stock.reconcile',
                $businessId,
                $locationId,
                $profile->variation_id
            ))
            ->values();

        $locations = BusinessLocation::query()
            ->where('business_id', $businessId)
            ->whereIn('id', $configuredLocationIds)
            ->orderBy('name')
            ->pluck('name', 'id');

        return response()->view('recommerce::reconciliation.index', compact('profiles', 'locationId', 'locations'))
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function show(
        Request $request,
        int $variationId,
        StockReconciliationService $reconciliationService
    ) {
        if ($request->has('approved_legacy_balance')) {
            return response()->json([
                'message' => 'Reconciliation request was rejected.',
            ], 422)->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        }

        $validator = Validator::make($request->all(), [
            'location_id' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Reconciliation request was rejected.',
            ], 422)->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        }

        $validated = $validator->validated();

        try {
            $result = $reconciliationService->forVariation(
                auth()->user(),
                (int) auth()->user()->business_id,
                (int) $validated['location_id'],
                $variationId
            );
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (InvalidArgumentException|LogicException $exception) {
            return response()->json([
                'message' => 'Reconciliation request was rejected.',
            ], 422)->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        }

        return response()->json($result)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function store(
        Request $request,
        int $variationId,
        ReconciliationRunService $runService
    ) {
        if ($request->has('approved_legacy_balance')) {
            return $this->rejectedResponse();
        }

        $validator = Validator::make($request->all(), [
            'location_id' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->rejectedResponse();
        }

        try {
            $result = $runService->record(
                auth()->user(),
                (int) auth()->user()->business_id,
                (int) $validator->validated()['location_id'],
                $variationId
            );
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (InvalidArgumentException|LogicException $exception) {
            return $this->rejectedResponse();
        }

        return response()->json($result, 201)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    protected function rejectedResponse()
    {
        return response()->json([
            'message' => 'Reconciliation request was rejected.',
        ], 422)->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
