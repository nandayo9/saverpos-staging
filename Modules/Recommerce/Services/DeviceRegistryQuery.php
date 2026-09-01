<?php

namespace Modules\Recommerce\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Recommerce\Entities\Device;

/**
 * Read-only registry query composition.  This deliberately knows nothing
 * about lifecycle mutation: Registry only locates and describes passports.
 */
class DeviceRegistryQuery
{
    public const LIFECYCLE_STATES = [
        'RECEIVED_PENDING_INSPECTION', 'INSPECTION_IN_PROGRESS',
        'REFURBISHMENT_REQUIRED', 'AVAILABLE', 'RESERVED', 'SOLD',
    ];

    public const CUSTODY_KINDS = ['LOCATION', 'IN_TRANSIT', 'CUSTOMER', 'EXTERNAL_PROVIDER'];
    public const STOCK_STATES = ['ON_HAND', 'RESERVED', 'IN_TRANSFER', 'NONE'];
    public const LABEL_STATES = ['NEEDS_LABEL', 'NOT_PRINTED', 'PRINT_VIEW_OPENED', 'PRINTED', 'REPRINTED'];

    public function base(int $businessId, int $locationId, array $variationIds): Builder
    {
        return Device::query()
            ->with(['product', 'variation', 'currentLocation', 'certification', 'latestLabelJobItem.job'])
            ->where('business_id', $businessId)
            ->whereIn('variation_id', $variationIds)
            ->where(function (Builder $builder) use ($businessId, $locationId) {
                $builder->where('current_location_id', $locationId)
                    ->orWhere(function (Builder $transit) use ($locationId) {
                        $transit->where('custody_kind', 'IN_TRANSIT')
                            ->whereHas('transferAssignments', function (Builder $assignment) use ($locationId) {
                                $assignment->whereIn('status', ['IN_TRANSIT', 'RECEIVED', 'RECEIVED_WITH_ISSUE'])
                                    ->where(function (Builder $scope) use ($locationId) {
                                        $scope->where('from_location_id', $locationId)
                                            ->orWhere('to_location_id', $locationId);
                                    });
                            });
                    })
                    // A completed sale clears the current branch holder. The
                    // immutable UltimatePOS sale branch remains its read scope.
                    ->orWhereExists(function ($sales) use ($businessId, $locationId) {
                        $sales->selectRaw('1')
                            ->from('recommerce_device_sale_dispositions as registry_sales')
                            ->join('transactions as registry_sale_transactions', 'registry_sale_transactions.id', '=', 'registry_sales.sale_transaction_id')
                            ->whereColumn('registry_sales.device_id', 'recommerce_devices.id')
                            ->where('registry_sales.business_id', $businessId)
                            ->whereNotNull('registry_sales.active_sale_key')
                            ->where('registry_sale_transactions.location_id', $locationId);
                    });
            });
    }

    /** @param array<string, mixed> $filters */
    public function apply(Builder $query, array $filters): Builder
    {
        if (($filters['state'] ?? '') !== '') {
            $query->where('lifecycle_state', $filters['state']);
        }
        if (($filters['product_id'] ?? 0) > 0) {
            $query->where('product_id', $filters['product_id']);
        }
        if (($filters['variation_id'] ?? 0) > 0) {
            $query->where('variation_id', $filters['variation_id']);
        }
        if (($filters['category'] ?? '') !== '') {
            $query->where('category_code', $filters['category']);
        }
        if (($filters['custody'] ?? '') !== '') {
            $query->where('custody_kind', $filters['custody']);
        }
        if (($filters['transfer_state'] ?? '') === 'ACTIVE') {
            $query->where('transfer_state', '<>', 'NONE');
        }
        if (($filters['inventory'] ?? '') !== '') {
            $query->where('stock_participation', $filters['inventory']);
        }
        if (($filters['grade'] ?? '') !== '') {
            $query->whereHas('certification', fn (Builder $certification) => $certification->where('grade', $filters['grade']));
        }
        if (($filters['inspection'] ?? '') !== '') {
            $query->whereHas('inspection', fn (Builder $inspection) => $inspection->where('status', $filters['inspection']));
        }
        if (($filters['has_repair'] ?? false) === true) {
            $query->whereHas('repairJobs');
        }
        if (($filters['age_days'] ?? 0) > 0) {
            $query->where('acquired_at', '<=', now()->subDays($filters['age_days'])->endOfDay());
        }
        if (($filters['received_from'] ?? '') !== '') {
            $query->where('acquired_at', '>=', $filters['received_from'].' 00:00:00');
        }
        if (($filters['received_to'] ?? '') !== '') {
            $query->where('acquired_at', '<=', $filters['received_to'].' 23:59:59');
        }

        return $this->applyLabelStatus($query, $filters['label_status'] ?? '');
    }

    public function applyDescriptiveSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $builder) use ($term) {
            $builder->whereHas('product', fn (Builder $product) => $product->where('name', 'like', '%'.$term.'%'))
                ->orWhereHas('variation', fn (Builder $variation) => $variation->where('name', 'like', '%'.$term.'%')->orWhere('sub_sku', 'like', '%'.$term.'%'));
        });
    }

    private function applyLabelStatus(Builder $query, string $status): Builder
    {
        if ($status === '') {
            return $query;
        }

        if ($status === 'NOT_PRINTED') {
            return $query->whereDoesntHave('labelJobItems');
        }

        if ($status === 'NEEDS_LABEL') {
            return $query->where(function (Builder $needsLabel) {
                $needsLabel->whereDoesntHave('labelJobItems')
                    ->orWhereHas('labelJobItems', function (Builder $item) {
                        $item->whereRaw('recommerce_label_job_items.id = (select max(rc_latest_label.id) from recommerce_label_job_items as rc_latest_label where rc_latest_label.device_id = recommerce_devices.id)')
                            ->whereHas('job', fn (Builder $job) => $job->whereNotIn('status', ['PRINT_CONFIRMED', 'REPRINT_CONFIRMED']));
                    });
            });
        }

        $jobStatus = $status === 'REPRINTED' ? 'REPRINT_CONFIRMED' : ($status === 'PRINTED' ? 'PRINT_CONFIRMED' : null);
        if ($jobStatus !== null) {
            return $query->whereHas('labelJobItems', function (Builder $item) use ($jobStatus) {
                $item->whereRaw('recommerce_label_job_items.id = (select max(rc_latest_label.id) from recommerce_label_job_items as rc_latest_label where rc_latest_label.device_id = recommerce_devices.id)')
                    ->whereHas('job', fn (Builder $job) => $job->where('status', $jobStatus));
            });
        }

        return $query->whereHas('labelJobItems', function (Builder $item) {
            $item->whereRaw('recommerce_label_job_items.id = (select max(rc_latest_label.id) from recommerce_label_job_items as rc_latest_label where rc_latest_label.device_id = recommerce_devices.id)')
                ->whereHas('job', fn (Builder $job) => $job->whereNotIn('status', ['PRINT_CONFIRMED', 'REPRINT_CONFIRMED']));
        });
    }
}
