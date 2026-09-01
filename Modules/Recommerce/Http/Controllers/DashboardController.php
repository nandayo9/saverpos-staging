<?php

namespace Modules\Recommerce\Http\Controllers;

use App\User;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Entities\SerializationProfile;
use Modules\Recommerce\Support\AuthorizationGate;

class DashboardController extends Controller
{
    /**
     * Native Ultimate POS entry point for the Recommerce workflow.
     *
     * This page is deliberately a read-only navigation surface. It does not
     * turn the module/write feature flags into an implied approval to mutate
     * stock, issue labels, or create repair jobs.
     */
    public function index(AuthorizationGate $authorizationGate)
    {
        $user = auth()->user();
        $businessId = (int) $user->business_id;
        $locationId = (int) config('recommerce.cohort.location_id');

        if (! User::can_access_this_location($locationId, $businessId)) {
            abort(404);
        }

        $canViewDevices = $authorizationGate->allowsRead(
            $user,
            'recommerce.device.view',
            $businessId,
            $locationId
        );
        $canViewRepairs = $authorizationGate->allowsRead(
            $user,
            'recommerce.repair.view',
            $businessId,
            $locationId
        );
        $canReconcile = $authorizationGate->allowsRead(
            $user,
            'recommerce.stock.reconcile',
            $businessId,
            $locationId
        );
        $canReceive = $authorizationGate->allowsWriteLocation(
            $user,
            'recommerce.receiving.prepare',
            $businessId,
            $locationId
        );
        $canRepairIntake = $authorizationGate->allowsWriteLocation(
            $user,
            'recommerce.repair.intake',
            $businessId,
            $locationId
        );

        if (! $canViewDevices && ! $canViewRepairs && ! $canReconcile && ! $canReceive && ! $canRepairIntake) {
            abort(404);
        }

        $cohortVariationIds = array_values(array_filter(array_map('intval', (array) config('recommerce.cohort.variation_ids', []))));

        $deviceCounts = $canViewDevices
            ? Device::query()
                ->where('business_id', $businessId)
                ->where('current_location_id', $locationId)
                ->whereIn('variation_id', $cohortVariationIds)
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN stock_participation = 'ON_HAND' THEN 1 ELSE 0 END) as on_hand, SUM(CASE WHEN stock_participation = 'RESERVED' THEN 1 ELSE 0 END) as reserved, SUM(CASE WHEN lifecycle_state = 'RECEIVED_PENDING_INSPECTION' THEN 1 ELSE 0 END) as awaiting_inspection, SUM(CASE WHEN lifecycle_state = 'REFURBISHMENT_REQUIRED' THEN 1 ELSE 0 END) as repair_required, SUM(CASE WHEN lifecycle_state = 'AVAILABLE' AND stock_participation = 'ON_HAND' THEN 1 ELSE 0 END) as ready_for_sale, SUM(CASE WHEN acquired_at >= ? AND acquired_at < ? THEN 1 ELSE 0 END) as received_today", [now()->startOfDay(), now()->copy()->addDay()->startOfDay()])
                ->first()
            : null;

        $repairJobs = $canViewRepairs
            ? RepairJob::query()
                ->with('device')
                ->where('business_id', $businessId)
                ->where('location_id', $locationId)
                ->whereNotIn('state', ['CLOSED'])
                ->where(function ($query) use ($cohortVariationIds) {
                    $query->where('job_type', 'CUSTOMER_REPAIR')
                        ->orWhere(function ($internal) use ($cohortVariationIds) {
                            $internal->where('job_type', 'INTERNAL_REFURBISHMENT')
                                ->whereHas('device', fn ($device) => $device->whereIn('variation_id', $cohortVariationIds));
                        });
                })
                ->orderByRaw("CASE priority WHEN 'URGENT' THEN 1 WHEN 'HIGH' THEN 2 WHEN 'NORMAL' THEN 3 ELSE 4 END")
                ->orderBy('due_at')
                ->limit(8)
                ->get()
            : collect();

        $profiles = $canReconcile
            ? SerializationProfile::query()
                ->with(['product', 'variation'])
                ->where('business_id', $businessId)
                ->whereIn('variation_id', (array) config('recommerce.cohort.variation_ids', []))
                ->when(Schema::hasColumn('recommerce_serialization_profiles', 'inventory_tracking_mode'), fn ($query) => $query->where('inventory_tracking_mode', 'SERIALIZED_DEVICE'))
                ->orderBy('product_id')
                ->limit(12)
                ->get()
            : collect();

        return response()->view('recommerce::dashboard.index', compact(
            'locationId',
            'canViewDevices',
            'canViewRepairs',
            'canReconcile',
            'canReceive',
            'canRepairIntake',
            'deviceCounts',
            'repairJobs',
            'profiles'
        ))->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
