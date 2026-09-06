<?php

namespace Modules\Recommerce\Services;

use LogicException;
use Illuminate\Support\Str;

/** The single maintained SAVER Value financial calculation implementation. */
final class SaverValueService
{
    /** @return array<string, mixed> */
    public function indicative(array $input): array
    {
        $device = $this->normaliseDevice($input);
        $prediction = $this->predict($device);

        return $this->calculate($device, (array) ($input['condition'] ?? []), $prediction, [], 'INDICATIVE');
    }

    /** @return array<string, mixed> */
    public function final(array $input): array
    {
        $expectedResaleMinor = (int) round($this->money($input['expected_resale_amount'] ?? null, 'Expected resale amount') * 100);
        $refurbishmentMinor = (int) round($this->money($input['expected_refurbishment_amount'] ?? 0, 'Expected refurbishment amount') * 100);
        $inspection = (array) ($input['inspection'] ?? []);
        $condition = $this->conditionFromInspection($inspection);
        $deductions = $refurbishmentMinor > 0 ? [['code' => 'REFURBISHMENT', 'amount_minor' => $refurbishmentMinor]] : [];
        $policy = $this->policy();
        $battery = isset($inspection['battery_health_percent']) ? (int) $inspection['battery_health_percent'] : null;
        if ($battery !== null && $battery < 80) {
            $code = $battery < 60 ? 'battery_below_60' : 'battery_below_80';
            $deductions[] = ['code' => strtoupper($code), 'amount_minor' => (int) $policy['inspection_deductions_minor'][$code]];
        }
        $observations = collect((array) ($inspection['functional_observations'] ?? []))->keyBy(fn ($item) => strtoupper((string) ($item['key'] ?? '')));
        if (strtoupper((string) data_get($observations, 'DISPLAY.outcome')) === 'FAIL') {
            $deductions[] = ['code' => 'SCREEN_FAULT', 'amount_minor' => (int) $policy['inspection_deductions_minor']['screen_fault']];
        }
        if (strtoupper((string) data_get($observations, 'USB_PORTS.outcome')) === 'FAIL') {
            $deductions[] = ['code' => 'PORTS_FAULT', 'amount_minor' => (int) $policy['inspection_deductions_minor']['ports_fault']];
        }

        $device = [
            'category' => strtoupper((string) ($input['category'] ?? 'LAPTOP')),
            'model_id' => (string) ($input['model_id'] ?? ('SAVERPOS-VARIATION-'.(int) ($input['variation_id'] ?? 0))),
            'model_label' => (string) ($input['model_label'] ?? ''),
            'configuration' => (array) ($input['configuration'] ?? []),
        ];
        $prediction = [
            'provider' => 'SAVERPOS_STAFF_EVIDENCE',
            'expected_resale_minor' => $expectedResaleMinor,
            'prediction_interval_low_minor' => (int) round($this->money($input['market_low_amount'] ?? ($expectedResaleMinor / 100), 'Market low amount') * 100),
            'prediction_interval_high_minor' => (int) round($this->money($input['market_high_amount'] ?? ($expectedResaleMinor / 100), 'Market high amount') * 100),
            'confidence_score' => 100,
            'match_type' => 'TECHNICIAN_CONFIRMED',
            'comparable_count' => count((array) ($input['market_evidence'] ?? [])),
            'median_days_to_sell' => 0,
            'evidence' => array_map(static fn (array $item): array => array_intersect_key($item, array_flip(['evidence_type', 'source_description', 'observed_at'])), (array) ($input['market_evidence'] ?? [])),
            'assumptions' => [],
        ];

        return $this->calculate($device, $condition, $prediction, $deductions, 'FINAL');
    }

