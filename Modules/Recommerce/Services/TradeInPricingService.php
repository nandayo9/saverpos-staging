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
    public function __construct(protected ?SaverValueService $saverValue = null)
    {
        $this->saverValue = $this->saverValue ?: new SaverValueService();
    }

    /** @return array<string, mixed> */
    public function calculate(TradeInRuleSet $ruleSet, array $input): array
    {
        // Legacy rule rows remain immutable provenance. All monetary output is
        // delegated to the single versioned SAVER Value implementation.
        $parameters = $this->normaliseParameters((array) $ruleSet->parameters_json);
        $valuation = $this->saverValue->final($input);
        $byCode = collect($valuation['breakdown'])->keyBy('code');
        $economicCeiling = ((int) $valuation['recommended_acquisition_minor']) / 100;

        return [
            'calculation_version' => $valuation['engine_version'],
            'engine_version' => $valuation['engine_version'],
            'policy_version' => $valuation['pricing_policy_version'],
            'rule' => [
                'id' => (int) $ruleSet->id,
                'code' => (string) $ruleSet->rule_code,
                'version' => (int) $ruleSet->version_number,
                'parameters' => $parameters,
            ],
            'inputs' => [
                'expected_resale_amount' => ((int) $valuation['expected_resale_minor']) / 100,
                'expected_refurbishment_amount' => $this->money($input['expected_refurbishment_amount'] ?? 0, 'Expected refurbishment amount'),
            ],
            'components' => [
                'warranty_reserve_amount' => abs((int) data_get($byCode, 'WARRANTY_RESERVE.amount_minor', 0)) / 100,
                'hidden_defect_reserve_amount' => 0.0,
                'markdown_reserve_amount' => 0.0,
                'logistics_handling_amount' => abs((int) data_get($byCode, 'LOGISTICS_HANDLING.amount_minor', 0)) / 100,
                'inventory_risk_amount' => abs((int) data_get($byCode, 'INVENTORY_RISK.amount_minor', 0)) / 100,
                'required_contribution_amount' => abs((int) data_get($byCode, 'REQUIRED_MARGIN.amount_minor', 0)) / 100,
            ],
            'recommendation' => [
                'opening_offer_amount' => ((int) $valuation['estimate_min_minor']) / 100,
                'target_acquisition_amount' => $economicCeiling,
                'negotiation_ceiling_amount' => $economicCeiling,
                'economic_ceiling_amount' => $economicCeiling,
            ],
            'saver_value' => $valuation,
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
