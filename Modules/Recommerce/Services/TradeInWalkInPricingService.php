<?php

namespace Modules\Recommerce\Services;

/**
 * Walk-in device buyback pricing.
 *
 * Separate from TradeInPricingService (which stays on the rule-set/laptop
 * wizard path) because the walk-in formula is a fixed, category- and
 * brand-family-aware deduction table the business gave directly, not a
 * configurable TradeInRuleSet.
 *
 * Severity tiers: the business described several deductions as ranges
 * ("-RM100 to -RM300"). There is no discrete input for that in a form, so
 * each such deduction is captured as a 3-tier severity (MINOR/MODERATE/SEVERE)
 * mapped to the low/mid/high of the stated range. Adjust the tier boundaries
 * in $this->range() once real quotes are seen.
 */
class TradeInWalkInPricingService
{
    public const CATEGORY_PHONE = 'PHONE';
    public const CATEGORY_TABLET = 'TABLET';
    public const CATEGORY_LAPTOP = 'LAPTOP';

    public const BRAND_APPLE = 'APPLE';
    public const BRAND_ANDROID = 'ANDROID';
    public const BRAND_ANDROID_FOLDABLE = 'ANDROID_FOLDABLE';

    public const REASON_AUTO_REJECT = 'DEVICE_INELIGIBLE_AUTO_REJECT';

    protected const FLOOR_AMOUNT = 30.0;

    public function calculate(string $categoryCode, string $brandFamily, float $marketPrice, array $answers): array
    {
        return match ($categoryCode) {
            self::CATEGORY_PHONE, self::CATEGORY_TABLET => $this->calculateMobile($categoryCode, $brandFamily, $marketPrice, $answers),
            self::CATEGORY_LAPTOP => $this->calculateLaptop($marketPrice, $answers),
            default => throw new \InvalidArgumentException("Unknown walk-in category [{$categoryCode}]."),
        };
    }

    protected function calculateMobile(string $categoryCode, string $brandFamily, float $marketPrice, array $answers): array
    {
        $base = round($marketPrice / 2, 2);
        $deductions = [];

        $screenRange = match (true) {
            $brandFamily === self::BRAND_APPLE => [100, 300],
            $brandFamily === self::BRAND_ANDROID_FOLDABLE && $categoryCode === self::CATEGORY_PHONE => [200, 600],
            default => [50, 250],
        };
        $deductions[] = $this->tieredDeduction('SCREEN_CONDITION', 'Screen condition', $answers['screen_condition'] ?? 'FLAWLESS', $screenRange);

        $bodyRange = match (true) {
            $brandFamily === self::BRAND_APPLE => [0, 200],
            $brandFamily === self::BRAND_ANDROID_FOLDABLE && $categoryCode === self::CATEGORY_PHONE => [0, 250],
            default => [0, 200],
        };
        $deductions[] = $this->tieredDeduction('BODY_CONDITION', 'Body condition', $answers['body_condition'] ?? 'FLAWLESS', $bodyRange);

        if (! $this->bool($answers['biometrics_ok'] ?? true)) {
            $deductions[] = $this->flat('BIOMETRICS_FAULT', 'Fingerprint / Face ID not working', 200);
        }
        if (! $this->bool($answers['core_functions_ok'] ?? true)) {
            $deductions[] = $this->flat('CORE_FUNCTIONS_FAULT', 'Speaker, microphone, buttons, Wi-Fi or Bluetooth not working', 300);
        }
        if (! $this->bool($answers['camera_ok'] ?? true)) {
            $deductions[] = $this->flat('CAMERA_FAULT', 'Camera not working', 300);
        }
        if (! empty($answers['extra_issue'])) {
            $deductions[] = $this->tieredDeduction('EXTRA_ISSUE', 'Extra issue', $answers['extra_issue_severity'] ?? 'MINOR', [100, 300]);
        }

        return $this->finalize($base, $deductions, $answers, rejected: false, rejectedReason: null);
    }

