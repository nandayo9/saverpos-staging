<?php

namespace Modules\Recommerce\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Recommerce\Entities\SerializationProfile;

/**
 * Purchase-led read model for Device receiving.
 *
 * UltimatePOS remains the commercial and aggregate-stock authority. This
 * service only determines which already-received purchase units require a
 * canonical Recommerce Device Passport and how far that work has progressed.
 */
class DeviceReceivingProgressService
{
    public const TRACKING_BULK = 'BULK';

    public const TRACKING_SERIALIZED_DEVICE = 'SERIALIZED_DEVICE';

    public function trackingMode(?SerializationProfile $profile): string
    {
        if (! $profile || ! in_array($profile->mode, ['TRACKED_REQUIRED', 'LEGACY_MIXED'], true)) {
            return self::TRACKING_BULK;
        }

        // Profiles created before the intake-policy migration are serialized
        // by definition. This keeps the migration additive and safe.
        return strtoupper((string) ($profile->inventory_tracking_mode ?: self::TRACKING_SERIALIZED_DEVICE))
            === self::TRACKING_BULK
            ? self::TRACKING_BULK
            : self::TRACKING_SERIALIZED_DEVICE;
    }

    public function inspectionRequired(?SerializationProfile $profile): bool
    {
        return $profile === null || $profile->inspection_required === null
            ? true
            : (bool) $profile->inspection_required;
    }

    public function profileFor(int $businessId, int $productId, int $variationId): ?SerializationProfile
    {
        return SerializationProfile::query()
            ->where('business_id', $businessId)
            ->where('product_id', $productId)
            ->where('variation_id', $variationId)
            ->first();
    }

    public function serializedPolicyFor(int $businessId, int $productId, int $variationId): ?SerializationProfile
    {
        $profile = $this->profileFor($businessId, $productId, $variationId);

        return $this->trackingMode($profile) === self::TRACKING_SERIALIZED_DEVICE ? $profile : null;
    }

    /**
     * Once a physical Device points at a purchase, native purchase mutation
     * would rewrite or cascade-delete its provenance. Corrections therefore
     * need a deliberate, permissioned Device workflow rather than an ordinary
     * purchase edit/status/delete action.
     */
    public function assertPurchaseMayBeChanged(int $businessId, int $purchaseId, string $operation = 'change'): void
    {
        if (! Schema::hasTable('recommerce_device_purchase_assignments')) {
            return;
        }

        $hasIdentifiedDevices = DB::table('recommerce_device_purchase_assignments')
            ->where('business_id', $businessId)
            ->where('transaction_id', $purchaseId)
            ->exists();

        if ($hasIdentifiedDevices) {
            $message = match ($operation) {
                'delete' => 'This purchase cannot be deleted because identified devices depend on it. Open the receiving record to review those devices.',
                'status' => 'This purchase status cannot change because devices have already been identified from it. Open the receiving record and contact an administrator if a documented correction is required.',
                'return' => 'This purchase cannot use the ordinary return screen because identified devices depend on its received quantity. Open the receiving record to review them, then use a device-aware return process or contact an administrator for a documented correction.',
                default => 'This purchase cannot be edited because this UltimatePOS screen saves purchase details and stock lines together. Devices have already been identified from those lines. Open the receiving record and contact an administrator if a documented correction is required.',
            };

            throw new LogicException($message);
        }
    }