    /** @return array<string, mixed> */
    private function calculate(array $device, array $condition, array $prediction, array $trustedDeductions, string $type): array
    {
        $policy = $this->policy();
        $category = strtoupper(trim((string) ($device['category'] ?? '')));
        if (! in_array($category, ['LAPTOP', 'PHONE', 'TABLET', 'DESKTOP', 'GAMING', 'OTHER'], true)) {
            throw new LogicException('Choose a supported device category.');
        }
        $expectedResale = (int) ($prediction['expected_resale_minor'] ?? 0);
        if ($expectedResale <= 0 || $expectedResale > 100000000) {
            throw new LogicException('The resale prediction is outside the supported valuation range.');
        }

        $margin = max((int) $policy['minimum_margin_minor'], (int) round($expectedResale * (float) $policy['target_margin_percent']));
        $breakdown = [
            ['code' => 'EXPECTED_RESALE', 'amount_minor' => $expectedResale],
            ['code' => 'REQUIRED_MARGIN', 'amount_minor' => -$margin],
            ['code' => 'WARRANTY_RESERVE', 'amount_minor' => -(int) $policy['warranty_reserve_minor']],
            ['code' => 'LOGISTICS_HANDLING', 'amount_minor' => -(int) $policy['logistics_handling_minor']],
            ['code' => 'INVENTORY_RISK', 'amount_minor' => -(int) $policy['inventory_risk_minor']],
        ];
        $rules = [];
        foreach ((array) $policy['condition_deductions_minor'] as $field => $options) {
            $answer = (string) ($condition[$field] ?? '');
            if (isset($options[$answer])) {
                $code = strtoupper($field).'_'.strtoupper($answer);
                $breakdown[] = ['code' => $code, 'amount_minor' => -(int) $options[$answer]];
                $rules[] = $code;
            }
        }
        if (($device['configuration']['charger'] ?? '') === 'no') {
            $breakdown[] = ['code' => 'CHARGER_MISSING', 'amount_minor' => -(int) $policy['charger_missing_minor']];
            $rules[] = 'CHARGER_MISSING';
        }
        foreach ($trustedDeductions as $deduction) {
            $breakdown[] = ['code' => (string) $deduction['code'], 'amount_minor' => -abs((int) $deduction['amount_minor'])];
            $rules[] = (string) $deduction['code'];
        }

        $raw = array_sum(array_column($breakdown, 'amount_minor'));
        $ceiling = (int) floor(($expectedResale * (float) $policy['maximum_acquisition_ratio']) / 100) * 100;
        $recommended = min($ceiling, max((int) $policy['minimum_offer_minor'], $raw));
        $recommended = max(0, (int) floor($recommended / 1000) * 1000);
        $score = max(0, min(100, (int) ($prediction['confidence_score'] ?? 0)));
        $manualReasons = [];
        if (! in_array($category, (array) $policy['automatic_categories'], true)) {
            $manualReasons[] = 'This category is outside the automatic Laptop V1 scope.';
            $score = min($score, 55);
        }
        foreach (['processor', 'ram', 'storage'] as $field) {
            if (($device['configuration'][$field] ?? '') === '' || ($device['configuration'][$field] ?? '') === 'not_sure') {
                $score -= 8;
                $manualReasons[] = ucfirst($field).' is incomplete or uncertain.';
            }
        }
        if (in_array('not_sure', $condition, true)) {
            $score = min($score, 45);
            $manualReasons[] = 'One or more condition answers are uncertain.';
        }
        if (trim((string) ($condition['known_defects'] ?? '')) !== '') {
            $score = min($score, 55);
            $manualReasons[] = 'Customer-reported defects or repair history require inspection.';
        }
        $score = max(0, $score);
        $confidence = $score >= 80 ? 'HIGH' : ($score >= 60 ? 'MEDIUM' : 'LOW');
        $manualReview = $score < (int) $policy['automatic_quote_min_confidence'] || $manualReasons !== [];
        $width = (int) $policy['range_width_minor'][$confidence];

        return [
            'valuation_id' => (string) Str::uuid(), 'valuation_type' => $type, 'currency' => 'MYR',
            'normalized_device' => $device, 'customer_condition' => $type === 'INDICATIVE' ? $condition : null,
            'technician_condition' => $type === 'FINAL' ? $condition : null,
            'prediction_provider' => (string) $prediction['provider'], 'market_reference_minor' => $expectedResale,
            'expected_resale_minor' => $expectedResale,
            'prediction_interval_low_minor' => (int) ($prediction['prediction_interval_low_minor'] ?? $expectedResale),
            'prediction_interval_high_minor' => (int) ($prediction['prediction_interval_high_minor'] ?? $expectedResale),
            'recommended_acquisition_minor' => $recommended, 'maximum_acquisition_minor' => $recommended,
            'estimate_min_minor' => max(0, $recommended - $width), 'estimate_max_minor' => $recommended,
            'confidence_score' => $score, 'confidence' => $confidence, 'manual_review' => $manualReview,
            'manual_review_reasons' => array_values(array_unique($manualReasons)),
            'decision' => $manualReview ? 'MANUAL_REVIEW_REQUIRED' : 'AUTOMATIC_QUOTE',
            'valid_days' => (int) $policy['valid_days'], 'breakdown' => $breakdown, 'rules_triggered' => $rules,
            'evidence' => (array) ($prediction['evidence'] ?? []), 'comparable_count' => (int) ($prediction['comparable_count'] ?? 0),
            'median_days_to_sell' => (int) ($prediction['median_days_to_sell'] ?? 0), 'match_type' => (string) ($prediction['match_type'] ?? 'UNKNOWN'),
            'assumptions' => (array) ($prediction['assumptions'] ?? []),
            'pricing_policy_version' => (string) $policy['version'], 'pricing_policy_status' => (string) $policy['status'],
            'pricing_policy_snapshot' => $policy, 'engine_version' => (string) config('recommerce.saver_value.engine_version'),
            'created_at' => now()->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed> */
    private function predict(array $device): array
    {
        $reference = (array) data_get(config('recommerce.saver_value.approved_references', []), (string) $device['model_id'], []);
        $amount = (int) ($reference['amount_minor'] ?? 0);
        if ($amount <= 0) {
            throw new LogicException('No approved resale reference is available for this model. Manual valuation is required.');
        }
        return [
            'provider' => 'APPROVED_REFERENCE', 'expected_resale_minor' => $amount,
            'prediction_interval_low_minor' => $amount, 'prediction_interval_high_minor' => $amount,
            'confidence_score' => 72, 'match_type' => 'EXACT_APPROVED_REFERENCE', 'comparable_count' => 0, 'median_days_to_sell' => 0,
            'evidence' => [[
                'source_type' => 'APPROVED_REFERENCE', 'source_id' => (string) ($reference['reference_id'] ?? $device['model_id']),
                'source_description' => 'Approved SaverBro resale reference', 'amount_minor' => $amount,
                'observed_at' => (string) ($reference['observed_at'] ?? ''), 'match_score' => 100,
            ]],
            'assumptions' => ['No qualifying internal completed-sale sample was available; an approved reference was used.'],
        ];
    }

    /** @return array<string, mixed> */
    private function normaliseDevice(array $input): array
    {
        $modelId = trim((string) ($input['model_id'] ?? ''));
        if ($modelId === '') throw new LogicException('Choose a supported published model.');
        $condition = (array) ($input['condition'] ?? []);
        foreach (['power', 'screen', 'battery', 'physical'] as $required) {
            if (trim((string) ($condition[$required] ?? '')) === '') throw new LogicException('Condition answer missing: '.$required.'.');
        }
        return [
            'category' => strtoupper(trim((string) ($input['category'] ?? ''))), 'model_id' => $modelId,
            'model_label' => trim((string) ($input['model_label'] ?? '')),
            'configuration' => array_intersect_key((array) ($input['configuration'] ?? []), array_flip(['processor','ram','storage','connectivity','charger'])),
        ];
    }

    /** @return array<string, string> */
    private function conditionFromInspection(array $inspection): array
    {
        return [
            'power' => 'yes', 'screen' => 'perfect', 'battery' => 'good',
            'physical' => strtolower((string) ($inspection['cosmetic_grade'] ?? 'A')) === 'a' ? 'excellent' : 'good',
        ];
    }

    /** @return array<string, mixed> */
    private function policy(): array
    {
        $policy = (array) config('recommerce.saver_value.policy', []);
        foreach (['version','target_margin_percent','minimum_margin_minor','warranty_reserve_minor','logistics_handling_minor','inventory_risk_minor'] as $field) {
            if (! array_key_exists($field, $policy)) throw new LogicException('SAVER Value policy is missing '.$field.'.');
        }
        return $policy;
    }

    private function money($value, string $label): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) throw new LogicException($label.' must be a non-negative amount.');
        return round((float) $value, 4);
    }
}