    protected function calculateLaptop(float $marketPrice, array $answers): array
    {
        $base = round($marketPrice / 2, 2);
        $deductions = [];
        $rejected = false;
        $rejectedReason = null;

        $lcd = $answers['lcd_condition'] ?? 'FLAWLESS';
        if ($lcd === 'NOT_WORKING') {
            $rejected = true;
            $rejectedReason = 'LCD not working';
        } else {
            $deductions[] = match ($lcd) {
                'FLAWLESS' => $this->flat('LCD_CONDITION', 'LCD condition: flawless', 0),
                'MINOR_SCRATCHES' => $this->flat('LCD_CONDITION', 'LCD condition: 2-3 minor scratches', 200),
                'HEAVY_SCRATCH' => $this->flat('LCD_CONDITION', 'LCD condition: heavy scratch', 400),
                'CRACKED' => $this->flat('LCD_CONDITION', 'LCD condition: cracked', 600),
                default => $this->flat('LCD_CONDITION', 'LCD condition: flawless', 0),
            };
        }

        $body = $answers['body_condition'] ?? 'FLAWLESS';
        $deductions[] = match ($body) {
            'FLAWLESS' => $this->flat('BODY_CONDITION', 'Body condition: flawless', 0),
            'SCRATCHES' => $this->flat('BODY_CONDITION', 'Body condition: scratches', 100),
            'DENT_BENT_CORNER' => $this->flat('BODY_CONDITION', 'Body condition: dent / bent corner', 200),
            'CRACKED' => $this->flat('BODY_CONDITION', 'Body condition: cracked', 300),
            default => $this->flat('BODY_CONDITION', 'Body condition: flawless', 0),
        };

        if (! $this->bool($answers['input_devices_ok'] ?? true)) {
            $deductions[] = $this->flat('INPUT_DEVICES_FAULT', 'Keyboard, buttons, Touch Bar or trackpad not working', 400);
        }
        if (! $this->bool($answers['camera_ok'] ?? true)) {
            $deductions[] = $this->flat('CAMERA_FAULT', 'Camera not working', 100);
        }

        if ($this->bool($answers['battery_bloated_or_pop_out'] ?? false)) {
            $rejected = true;
            $rejectedReason = 'Bloated battery / screen or body pop-out';
        }
        if ($this->bool($answers['jailbroken_or_rooted'] ?? false)) {
            $rejected = true;
            $rejectedReason = 'Jailbroken / rooted device';
        }
        if (! $rejected) {
            if ($this->bool($answers['liquid_damage'] ?? false)) {
                $deductions[] = $this->flat('LIQUID_DAMAGE', 'Liquid damage', 700);
            }
            if ($this->bool($answers['power_on_fault'] ?? false)) {
                $deductions[] = $this->flat('POWER_ON_FAULT', 'Device cannot power on', 300);
            }
        }

        return $this->finalize($base, $deductions, $answers, $rejected, $rejectedReason);
    }

    protected function finalize(float $base, array $deductions, array $answers, bool $rejected, ?string $rejectedReason): array
    {
        if ($rejected) {
            return [
                'base_amount' => $base,
                'deductions' => $deductions,
                'margin_deductions' => [],
                'final_amount' => 0.0,
                'rejected' => true,
                'rejected_reason' => $rejectedReason,
            ];
        }

        $afterConditionDeductions = $base - array_sum(array_column($deductions, 'amount'));
        $marginDeductions = $this->marginDeductions($afterConditionDeductions, $answers);
        $afterMargin = $afterConditionDeductions - array_sum(array_column($marginDeductions, 'amount'));

        return [
            'base_amount' => $base,
            'deductions' => $deductions,
            'margin_deductions' => $marginDeductions,
            'final_amount' => max(self::FLOOR_AMOUNT, round($afterMargin, 2)),
            'rejected' => false,
            'rejected_reason' => null,
        ];
    }

    protected function marginDeductions(float $amount, array $answers): array
    {
        $deductions = [];

        $deductions[] = $this->percentOf('STORE_PROFIT', 'Store profit margin', $amount, 30);

        $warrantyPercent = match ($answers['warranty_status'] ?? 'EXPIRED') {
            'BRANDED' => 10,
            'OTHER_STORE' => 15,
            default => 20,
        };
        $deductions[] = $this->percentOf('WARRANTY_ADJUSTMENT', 'Warranty adjustment', $amount, $warrantyPercent);

        $appearanceRange = $this->range([10, 20], $answers['appearance_severity'] ?? 'MINOR');
        $deductions[] = $this->percentOf('APPEARANCE_ADJUSTMENT', 'Appearance (scratches, dents, stickers)', $amount, $appearanceRange);

        return $deductions;
    }

    protected function tieredDeduction(string $code, string $label, string $condition, array $range): array
    {
        if ($condition === 'FLAWLESS' || $condition === 'NONE') {
            return $this->flat($code, "{$label}: flawless", 0);
        }

        $severity = match ($condition) {
            'MINOR', 'MODERATE', 'SEVERE' => $condition,
            default => 'MODERATE',
        };
        $amount = $this->range($range, $severity);

        return $this->flat($code, "{$label}: {$severity}", $amount);
    }

    protected function range(array $lowHigh, string $severity): float
    {
        [$low, $high] = $lowHigh;
        $mid = $low + (($high - $low) / 2);

        return match ($severity) {
            'MINOR' => (float) $low,
            'SEVERE' => (float) $high,
            default => round($mid, 2),
        };
    }

    protected function percentOf(string $code, string $label, float $amount, float $percent): array
    {
        return ['code' => $code, 'label' => "{$label} ({$percent}%)", 'amount' => round($amount * ($percent / 100), 2)];
    }

    protected function flat(string $code, string $label, float $amount): array
    {
        return ['code' => $code, 'label' => $label, 'amount' => $amount];
    }

    protected function bool($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