    /**
     * Returns a purchase and all of its lines. Lines without an approved
     * serialized-device policy are deliberately represented as BULK, rather
     * than requiring staff to make a tracking decision at receipt time.
     */
    public function forPurchase(int $businessId, int $purchaseId): ?array
    {
        $purchase = DB::table('transactions as t')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoin('business_locations as l', 'l.id', '=', 't.location_id')
            ->where('t.id', $purchaseId)
            ->where('t.business_id', $businessId)
            ->where('t.type', 'purchase')
            ->where('t.status', 'received')
            ->first([
                't.id', 't.business_id', 't.location_id', 't.ref_no', 't.invoice_no', 't.transaction_date',
                'c.name as supplier_name', 'c.supplier_business_name', 'l.name as location_name',
            ]);

        if (! $purchase) {
            return null;
        }

        $profiles = SerializationProfile::query()
            ->where('business_id', $businessId)
            ->get()
            ->keyBy(fn (SerializationProfile $profile): string => $profile->product_id.':'.$profile->variation_id);

        $inspectionCounts = collect();
        if (Schema::hasTable('recommerce_device_inspections')) {
            $inspectionCounts = DB::table('recommerce_device_purchase_assignments as dpa')
                ->join('recommerce_devices as d', 'd.id', '=', 'dpa.device_id')
                ->leftJoin('recommerce_device_inspections as di', 'di.device_id', '=', 'd.id')
                ->where('dpa.business_id', $businessId)
                ->where('dpa.transaction_id', $purchase->id)
                ->selectRaw("dpa.purchase_line_id, SUM(CASE WHEN d.lifecycle_state = 'AVAILABLE' THEN 1 ELSE 0 END) as inspection_cleared_count, SUM(CASE WHEN di.status IN ('PENDING', 'ASSIGNED', 'IN_INSPECTION') THEN 1 ELSE 0 END) as inspection_open_count, SUM(CASE WHEN di.status = 'FAILED' THEN 1 ELSE 0 END) as inspection_failed_count")
                ->groupBy('dpa.purchase_line_id')->get()->keyBy('purchase_line_id');
        }

        // Label evidence is operational only. Registration stays complete
        // even when a printer is unavailable or an operator has not yet
        // confirmed the physical label was attached.
        $labelCounts = collect();
        if (Schema::hasTable('recommerce_label_job_items') && Schema::hasTable('recommerce_label_jobs')) {
            $labelCounts = DB::table('recommerce_device_purchase_assignments as dpa')
                ->join('recommerce_label_job_items as lji', 'lji.device_id', '=', 'dpa.device_id')
                ->join('recommerce_label_jobs as lj', 'lj.id', '=', 'lji.label_job_id')
                ->where('dpa.business_id', $businessId)
                ->where('dpa.transaction_id', $purchase->id)
                ->selectRaw("dpa.purchase_line_id, COUNT(DISTINCT lji.device_id) as label_view_opened_count, COUNT(DISTINCT CASE WHEN lj.status IN ('PRINT_CONFIRMED', 'REPRINT_CONFIRMED') THEN lji.device_id END) as label_confirmed_count")
                ->groupBy('dpa.purchase_line_id')->get()->keyBy('purchase_line_id');
        }

        $lines = DB::table('purchase_lines as pl')
            ->join('products as p', 'p.id', '=', 'pl.product_id')
            ->leftJoin('variations as v', 'v.id', '=', 'pl.variation_id')
            ->leftJoin('recommerce_device_purchase_assignments as dpa', function ($join) use ($businessId) {
                $join->on('dpa.purchase_line_id', '=', 'pl.id')
                    ->where('dpa.business_id', '=', $businessId);
            })
            ->where('pl.transaction_id', $purchase->id)
            ->selectRaw('pl.id, pl.transaction_id, pl.product_id, pl.variation_id, pl.quantity, pl.purchase_price_inc_tax, p.name as product_name, v.name as variation_name, COUNT(dpa.id) as registered_count')
            ->groupBy('pl.id', 'pl.transaction_id', 'pl.product_id', 'pl.variation_id', 'pl.quantity', 'pl.purchase_price_inc_tax', 'p.name', 'v.name')
            ->orderBy('pl.id')
            ->get()
            ->map(function ($line) use ($profiles, $inspectionCounts, $labelCounts) {
                $profile = $profiles->get($line->product_id.':'.$line->variation_id);
                $trackingMode = $this->trackingMode($profile);
                $quantity = (float) $line->quantity;
                $isWholeUnit = $quantity > 0 && abs($quantity - round($quantity)) <= 0.000001;

                $line->tracking_mode = $trackingMode;
                $line->inspection_required = $trackingMode === self::TRACKING_SERIALIZED_DEVICE
                    ? $this->inspectionRequired($profile)
                    : false;
                $line->expected_count = $trackingMode === self::TRACKING_SERIALIZED_DEVICE && $isWholeUnit
                    ? (int) round($quantity)
                    : 0;
                $line->registered_count = (int) $line->registered_count;
                $line->remaining_count = max(0, $line->expected_count - $line->registered_count);
                $line->is_whole_unit = $isWholeUnit;
                $line->profile_id = $profile?->id;
                $line->default_unit_acquisition_cost = $line->purchase_price_inc_tax === null
                    ? null
                    : (float) $line->purchase_price_inc_tax;
                $inspection = $inspectionCounts->get($line->id);
                $line->inspection_cleared_count = (int) ($inspection->inspection_cleared_count ?? 0);
                $line->inspection_open_count = (int) ($inspection->inspection_open_count ?? 0);
                $line->inspection_failed_count = (int) ($inspection->inspection_failed_count ?? 0);
                $labels = $labelCounts->get($line->id);
                $line->label_view_opened_count = (int) ($labels->label_view_opened_count ?? 0);
                $line->label_confirmed_count = (int) ($labels->label_confirmed_count ?? 0);
                $line->label_remaining_count = max(0, $line->registered_count - $line->label_confirmed_count);

                return $line;
            })
            ->values();

        $serializedLines = $lines->where('tracking_mode', self::TRACKING_SERIALIZED_DEVICE);

        return [
            'purchase' => $purchase,
            'lines' => $lines,
            'serialized_line_count' => $serializedLines->count(),
            'expected_count' => (int) $serializedLines->sum('expected_count'),
            'registered_count' => (int) $serializedLines->sum('registered_count'),
            'remaining_count' => (int) $serializedLines->sum('remaining_count'),
            'inspection_cleared_count' => (int) $serializedLines->sum('inspection_cleared_count'),
            'inspection_open_count' => (int) $serializedLines->sum('inspection_open_count'),
            'inspection_failed_count' => (int) $serializedLines->sum('inspection_failed_count'),
            'label_view_opened_count' => (int) $serializedLines->sum('label_view_opened_count'),
            'label_confirmed_count' => (int) $serializedLines->sum('label_confirmed_count'),
            'label_remaining_count' => (int) $serializedLines->sum('label_remaining_count'),
        ];
    }
}
