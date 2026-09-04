<?php

namespace Modules\Recommerce\Services;

use App\Contact;
use App\User;
use App\Variation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Recommerce\Entities\TradeInQuickQuote;
use Modules\Recommerce\Entities\TradeInValuation;
use Modules\Recommerce\Support\AuthorizationGate;

class TradeInQuickQuoteService
{
    public function __construct(
        protected AuthorizationGate $authorizationGate,
        protected TradeInRuleResolver $ruleResolver,
        protected TradeInPricingService $pricingService
    ) {
    }

    public function create(User $user, array $input): TradeInQuickQuote
    {
        $businessId = (int) $user->business_id;
        $locationId = (int) ($input['location_id'] ?? 0);
        $variationId = (int) ($input['variation_id'] ?? 0);
        $commandUuid = strtolower(trim((string) ($input['command_uuid'] ?? '')));
        if (! Str::isUuid($commandUuid)) {
            throw new LogicException('Quick Quote requires a valid idempotency reference.');
        }
        if (! $this->authorizationGate->allowsWrite($user, TradeInService::PERMISSION_MANAGE, $businessId, $locationId, $variationId)) {
            throw new AuthorizationException('Trade-in Quick Quote scope denied.');
        }

        $variation = Variation::query()->with('product')->find($variationId);
        if (! $variation || ! $variation->product || (int) $variation->product->business_id !== $businessId) {
            throw new LogicException('Choose an approved catalogue match for this quote.');
        }
        $brand = $this->requiredText($input['brand'] ?? null, 'Brand', 100);
        $model = $this->requiredText($input['model'] ?? null, 'Model', 160);
        $grade = strtoupper(trim((string) ($input['cosmetic_grade'] ?? '')));
        if (! in_array($grade, ['A', 'B', 'C', 'D'], true)) {
            throw new LogicException('Quick Quote cosmetic grade must be A, B, C, or D.');
        }
        $battery = $input['battery_health_percent'] ?? null;
        if ($battery !== null && $battery !== '' && (! is_numeric($battery) || (float) $battery < 0 || (float) $battery > 100)) {
            throw new LogicException('Battery health must be between 0 and 100 percent.');
        }
        $resale = isset($input['expected_resale_amount']) && $input['expected_resale_amount'] !== ''
            ? $input['expected_resale_amount']
            : $variation->sell_price_inc_tax;
        if (! is_numeric($resale) || (float) $resale <= 0) {
            throw new LogicException('A positive expected resale amount is required to calculate this quote.');
        }
        $customerId = isset($input['customer_contact_id']) && (int) $input['customer_contact_id'] > 0 ? (int) $input['customer_contact_id'] : null;
        $customer = $customerId ? Contact::query()
            ->where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])
            ->whereNull('deleted_at')
            ->whereKey($customerId)
            ->first() : null;
        if ($customerId && ! $customer) {
            throw new LogicException('The selected seller is unavailable.');
        }
        $expectedUnknown = filter_var($input['customer_expected_unknown'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $expected = $expectedUnknown || ($input['customer_expected_amount'] ?? '') === ''
            ? null
            : $this->money($input['customer_expected_amount'], 'Customer expected price');
        $rule = $this->ruleResolver->resolve($businessId, $variationId, 'LAPTOP');
        $pricing = $this->pricingService->calculate($rule, [
            'expected_resale_amount' => $resale,
            'expected_refurbishment_amount' => 0,
        ]);
        $validDays = max(1, min(30, (int) config('recommerce.tradein_quote_valid_days', 7)));

        return DB::transaction(function () use ($user, $input, $businessId, $locationId, $variation, $rule, $commandUuid, $customer, $brand, $model, $grade, $battery, $expectedUnknown, $expected, $resale, $pricing, $validDays) {
            $existing = TradeInQuickQuote::query()->where('business_id', $businessId)->where('command_uuid', $commandUuid)->first();
            if ($existing) {
                return $existing;
            }

            $supersedesQuoteId = isset($input['supersedes_quote_id']) && (int) $input['supersedes_quote_id'] > 0
                ? (int) $input['supersedes_quote_id']
                : null;
            if ($supersedesQuoteId) {
                $superseded = TradeInQuickQuote::query()
                    ->where('business_id', $businessId)
                    ->where('location_id', $locationId)
                    ->where('variation_id', $variation->id)
                    ->whereKey($supersedesQuoteId)
                    ->lockForUpdate()
                    ->first();
                if (! $superseded || ! $superseded->isExpired()) {
                    throw new LogicException('Only an expired quote in the same branch and catalogue scope can be revalued.');
                }
            }

            return TradeInQuickQuote::create([
                'quote_uuid' => (string) Str::uuid(),
                'command_uuid' => $commandUuid,
                'business_id' => $businessId,
                'location_id' => $locationId,
                'customer_contact_id' => optional($customer)->id,
                'product_id' => $variation->product_id,
                'variation_id' => $variation->id,
                'rule_set_id' => $rule->id,
                'supersedes_quote_id' => $supersedesQuoteId,
                'status' => TradeInQuickQuote::STATUS_CONSIDERING,
                'acquisition_type' => in_array(($input['acquisition_type'] ?? ''), ['SELL_TO_SAVERBRO', 'TRADE_IN'], true) ? $input['acquisition_type'] : 'SELL_TO_SAVERBRO',
                'seller_name_snapshot' => optional($customer)->name ?: $this->optionalText($input['seller_name'] ?? null, 255),
                'seller_phone_snapshot' => optional($customer)->mobile ?: $this->optionalText($input['seller_phone'] ?? null, 80),
                'specifications_json' => array_filter([
                    'brand' => $brand, 'model' => $model, 'cpu' => $this->optionalText($input['cpu'] ?? null, 160),
                    'ram' => $this->optionalText($input['ram'] ?? null, 80), 'storage' => $this->optionalText($input['storage'] ?? null, 120),
                    'gpu' => $this->optionalText($input['gpu'] ?? null, 160),
                ], fn ($value) => $value !== null && $value !== ''),
                'condition_json' => [
                    'cosmetic_grade' => $grade,
                    'battery_health_percent' => $battery === null || $battery === '' ? null : round((float) $battery, 2),
                    'major_defects' => $this->optionalText($input['major_defects'] ?? null, 1000),
                    'charger_included' => filter_var($input['charger_included'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ],
                'customer_expected_amount' => $expected,
                'customer_expected_unknown' => $expectedUnknown,
                'expected_resale_amount' => round((float) $resale, 4),
                'pricing_snapshot_json' => $pricing,
                'estimated_low_amount' => data_get($pricing, 'recommendation.opening_offer_amount'),
                'estimated_high_amount' => data_get($pricing, 'recommendation.negotiation_ceiling_amount'),
                'expires_at' => now()->addDays($validDays)->endOfDay(),
                'created_by' => $user->id,
            ]);
        });
    }

    public function decline(User $user, TradeInQuickQuote $quote, string $reasonCode, string $reason): TradeInQuickQuote
    {
        if (! $this->authorizationGate->allowsWrite($user, TradeInService::PERMISSION_MANAGE, $quote->business_id, $quote->location_id, $quote->variation_id)) {
            throw new AuthorizationException('Trade-in Quick Quote scope denied.');
        }
        $allowed = ['OFFER_TOO_LOW', 'CUSTOMER_EXPECTED_MORE', 'COMPETITOR_OFFERED_MORE', 'CUSTOMER_DECIDED_NOT_TO_SELL', 'PRICE_CHECK_ONLY', 'NO_SUITABLE_UPGRADE', 'OTHER'];
        $reasonCode = strtoupper(trim($reasonCode));
        if (! in_array($reasonCode, $allowed, true) || trim($reason) === '') {
            throw new LogicException('Choose a customer-declined reason and add a short note.');
        }

        return DB::transaction(function () use ($quote, $reasonCode, $reason): TradeInQuickQuote {
            $locked = TradeInQuickQuote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== TradeInQuickQuote::STATUS_CONSIDERING) {
                throw new LogicException('Only a quote still under consideration can be closed as customer declined.');
            }
            $locked->update([
                'status' => TradeInQuickQuote::STATUS_CUSTOMER_DECLINED,
                'lost_reason_code' => $reasonCode,
                'lost_reason' => mb_substr(trim($reason), 0, 255),
            ]);

            return $locked->fresh();
        });
    }

    public function continueToValuation(TradeInQuickQuote $quote, TradeInValuation $valuation): void
    {
        DB::transaction(function () use ($quote, $valuation): void {
            $locked = TradeInQuickQuote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === TradeInQuickQuote::STATUS_CONTINUED
                && (int) $locked->continued_to_valuation_id === (int) $valuation->id) {
                return;
            }
            if ($locked->status !== TradeInQuickQuote::STATUS_CONSIDERING) {
                throw new LogicException('Only a quote still under consideration can continue to a formal valuation.');
            }
            if ($locked->isExpired()) {
                throw new LogicException('This Quick Quote has expired. Create a new quote before formal valuation.');
            }
            if ((int) $locked->business_id !== (int) $valuation->business_id
                || (int) $locked->location_id !== (int) $valuation->location_id
                || (int) $locked->variation_id !== (int) $valuation->variation_id) {
                throw new LogicException('Quick Quote and valuation scope do not match.');
            }
            $locked->update([
                'status' => TradeInQuickQuote::STATUS_CONTINUED,
                'continued_to_valuation_id' => $valuation->id,
            ]);
        });
    }

    protected function requiredText($value, string $label, int $limit): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new LogicException($label.' is required for a Quick Quote.');
        }
        return mb_substr($value, 0, $limit);
    }

    protected function optionalText($value, int $limit): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    protected function money($value, string $label): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
            throw new LogicException($label.' must be a non-negative amount.');
        }
        return round((float) $value, 4);
    }
}
