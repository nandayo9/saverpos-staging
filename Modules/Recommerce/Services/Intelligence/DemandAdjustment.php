<?php

namespace Modules\Recommerce\Services\Intelligence;

/** Applies only explicitly approved deltas to complete, fresh native signals. */
final class DemandAdjustment
{
    public function calculate(int $base, ?array $signal, ?array $policy, int $now): array
    {
        $out = [
            'base_minor' => $base,
            'adjustment_minor' => 0,
            'result_minor' => $base,
            'reason' => 'NO_APPROVED_POLICY',
            'signal_at' => $signal['observed_at'] ?? null,
            'policy_version' => $policy['version'] ?? null,
            'cap_applied' => false,
        ];
        if (($policy['status'] ?? '') !== 'APPROVED') {
            return $out;
        }
        $required = ['max_adjustment_minor', 'max_age_seconds', 'overstock_threshold',
            'overstock_adjustment_minor', 'low_stock_threshold', 'low_stock_adjustment_minor',
            'minimum_sales_30d', 'low_demand_sales_threshold', 'low_demand_adjustment_minor'];
        foreach ($required as $field) {
            if (!isset($policy[$field]) || !is_int($policy[$field]) || abs($policy[$field]) > 100000000) {
                return array_replace($out, ['reason' => 'INCOMPLETE_POLICY']);
            }
        }
        if ($policy['max_adjustment_minor'] < 0 || $policy['max_age_seconds'] < 1 ||
            $policy['max_age_seconds'] > 86400 || $policy['low_stock_threshold'] < 0 ||
            $policy['overstock_threshold'] <= $policy['low_stock_threshold'] ||
            $policy['minimum_sales_30d'] < 1 || $policy['low_demand_sales_threshold'] < 0 ||
            $policy['low_demand_sales_threshold'] >= $policy['minimum_sales_30d'] ||
            $policy['overstock_adjustment_minor'] > 0 || $policy['low_demand_adjustment_minor'] > 0 ||
            $policy['low_stock_adjustment_minor'] < 0 || $base < 0) {
            return array_replace($out, ['reason' => 'INVALID_POLICY']);
        }
        $observed = is_string($signal['observed_at'] ?? null) ? strtotime($signal['observed_at']) : false;
        if (!$signal || ($signal['status'] ?? '') !== 'ELIGIBLE' || !$observed ||
            $observed > $now || $observed < $now - $policy['max_age_seconds']) {
            return array_replace($out, ['reason' => 'MISSING_OR_STALE_SIGNAL']);
        }
        foreach (['sellable_count', 'commitments_count', 'sales_30d'] as $field) {
            if (!isset($signal[$field]) || !is_int($signal[$field]) || $signal[$field] < 0 || $signal[$field] > 1000000) {
                return array_replace($out, ['reason' => 'INCOMPLETE_SIGNAL']);
            }
        }
        if (($signal['availability_history_complete'] ?? false) !== true) {
            return array_replace($out, ['reason' => 'STOCKOUT_OR_MISSING_EXPOSURE_HISTORY']);
        }
        $stock = $signal['sellable_count'] + $signal['commitments_count'];
        $delta = 0;
        $reason = 'NEUTRAL';
        // Select one adjustment. Overstock and low demand must not be double counted.
        if ($stock >= $policy['overstock_threshold']) {
            $delta = $policy['overstock_adjustment_minor'];
            $reason = 'OVERSTOCK';
        } elseif ($stock <= $policy['low_stock_threshold'] && $signal['sales_30d'] >= $policy['minimum_sales_30d']) {
            $delta = $policy['low_stock_adjustment_minor'];
            $reason = 'LOW_STOCK_WITH_DEMAND';
        } elseif ($signal['sales_30d'] <= $policy['low_demand_sales_threshold']) {
            $delta = $policy['low_demand_adjustment_minor'];
            $reason = 'LOW_DEMAND_WITH_COMPLETE_EXPOSURE';
        }
        $cap = $policy['max_adjustment_minor'];
        $bounded = max(-$base, max(-$cap, min($cap, $delta)));
        return array_replace($out, [
            'adjustment_minor' => $bounded,
            'result_minor' => $base + $bounded,
            'reason' => $reason,
            'cap_applied' => $bounded !== $delta,
        ]);
    }
}
