<?php

namespace Modules\Recommerce\Services;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\LegacyStockBalance;
use Modules\Recommerce\Entities\SerializationProfile;
use Modules\Recommerce\Exceptions\ReceivingReconciliationBlockedException;
use Modules\Recommerce\Support\AuthorizationGate;

class StockReconciliationService
{
    public function __construct(protected AuthorizationGate $authorizationGate)
    {
    }

    /**
     * Read-only comparison of Ultimate POS aggregate quantity with tracked
     * Devices plus an approved, persisted legacy balance.
     */
    public function forVariation(
        User $user,
        int $businessId,
        int $locationId,
        int $variationId
    ): array {
        if ((string) $user->business_id !== (string) $businessId
            || ! User::can_access_this_location($locationId, $businessId)
            || ! $this->authorizationGate->allowsRead(
                $user,
                'recommerce.stock.reconcile',
                $businessId,
                $locationId,
                $variationId
            )) {
            throw new AuthorizationException('Recommerce reconciliation scope denied.');
        }

        return $this->calculate($businessId, $locationId, $variationId);
    }

    /**
     * Stop a new tracked receive when the current persisted stock evidence is
     * already divergent. The receiving service has already established the
     * write/business/location/cohort boundary before calling this guard.
     */
    public function assertTrackedReceiveMayProceed(
        int $businessId,
        int $locationId,
        int $variationId
    ): void {
        $result = $this->calculate($businessId, $locationId, $variationId);

        if (in_array($result['status'], ['MISMATCH', 'EXCEPTION'], true)) {
            throw new ReceivingReconciliationBlockedException(
                'Tracked receiving is blocked until the stock reconciliation exception is resolved.'
            );
        }
    }

    protected function calculate(int $businessId, int $locationId, int $variationId): array
    {
        $coreRow = DB::table('variation_location_details as vld')
            ->join('products as p', 'p.id', '=', 'vld.product_id')
            ->where('p.business_id', $businessId)
            ->where('vld.location_id', $locationId)
            ->where('vld.variation_id', $variationId)
            ->select('vld.product_id', 'vld.qty_available')
            ->first();

        $trackedCount = Device::query()
            ->where('business_id', $businessId)
            ->where('current_location_id', $locationId)
            ->where('variation_id', $variationId)
            ->whereIn('stock_participation', ['ON_HAND', 'RESERVED'])
            ->count();

        $inTransferCount = Device::query()
            ->where('business_id', $businessId)
            ->where('variation_id', $variationId)
            ->where('stock_participation', 'IN_TRANSFER')
            ->count();

        $profile = SerializationProfile::query()
            ->where('business_id', $businessId)
            ->where('variation_id', $variationId)
            ->when(Schema::hasColumn('recommerce_serialization_profiles', 'inventory_tracking_mode'), fn ($query) => $query->where('inventory_tracking_mode', 'SERIALIZED_DEVICE'))
            ->when($coreRow, fn ($query) => $query->where('product_id', $coreRow->product_id))
            ->first();

        $legacyBalanceRow = $profile && $profile->mode === 'LEGACY_MIXED'
            ? LegacyStockBalance::query()
                ->where('serialization_profile_id', $profile->id)
                ->where('business_id', $businessId)
                ->where('location_id', $locationId)
                ->where('variation_id', $variationId)
                ->first()
            : null;

        $profileApproved = $profile
            && $profile->configured_by !== null
            && trim((string) $profile->approval_reference) !== '';
        $balanceApproved = $legacyBalanceRow
            && $legacyBalanceRow->approved_by !== null
            && trim((string) $legacyBalanceRow->evidence_reference) !== '';

        $evidenceStatus = 'MISSING_PROFILE';
        $legacyBalance = null;

        if ($profile !== null) {
            $evidenceStatus = 'INCOMPLETE_PROFILE';
        }

        if ($profileApproved && ! in_array($profile->mode, ['TRACKED_REQUIRED', 'LEGACY_MIXED'], true)) {
            $evidenceStatus = 'INVALID_PROFILE';
        } elseif ($profileApproved && $profile->mode === 'TRACKED_REQUIRED') {
            $evidenceStatus = 'APPROVED_PROFILE';
            $legacyBalance = 0.0;
        } elseif ($profileApproved && $profile->mode === 'LEGACY_MIXED' && $legacyBalanceRow === null) {
            $evidenceStatus = 'MISSING_BALANCE';
        } elseif ($profileApproved && $profile->mode === 'LEGACY_MIXED' && ! $balanceApproved) {
            $evidenceStatus = 'INCOMPLETE_BALANCE';
        } elseif ($balanceApproved && (! is_numeric($legacyBalanceRow->legacy_unserialized_qty)
            || (float) $legacyBalanceRow->legacy_unserialized_qty < 0)) {
            $evidenceStatus = 'INVALID_BALANCE';
        } elseif ($balanceApproved) {
            $evidenceStatus = 'APPROVED_BALANCE';
            $legacyBalance = (float) $legacyBalanceRow->legacy_unserialized_qty;
        }

        if (! $coreRow || $legacyBalance === null) {
            return [
                'status' => 'UNAVAILABLE',
                'business_id' => $businessId,
                'location_id' => $locationId,
                'variation_id' => $variationId,
                'core_quantity' => null,
                'tracked_device_count' => $trackedCount,
                'in_transfer_device_count' => $inTransferCount,
                'approved_legacy_balance' => $legacyBalance,
                'reconciliation_evidence_status' => $evidenceStatus,
                'serialization_profile_id' => $profile?->id,
                'legacy_balance_id' => $legacyBalanceRow?->id,
                'difference' => null,
            ];
        }

        $coreQuantity = (float) $coreRow->qty_available;
        $difference = $coreQuantity - $trackedCount - $legacyBalance;

        return [
            'status' => $inTransferCount > 0
                ? 'EXCEPTION'
                : (abs($difference) < 0.000001 ? 'PASS' : 'MISMATCH'),
            'business_id' => $businessId,
            'location_id' => $locationId,
            'variation_id' => $variationId,
            'core_quantity' => $coreQuantity,
            'tracked_device_count' => $trackedCount,
            'in_transfer_device_count' => $inTransferCount,
            'approved_legacy_balance' => $legacyBalance,
            'reconciliation_evidence_status' => $evidenceStatus,
            'serialization_profile_id' => $profile->id,
            'legacy_balance_id' => $legacyBalanceRow?->id,
            'difference' => $difference,
        ];
    }
}
