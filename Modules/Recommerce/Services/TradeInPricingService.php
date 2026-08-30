<?php

namespace Modules\Recommerce\Services;

use LogicException;
use Modules\Recommerce\Entities\TradeInRuleSet;

/**
 * Deterministic, versioned pricing calculator. It deliberately knows nothing
 * about stock, payments, or purchases: it turns an immutable inspection and
 * evidence snapshot into explainable acquisition ceilings only.
 */
class TradeInPricingService
{
    /** @return array<string, mixed> */
    public function calculate(TradeInRuleSet $ruleSet, array $input): array
    {
        $parameters = $this->normaliseParameters((array) $ruleSet->parameters_json);
        $expectedResale = $this->money($input['expected_resale_amount'] ?? null, 'Expected resale amount');
        $refurbishment = $this->money($input['expected_refurbishment_amount'] ?? 0, 'Expected refurbishment amount');

        $warrantyReserve = round($expectedResale * $parameters['warranty_reserve_percent'], 4);
        $hiddenDefectReserve = round($expectedResale * $parameters['hidden_defect_reserve_percent'], 4);
        $markdownReserve = round($expectedResale * $parameters['markdown_reserve_percent'], 4);
        $requiredContribution = round($expectedResale * $parameters['target_margin_percent'], 4);
        $economicCeiling = max(0, round(
            $expectedResale
            - $refurbishment
            - $warrantyReserve
            - $hiddenDefectReserve
            - $markdownReserve
            - $requiredContribution,
            4
        ));

        return [
            'calculation_version' => 'SAVERPOS_ACQUISITION_INTELLIGENCE_V1',
            'rule' => [
                'id' => (int) $ruleSet->id,
                'code' => (string) $ruleSet->rule_code,
                'version' => (int) $ruleSet->version_number,
                'parameters' => $parameters,
            ],
            'inputs' => [
                'expected_resale_amount' => $expectedResale,
                'expected_refurbishment_amount' => $refurbishment,
            ],
            'components' => [
                'warranty_reserve_amount' => $warrantyReserve,
                'hidden_defect_reserve_amount' => $hiddenDefectReserve,
                'markdown_reserve_amount' => $markdownReserve,
                'required_contribution_amount' => $requiredContribution,
            ],
            'recommendation' => [
                'opening_offer_amount' => round($economicCeiling * $parameters['opening_offer_ratio'], 4),
                'target_acquisition_amount' => round($economicCeiling * $parameters['target_acquisition_ratio'], 4),
                'negotiation_ceiling_amount' => round($economicCeiling * $parameters['negotiation_ceiling_ratio'], 4),
                'economic_ceiling_amount' => $economicCeiling,
            ],
        ];
    }

    /** @return array<string, float> */
    public function normaliseParameters(array $parameters): array
    {
        $required = [
            'target_margin_percent',
            'warranty_reserve_percent',
            'hidden_defect_reserve_percent',
            'markdown_reserve_percent',
            'opening_offer_ratio',
            'target_acquisition_ratio',
            'negotiation_ceiling_ratio',
        ];
        $normalised = [];
        foreach ($required as $key) {
            if (! array_key_exists($key, $parameters) || ! is_numeric($parameters[$key])) {
                throw new LogicException('Pricing rule is missing a valid '.$key.'.');
            }
            $value = (float) $parameters[$key];
            if (! is_finite($value) || $value < 0 || $value > 1) {
                throw new LogicException('Pricing rule '.$key.' must be between 0 and 1.');
            }
            $normalised[$key] = $value;
        }

        if ($normalised['opening_offer_ratio'] > $normalised['target_acquisition_ratio']
            || $normalised['target_acquisition_ratio'] > $normalised['negotiation_ceiling_ratio']) {
            throw new LogicException('Pricing rule offer ratios must be ordered from opening through negotiation ceiling.');
        }

        return $normalised;
    }

    protected function money($value, string $label): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
            throw new LogicException($label.' must be a non-negative amount.');
        }

        return round((float) $value, 4);
    }
}
