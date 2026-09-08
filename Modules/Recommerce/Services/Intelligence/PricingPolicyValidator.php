<?php
namespace Modules\Recommerce\Services\Intelligence;

use LogicException;

/** Validate imported policy before any customer estimate can consume it. */
final class PricingPolicyValidator
{
    public static function validate(array $p): void
    {
        foreach (['version','approval_reference','condition_basis'] as $field) {
            if (!is_string($p[$field] ?? null) || trim($p[$field]) === '' || strlen($p[$field]) > 200) {
                throw new LogicException('Policy needs a valid '.$field.'.');
            }
        }
        if (!in_array($p['status'] ?? '', ['PROPOSED','APPROVED','REVOKED'], true) ||
            !is_array($p['categories'] ?? null) || !$p['categories'] ||
            array_diff($p['categories'], ['LAPTOP','PHONE','TABLET']) ||
            !is_array($p['reference_order'] ?? null) || !$p['reference_order'] ||
            array_diff($p['reference_order'], ['APPROVED','MARKET','MODEL']) ||
            count(array_unique($p['reference_order'])) !== count($p['reference_order'])) {
            throw new LogicException('Invalid policy scope or reference order.');
        }
        $c = $p['commercial'] ?? null;
        if (!is_array($c) || ($c['version'] ?? null) !== $p['version'] || ($c['status'] ?? null) !== $p['status']) {
            throw new LogicException('Commercial policy version and status must match approval.');
        }
        foreach (['minimum_margin_minor','warranty_reserve_minor','logistics_handling_minor','inventory_risk_minor',
            'minimum_offer_minor','charger_missing_minor'] as $field) {
            self::money($c[$field] ?? null);
        }
        foreach (['target_margin_percent','maximum_acquisition_ratio'] as $field) {
            if (!is_numeric($c[$field] ?? null) || !is_finite((float)$c[$field]) || $c[$field] < 0 || $c[$field] > 1) {
                throw new LogicException('Invalid commercial ratio.');
            }
        }
        if (!is_int($c['valid_days'] ?? null) || $c['valid_days'] < 1 || $c['valid_days'] > 30 ||
            !is_int($c['automatic_quote_min_confidence'] ?? null) || $c['automatic_quote_min_confidence'] < 0 || $c['automatic_quote_min_confidence'] > 100) {
            throw new LogicException('Invalid quote validity or confidence threshold.');
        }
        foreach (['HIGH','MEDIUM','LOW'] as $tier) self::money($c['range_width_minor'][$tier] ?? null);
        $enums = ['power'=>['yes','intermittent','no','not_sure'],'screen'=>['perfect','minor','lines','cracked','not_working','not_sure'],
            'battery'=>['good','short','plugged','missing','not_sure'],'physical'=>['excellent','good','fair','poor']];
        if (!is_array($c['condition_deductions_minor'] ?? null) || array_diff(array_keys($c['condition_deductions_minor']), array_keys($enums))) {
            throw new LogicException('Invalid condition deduction schema.');
        }
        foreach ($c['condition_deductions_minor'] as $field=>$options) {
            if (!is_array($options) || array_diff(array_keys($options),$enums[$field])) throw new LogicException('Invalid condition options.');
            foreach ($options as $amount) self::money($amount);
        }
        // The resale condition is the zero-deduction basis. A good-condition reference
        // cannot also silently incur the same good-condition deduction.
        if (!in_array($p['condition_basis'],$enums['physical'],true) ||
            ($c['condition_deductions_minor']['physical'][$p['condition_basis']] ?? 0) !== 0) {
            throw new LogicException('Reference condition is already priced; resolve duplicate condition deductions.');
        }
        if (!is_array($c['inspection_deductions_minor'] ?? null)) throw new LogicException('Inspection deductions are missing.');
        foreach ($c['inspection_deductions_minor'] as $amount) self::money($amount);
        if (!in_array($p['market_min_confidence'] ?? '', ['MEDIUM','HIGH'], true) || !is_array($p['market_target'] ?? null) || !is_array($p['demand'] ?? null)) {
            throw new LogicException('Invalid market or demand policy.');
        }
        $target=$p['market_target'];
        if (($target['condition'] ?? null)!==$p['condition_basis'] ||
            !in_array($target['segment'] ?? '',['PRIVATE_USED','RETAIL_USED','RETAIL_REFURBISHED','RETAIL_NEW'],true) ||
            !in_array($target['region'] ?? '',['SABAH','SARAWAK','PENINSULAR'],true) || !is_string($target['warranty'] ?? null)) {
            throw new LogicException('Market target must specify an exact approved segment and condition basis.');
        }
    }

    private static function money($amount): void
    {
        if (!is_int($amount) || $amount < 0 || $amount > 100000000) throw new LogicException('Invalid commercial amount.');
    }
}
