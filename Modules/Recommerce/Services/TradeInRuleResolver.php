<?php

namespace Modules\Recommerce\Services;

use LogicException;
use Modules\Recommerce\Entities\TradeInRuleSet;

class TradeInRuleResolver
{
    public function resolve(int $businessId, int $variationId, ?string $categoryCode = null): TradeInRuleSet
    {
        $rules = TradeInRuleSet::query()->where('business_id', $businessId)->where('status', 'ACTIVE')->get()
            ->filter(function (TradeInRuleSet $rule) use ($variationId, $categoryCode) {
                return $rule->variation_id === null || (int) $rule->variation_id === $variationId
                    || ($categoryCode !== null && $rule->category_code !== null && strtoupper($rule->category_code) === strtoupper($categoryCode));
            })->sortBy(function (TradeInRuleSet $rule) use ($variationId, $categoryCode) {
                if ((int) $rule->variation_id === $variationId) return 0;
                if ($categoryCode !== null && strtoupper((string) $rule->category_code) === strtoupper($categoryCode)) return 1;
                return 2;
            });
        $rule = $rules->first();
        if (! $rule) {
            throw new LogicException('No active pricing rule matches this device category and product variation.');
        }

        return $rule;
    }
}
