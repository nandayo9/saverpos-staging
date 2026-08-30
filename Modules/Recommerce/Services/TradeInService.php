<?php

namespace Modules\Recommerce\Services;

use App\BusinessLocation;
use App\Contact;
use App\Product;
use App\Transaction;
use App\User;
use App\Variation;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Recommerce\Entities\CustodyPeriod;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceAcquisition;
use Modules\Recommerce\Entities\DeviceAcquisitionReversal;
use Modules\Recommerce\Entities\DeviceMovement;
use Modules\Recommerce\Entities\OwnershipPeriod;
use Modules\Recommerce\Entities\TradeInMarketEvidence;
use Modules\Recommerce\Entities\TradeInRuleSet;
use Modules\Recommerce\Entities\TradeInValuation;
use Modules\Recommerce\Support\AuthorizationGate;

/**
 * RC-038 command service. UltimatePOS remains the only purchase, payable,
 * payment, and aggregate-stock authority; this service only supplies the
 * physical-device and valuation evidence that surrounds that native purchase.
 */
class TradeInService
{
    public const PERMISSION_VIEW = 'recommerce.tradein.view';
    public const PERMISSION_MANAGE = 'recommerce.tradein.manage';
    public const PERMISSION_APPROVE = 'recommerce.tradein.approve';
    public const PERMISSION_OVERRIDE_ECONOMIC = 'recommerce.tradein.override_economic_ceiling';
    public const PERMISSION_ACCEPT = 'recommerce.tradein.accept';
    public const PERMISSION_REVERSE = 'recommerce.tradein.reverse';

    public function __construct(
        protected AuthorizationGate $authorizationGate,
        protected TradeInPricingService $pricingService,
        protected UltimatePosPurchaseWriter $purchaseWriter,
        protected DeviceEventRecorder $eventRecorder
    ) {
    }

    /** Create a new immutable policy version and retire only its predecessor. */
    public function createRuleSet(User $user, array $command): TradeInRuleSet
    {
        foreach (['business_id', 'location_id', 'variation_id'] as $key) {
            if (! isset($command[$key]) || ! is_numeric($command[$key]) || (int) $command[$key] < 1) {
                throw new LogicException('Trade-in pricing rule requires a valid '.$key.'.');
            }
        }
        $businessId = (int) $command['business_id'];
        $locationId = (int) $command['location_id'];
        $variationId = (int) $command['variation_id'];
        $ruleCode = strtoupper(trim((string) ($command['rule_code'] ?? '')));
        if (! preg_match('/^[A-Z0-9_]{1,64}$/', $ruleCode)) {
            throw new LogicException('Trade-in pricing rule code is invalid.');
        }
        $parameters = $this->pricingService->normaliseParameters((array) ($command['parameters'] ?? []));
        $this->assertActorBusiness($user, $businessId);
        $this->assertRuleContext($businessId, $locationId, $variationId);
        $this->assertWrite($user, self::PERMISSION_MANAGE, [
            'business_id' => $businessId,
            'location_id' => $locationId,
            'variation_id' => $variationId,
        ]);

        return DB::transaction(function () use ($user, $businessId, $ruleCode, $parameters): TradeInRuleSet {
            DB::table('business')->where('id', $businessId)->lockForUpdate()->first();
            $existing = TradeInRuleSet::query()
                ->where('business_id', $businessId)
                ->where('rule_code', $ruleCode)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->get();
            $version = ((int) TradeInRuleSet::query()
                ->where('business_id', $businessId)
                ->where('rule_code', $ruleCode)
                ->lockForUpdate()
                ->max('version_number')) + 1;
            foreach ($existing as $rule) {
                $rule->status = 'RETIRED';
                $rule->retired_at = now();
                $rule->retired_by = $user->getAuthIdentifier();
                $rule->save();
            }

            return TradeInRuleSet::create([
                'business_id' => $businessId,
                'rule_code' => $ruleCode,
                'version_number' => $version,
                'status' => 'ACTIVE',
                'parameters_json' => $parameters,
                'effective_at' => now(),
                'created_by' => $user->getAuthIdentifier(),
            ]);
        });
    }

    /**
     * Create one immutable valuation snapshot. A later negotiation or changed
     * inspection is a new valuation version; it never edits this one.
     */
    public function createValuation(User $user, array $command): TradeInValuation
    {
        $normalized = $this->normaliseValuation($command);
        $this->assertActorBusiness($user, $normalized['business_id']);
        $this->assertWrite($user, self::PERMISSION_MANAGE, $normalized);

        return DB::transaction(function () use ($user, $normalized): TradeInValuation {
            DB::table('business')->where('id', $normalized['business_id'])->lockForUpdate()->first();

            $existing = TradeInValuation::query()
                ->where('business_id', $normalized['business_id'])
                ->where('command_uuid', $normalized['command_uuid'])
                ->first();
            if ($existing) {
                return $existing->load('marketEvidence');
            }

            $ruleSet = TradeInRuleSet::query()
                ->whereKey($normalized['rule_set_id'])
                ->where('business_id', $normalized['business_id'])
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->first();
            if (! $ruleSet) {
                throw new LogicException('The selected active pricing rule set is unavailable.');
            }

            $this->assertReferences($normalized);
            $device = Device::query()
                ->whereKey($normalized['device_id'])
                ->where('business_id', $normalized['business_id'])
                ->lockForUpdate()
                ->first();
            $this->assertCustomerDevice($device, $normalized);

            $open = TradeInValuation::query()
                ->where('business_id', $normalized['business_id'])
                ->where('device_id', $normalized['device_id'])
                ->whereIn('status', [
                    TradeInValuation::STATUS_READY_TO_ACCEPT,
                    TradeInValuation::STATUS_PENDING_APPROVAL,
                    TradeInValuation::STATUS_APPROVED,
                ])
                ->lockForUpdate()
                ->first();
            if ($open) {
                throw new LogicException('This Device already has an open trade-in valuation.');
            }

            $this->recordCustomerHandInIfNeeded($device, $normalized, (int) $user->getAuthIdentifier());
            $calculation = $this->pricingService->calculate($ruleSet, $normalized);
            $recommendation = $calculation['recommendation'];
            $requiresApproval = $normalized['staff_proposed_amount'] > $recommendation['negotiation_ceiling_amount'];
            $version = ((int) TradeInValuation::query()
                ->where('business_id', $normalized['business_id'])
                ->where('device_id', $normalized['device_id'])
                ->lockForUpdate()
                ->max('version_number')) + 1;

            $snapshot = $calculation + [
                'inspection' => $normalized['inspection'],
                'market_evidence' => $normalized['market_evidence'],
                'market_reference_amount' => $normalized['market_reference_amount'],
                'staff_proposed_amount' => $normalized['staff_proposed_amount'],
                'customer_requested_amount' => $normalized['customer_requested_amount'],
                'created_at' => now()->toISOString(),
            ];

            $valuation = TradeInValuation::create([
                'valuation_uuid' => (string) Str::uuid(),
                'command_uuid' => $normalized['command_uuid'],
                'business_id' => $normalized['business_id'],
                'location_id' => $normalized['location_id'],
                'device_id' => $normalized['device_id'],
                'customer_contact_id' => $normalized['customer_contact_id'],
                'supplier_contact_id' => $normalized['supplier_contact_id'],
                'product_id' => $normalized['product_id'],
                'variation_id' => $normalized['variation_id'],
                'rule_set_id' => $ruleSet->id,
                'version_number' => $version,
                'supersedes_valuation_id' => $normalized['supersedes_valuation_id'],
                'status' => $requiresApproval ? TradeInValuation::STATUS_PENDING_APPROVAL : TradeInValuation::STATUS_READY_TO_ACCEPT,
                'inspection_json' => $normalized['inspection'],
                'pricing_snapshot_json' => $snapshot,
                'market_low_amount' => $normalized['market_low_amount'],
                'market_high_amount' => $normalized['market_high_amount'],
                'market_reference_amount' => $normalized['market_reference_amount'],
                'expected_resale_amount' => $normalized['expected_resale_amount'],
                'expected_refurbishment_amount' => $normalized['expected_refurbishment_amount'],
                'opening_offer_amount' => $recommendation['opening_offer_amount'],
                'target_acquisition_amount' => $recommendation['target_acquisition_amount'],
                'negotiation_ceiling_amount' => $recommendation['negotiation_ceiling_amount'],
                'economic_ceiling_amount' => $recommendation['economic_ceiling_amount'],
                'staff_proposed_amount' => $normalized['staff_proposed_amount'],
                'customer_requested_amount' => $normalized['customer_requested_amount'],
                'final_acquisition_amount' => $normalized['staff_proposed_amount'],
                'currency' => $normalized['currency'],
                'approval_required' => $requiresApproval,
                'created_by' => $user->getAuthIdentifier(),
            ]);

            foreach ($normalized['market_evidence'] as $evidence) {
                TradeInMarketEvidence::create($evidence + [
                    'valuation_id' => $valuation->id,
                    'business_id' => $valuation->business_id,
                    'currency' => $valuation->currency,
                    'recorded_by' => $user->getAuthIdentifier(),
                ]);
            }

            $this->eventRecorder->recordLifecycle($device->fresh(), 'TRADE_IN_VALUED', (int) $user->getAuthIdentifier(), null, [
                'trade_in_valuation_id' => (int) $valuation->id,
                'valuation_status' => $valuation->status,
            ]);

            return $valuation->fresh(['marketEvidence']);
        });
    }

    public function approve(User $user, TradeInValuation $valuation, string $reason): TradeInValuation
    {
        $this->assertActorBusiness($user, (int) $valuation->business_id);
        $this->assertWrite($user, self::PERMISSION_APPROVE, [
            'business_id' => (int) $valuation->business_id,
            'location_id' => (int) $valuation->location_id,
            'variation_id' => (int) $valuation->variation_id,
        ]);

        return DB::transaction(function () use ($user, $valuation, $reason): TradeInValuation {
            $locked = TradeInValuation::query()->whereKey($valuation->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== TradeInValuation::STATUS_PENDING_APPROVAL) {
                throw new LogicException('Only a pending trade-in valuation can be approved.');
            }
            if (trim($reason) === '') {
                throw new LogicException('Trade-in approval requires a reason or evidence reference.');
            }
            if ((float) $locked->staff_proposed_amount > (float) $locked->economic_ceiling_amount
                && ! $this->authorizationGate->allowsWrite(
                    $user,
                    self::PERMISSION_OVERRIDE_ECONOMIC,
                    $locked->business_id,
                    $locked->location_id,
                    $locked->variation_id
                )) {
                throw new AuthorizationException('An economic-ceiling override requires the designated permission.');
            }

            $locked->status = TradeInValuation::STATUS_APPROVED;
            $locked->approved_by = $user->getAuthIdentifier();
            $locked->approved_at = now();
            $locked->approval_reason = mb_substr(trim($reason), 0, 2000);
            $locked->lock_version = (int) $locked->lock_version + 1;
            $locked->save();

            return $locked;
        });
    }

    /** Post exactly one native UltimatePOS purchase, then append physical evidence. */
    public function accept(User $user, TradeInValuation $valuation, string $commandUuid): DeviceAcquisition
    {
        $this->assertUuid($commandUuid, 'Trade-in acceptance');
        $this->assertActorBusiness($user, (int) $valuation->business_id);
        $this->assertWrite($user, self::PERMISSION_ACCEPT, [
            'business_id' => (int) $valuation->business_id,
            'location_id' => (int) $valuation->location_id,
            'variation_id' => (int) $valuation->variation_id,
        ]);

        return DB::transaction(function () use ($user, $valuation, $commandUuid): DeviceAcquisition {
            DB::table('business')->where('id', $valuation->business_id)->lockForUpdate()->first();

            $existing = DeviceAcquisition::query()
                ->where('business_id', $valuation->business_id)
                ->where('command_uuid', $commandUuid)
                ->first();
            if ($existing) {
                if ((int) $existing->trade_in_valuation_id !== (int) $valuation->id) {
                    throw new LogicException('Trade-in acceptance idempotency key was reused for a different valuation.');
                }

                return $existing;
            }

            $locked = TradeInValuation::query()->whereKey($valuation->id)->lockForUpdate()->first();
            if (! $locked || ! in_array($locked->status, [TradeInValuation::STATUS_READY_TO_ACCEPT, TradeInValuation::STATUS_APPROVED], true)) {
                throw new LogicException('This trade-in valuation is not approved for acceptance.');
            }
            if ((float) $locked->staff_proposed_amount > (float) $locked->negotiation_ceiling_amount
                && $locked->status !== TradeInValuation::STATUS_APPROVED) {
                throw new LogicException('The proposed amount requires recorded approval before acceptance.');
            }

            $device = Device::query()->whereKey($locked->device_id)->lockForUpdate()->first();
            $this->assertCustomerDevice($device, [
                'business_id' => (int) $locked->business_id,
                'location_id' => (int) $locked->location_id,
                'customer_contact_id' => (int) $locked->customer_contact_id,
                'product_id' => (int) $locked->product_id,
                'variation_id' => (int) $locked->variation_id,
            ]);
            $this->assertReferences([
                'business_id' => (int) $locked->business_id,
                'location_id' => (int) $locked->location_id,
                'customer_contact_id' => (int) $locked->customer_contact_id,
                'supplier_contact_id' => (int) $locked->supplier_contact_id,
                'product_id' => (int) $locked->product_id,
                'variation_id' => (int) $locked->variation_id,
            ]);

            $amount = round((float) $locked->final_acquisition_amount, 4);
            $receipt = $this->purchaseWriter->write($user, [
                'business_id' => (int) $locked->business_id,
                'location_id' => (int) $locked->location_id,
                'product_id' => (int) $locked->product_id,
                'variation_id' => (int) $locked->variation_id,
                'purchase' => [
                    'contact_id' => (int) $locked->supplier_contact_id,
                    'transaction_date' => now()->toDateString(),
                    'unit_purchase_price' => $amount,
                    'unit_purchase_price_inc_tax' => $amount,
                    'unit_item_tax' => 0,
                    'tax_id' => null,
                    'additional_notes' => 'Trade-in valuation '.$locked->valuation_uuid,
                ],
                'units' => [['trade_in_valuation_id' => (int) $locked->id]],
            ]);

            $this->closeOpenPeriods($device, (int) $user->getAuthIdentifier());
            $movement = DeviceMovement::create([
                'device_id' => $device->id,
                'business_id' => $device->business_id,
                'movement_type' => 'TRADE_IN_ACQUISITION',
                'from_custody_kind' => $device->custody_kind,
                'from_location_id' => $device->current_location_id,
                'to_custody_kind' => 'LOCATION',
                'to_location_id' => $locked->location_id,
                'source_transaction_id' => $receipt['transaction_id'],
                'source_line_id' => $receipt['purchase_line_id'],
                'source_line_type' => 'PURCHASE_LINE',
                'command_uuid' => $commandUuid,
                'occurred_at' => now(),
                'recorded_by' => $user->getAuthIdentifier(),
            ]);
            $device->update([
                'ownership_kind' => 'BUSINESS',
                'current_owner_contact_id' => null,
                'custody_kind' => 'LOCATION',
                'current_location_id' => $locked->location_id,
                'product_id' => $locked->product_id,
                'variation_id' => $locked->variation_id,
                'lifecycle_state' => 'AVAILABLE',
                'stock_participation' => 'ON_HAND',
                'acquired_at' => now(),
                'updated_by' => $user->getAuthIdentifier(),
                'lock_version' => (int) $device->lock_version + 1,
            ]);
            OwnershipPeriod::create([
                'device_id' => $device->id,
                'business_id' => $device->business_id,
                'owner_kind' => 'BUSINESS',
                'starts_at' => now(),
                'open_period_key' => $device->id,
                'acquisition_transaction_id' => $receipt['transaction_id'],
                'reason' => 'TRADE_IN',
                'recorded_by' => $user->getAuthIdentifier(),
            ]);
            CustodyPeriod::create([
                'device_id' => $device->id,
                'business_id' => $device->business_id,
                'custody_kind' => 'LOCATION',
                'location_id' => $locked->location_id,
                'starts_at' => now(),
                'open_period_key' => $device->id,
                'source_movement_id' => $movement->id,
                'reason' => 'TRADE_IN',
                'recorded_by' => $user->getAuthIdentifier(),
            ]);
            $acquisition = DeviceAcquisition::create([
                'acquisition_uuid' => (string) Str::uuid(),
                'command_uuid' => $commandUuid,
                'business_id' => $device->business_id,
                'device_id' => $device->id,
                'trade_in_valuation_id' => $locked->id,
                'seller_contact_id' => $locked->customer_contact_id,
                'supplier_contact_id' => $locked->supplier_contact_id,
                'location_id' => $locked->location_id,
                'acquisition_source' => 'TRADE_IN',
                'transaction_id' => $receipt['transaction_id'],
                'purchase_line_id' => $receipt['purchase_line_id'],
                'acquisition_amount' => $amount,
                'currency' => $locked->currency,
                'posted_at' => now(),
                'recorded_by' => $user->getAuthIdentifier(),
            ]);
            $locked->status = TradeInValuation::STATUS_ACCEPTED;
            $locked->accepted_at = now();
            $locked->lock_version = (int) $locked->lock_version + 1;
            $locked->save();
            $this->eventRecorder->recordLifecycle($device->fresh(), 'ACQUISITION_POSTED', (int) $user->getAuthIdentifier(), $receipt['transaction_id'], [
                'acquisition_id' => (int) $acquisition->id,
                'trade_in_valuation_id' => (int) $locked->id,
                'purchase_line_id' => (int) $receipt['purchase_line_id'],
            ]);

            return $acquisition;
        });
    }

    /** Reject an opportunity and return only physical custody to the customer. */
    public function reject(User $user, TradeInValuation $valuation, string $reason): TradeInValuation
    {
        $this->assertActorBusiness($user, (int) $valuation->business_id);
        $this->assertWrite($user, self::PERMISSION_MANAGE, [
            'business_id' => (int) $valuation->business_id,
            'location_id' => (int) $valuation->location_id,
            'variation_id' => (int) $valuation->variation_id,
        ]);

        return DB::transaction(function () use ($user, $valuation, $reason): TradeInValuation {
            $locked = TradeInValuation::query()->whereKey($valuation->id)->lockForUpdate()->first();
            if (! $locked || ! in_array($locked->status, [TradeInValuation::STATUS_READY_TO_ACCEPT, TradeInValuation::STATUS_PENDING_APPROVAL, TradeInValuation::STATUS_APPROVED], true)) {
                throw new LogicException('Only an open trade-in valuation can be rejected.');
            }
            if (trim($reason) === '') {
                throw new LogicException('A rejected trade-in requires a reason.');
            }
            $device = Device::query()->whereKey($locked->device_id)->lockForUpdate()->firstOrFail();
            if ($device->ownership_kind !== 'CUSTOMER' || (int) $device->current_owner_contact_id !== (int) $locked->customer_contact_id) {
                throw new LogicException('The customer-owned Device is no longer eligible for rejection handover.');
            }
            $this->returnCustomerCustody($device, $locked, (int) $user->getAuthIdentifier(), 'TRADE_IN_REJECTED');
            $locked->status = TradeInValuation::STATUS_REJECTED;
            $locked->rejected_at = now();
            $locked->rejected_by = $user->getAuthIdentifier();
            $locked->rejection_reason = mb_substr(trim($reason), 0, 255);
            $locked->lock_version = (int) $locked->lock_version + 1;
            $locked->save();
            $this->eventRecorder->recordLifecycle($device->fresh(), 'TRADE_IN_REJECTED', (int) $user->getAuthIdentifier(), null, [
                'trade_in_valuation_id' => (int) $locked->id,
            ]);

            return $locked;
        });
    }

    /**
     * Record the physical inverse only after UltimatePOS has posted its own
     * purchase-return transaction. This service never creates financial reversals.
     */
    public function recordReversal(User $user, DeviceAcquisition $acquisition, int $reversalTransactionId, string $commandUuid, string $reason): DeviceAcquisitionReversal
    {
        $this->assertUuid($commandUuid, 'Trade-in reversal');
        $this->assertActorBusiness($user, (int) $acquisition->business_id);
        $this->assertWrite($user, self::PERMISSION_REVERSE, [
            'business_id' => (int) $acquisition->business_id,
            'location_id' => (int) $acquisition->location_id,
            'variation_id' => (int) Device::query()->whereKey($acquisition->device_id)->value('variation_id'),
        ]);
        if ($reversalTransactionId < 1 || trim($reason) === '') {
            throw new LogicException('A native purchase-return reference and reversal reason are required.');
        }

        return DB::transaction(function () use ($user, $acquisition, $reversalTransactionId, $commandUuid, $reason): DeviceAcquisitionReversal {
            $locked = DeviceAcquisition::query()->whereKey($acquisition->id)->lockForUpdate()->firstOrFail();
            $existing = DeviceAcquisitionReversal::query()
                ->where('business_id', $locked->business_id)
                ->where('command_uuid', $commandUuid)
                ->first();
            if ($existing) {
                if ((int) $existing->acquisition_id !== (int) $locked->id) {
                    throw new LogicException('Trade-in reversal idempotency key was reused for a different acquisition.');
                }

                return $existing;
            }
            if (DeviceAcquisitionReversal::query()->where('acquisition_id', $locked->id)->exists()) {
                throw new LogicException('This acquisition has already been reversed.');
            }
            $return = Transaction::query()
                ->where('id', $reversalTransactionId)
                ->where('business_id', $locked->business_id)
                ->where('type', 'purchase_return')
                ->where('return_parent_id', $locked->transaction_id)
                ->lockForUpdate()
                ->first();
            if (! $return) {
                throw new LogicException('A matching native UltimatePOS purchase return is required before recording reversal.');
            }
            $device = Device::query()->whereKey($locked->device_id)->lockForUpdate()->firstOrFail();
            if ($device->ownership_kind !== 'BUSINESS' || $device->stock_participation !== 'ON_HAND'
                || (int) $device->current_location_id !== (int) $locked->location_id) {
                throw new LogicException('Trade-in reversal is blocked after the Device has left its acquired on-hand state.');
            }

            $this->closeOpenPeriods($device, (int) $user->getAuthIdentifier());
            $movement = DeviceMovement::create([
                'device_id' => $device->id,
                'business_id' => $device->business_id,
                'movement_type' => 'TRADE_IN_REVERSAL',
                'from_custody_kind' => 'LOCATION',
                'from_location_id' => $locked->location_id,
                'to_custody_kind' => 'CUSTOMER',
                'source_transaction_id' => $return->id,
                'source_line_type' => 'PURCHASE_RETURN',
                'command_uuid' => $commandUuid,
                'occurred_at' => now(),
                'recorded_by' => $user->getAuthIdentifier(),
                'reason' => mb_substr(trim($reason), 0, 255),
            ]);
            $device->update([
                'ownership_kind' => 'CUSTOMER',
                'current_owner_contact_id' => $locked->seller_contact_id,
                'custody_kind' => 'CUSTOMER',
                'current_location_id' => null,
                'lifecycle_state' => 'CUSTOMER_CUSTODY',
                'stock_participation' => 'NONE',
                'acquired_at' => null,
                'updated_by' => $user->getAuthIdentifier(),
                'lock_version' => (int) $device->lock_version + 1,
            ]);
            OwnershipPeriod::create([
                'device_id' => $device->id,
                'business_id' => $device->business_id,
                'owner_kind' => 'CUSTOMER',
                'contact_id' => $locked->seller_contact_id,
                'starts_at' => now(),
                'open_period_key' => $device->id,
                'reason' => 'TRADE_IN_REVERSAL',
                'recorded_by' => $user->getAuthIdentifier(),
            ]);
            CustodyPeriod::create([
                'device_id' => $device->id,
                'business_id' => $device->business_id,
                'custody_kind' => 'CUSTOMER',
                'starts_at' => now(),
                'open_period_key' => $device->id,
                'source_movement_id' => $movement->id,
                'reason' => 'TRADE_IN_REVERSAL',
                'recorded_by' => $user->getAuthIdentifier(),
            ]);
            $reversal = DeviceAcquisitionReversal::create([
                'command_uuid' => $commandUuid,
                'business_id' => $locked->business_id,
                'acquisition_id' => $locked->id,
                'reversal_transaction_id' => $return->id,
                'reason' => mb_substr(trim($reason), 0, 255),
                'recorded_by' => $user->getAuthIdentifier(),
                'reversed_at' => now(),
            ]);
            TradeInValuation::query()->whereKey($locked->trade_in_valuation_id)->update([
                'status' => TradeInValuation::STATUS_REVERSED,
                'lock_version' => DB::raw('lock_version + 1'),
                'updated_at' => now(),
            ]);
            $this->eventRecorder->recordLifecycle($device->fresh(), 'ACQUISITION_REVERSED', (int) $user->getAuthIdentifier(), $return->id, [
                'acquisition_id' => (int) $locked->id,
                'reversal_id' => (int) $reversal->id,
            ]);

            return $reversal;
        });
    }

    /** Guard rule publication against a configured but stale/foreign scope. */
    protected function assertRuleContext(int $businessId, int $locationId, int $variationId): void
    {
        $locationExists = DB::table('business_locations')
            ->where('id', $locationId)
            ->where('business_id', $businessId)
            ->exists();
        $variationExists = DB::table('variations')
            ->join('products', 'products.id', '=', 'variations.product_id')
            ->where('variations.id', $variationId)
            ->where('products.business_id', $businessId)
            ->exists();
        if (! $locationExists || ! $variationExists) {
            throw new LogicException('Trade-in pricing rule requires an authorised location and product variation in this business.');
        }
    }

    /** @return array<string, mixed> */
    protected function normaliseValuation(array $command): array
    {
        foreach (['business_id', 'location_id', 'device_id', 'customer_contact_id', 'supplier_contact_id', 'product_id', 'variation_id', 'rule_set_id'] as $key) {
            if (! isset($command[$key]) || ! is_numeric($command[$key]) || (int) $command[$key] < 1) {
                throw new LogicException('Trade-in valuation requires a valid '.$key.'.');
            }
        }
        $this->assertUuid((string) ($command['command_uuid'] ?? ''), 'Trade-in valuation');
        $currency = strtoupper(trim((string) ($command['currency'] ?? 'MYR')));
        if (! preg_match('/^[A-Z]{3,12}$/', $currency)) {
            throw new LogicException('Trade-in valuation currency is invalid.');
        }
        $inspection = $this->normaliseInspection($command['inspection'] ?? []);
        $evidence = $this->normaliseEvidence($command['market_evidence'] ?? []);
        $marketReference = $this->money($command['market_reference_amount'] ?? null, 'Market reference amount');
        $evidenceAmounts = array_column($evidence, 'reference_amount');
        if ($marketReference < min($evidenceAmounts) || $marketReference > max($evidenceAmounts)) {
            throw new LogicException('Market reference amount must be within the recorded evidence range.');
        }

        return [
            'business_id' => (int) $command['business_id'],
            'location_id' => (int) $command['location_id'],
            'device_id' => (int) $command['device_id'],
            'customer_contact_id' => (int) $command['customer_contact_id'],
            'supplier_contact_id' => (int) $command['supplier_contact_id'],
            'product_id' => (int) $command['product_id'],
            'variation_id' => (int) $command['variation_id'],
            'rule_set_id' => (int) $command['rule_set_id'],
            'supersedes_valuation_id' => isset($command['supersedes_valuation_id']) && (int) $command['supersedes_valuation_id'] > 0 ? (int) $command['supersedes_valuation_id'] : null,
            'command_uuid' => strtolower((string) $command['command_uuid']),
            'currency' => $currency,
            'inspection' => $inspection,
            'market_evidence' => $evidence,
            'market_low_amount' => min($evidenceAmounts),
            'market_high_amount' => max($evidenceAmounts),
            'market_reference_amount' => $marketReference,
            'expected_resale_amount' => $this->money($command['expected_resale_amount'] ?? null, 'Expected resale amount'),
            'expected_refurbishment_amount' => $this->money($command['expected_refurbishment_amount'] ?? 0, 'Expected refurbishment amount'),
            'staff_proposed_amount' => $this->money($command['staff_proposed_amount'] ?? null, 'Staff proposed amount'),
            'customer_requested_amount' => array_key_exists('customer_requested_amount', $command) && $command['customer_requested_amount'] !== null && $command['customer_requested_amount'] !== ''
                ? $this->money($command['customer_requested_amount'], 'Customer requested amount')
                : null,
        ];
    }

    /** @return array<string, mixed> */
    protected function normaliseInspection($inspection): array
    {
        if (! is_array($inspection)) {
            throw new LogicException('Trade-in inspection must be structured data.');
        }
        $batteryHealth = $inspection['battery_health_percent'] ?? null;
        if ($batteryHealth !== null && (! is_numeric($batteryHealth) || (float) $batteryHealth < 0 || (float) $batteryHealth > 100)) {
            throw new LogicException('Battery health must be between 0 and 100 percent.');
        }
        $replacement = strtoupper((string) ($inspection['battery_replacement_needed'] ?? 'NO'));
        if (! in_array($replacement, ['YES', 'NO', 'CONDITIONAL'], true)) {
            throw new LogicException('Battery replacement status is invalid.');
        }
        $cosmeticGrade = strtoupper((string) ($inspection['cosmetic_grade'] ?? ''));
        if (! in_array($cosmeticGrade, ['A', 'B', 'C', 'D'], true)) {
            throw new LogicException('Cosmetic grade must be A, B, C, or D.');
        }
        $functional = $inspection['functional_observations'] ?? [];
        if (! is_array($functional) || count($functional) > 40) {
            throw new LogicException('Functional observations are invalid.');
        }
        $normalisedFunctional = [];
        foreach ($functional as $observation) {
            if (! is_array($observation) || ! isset($observation['key'], $observation['outcome'])
                || ! preg_match('/^[A-Z0-9_]{1,64}$/', strtoupper((string) $observation['key']))
                || ! in_array(strtoupper((string) $observation['outcome']), ['PASS', 'FAIL', 'CONDITIONAL', 'NOT_TESTED'], true)) {
                throw new LogicException('Each functional observation needs a supported key and outcome.');
            }
            $normalisedFunctional[] = [
                'key' => strtoupper((string) $observation['key']),
                'outcome' => strtoupper((string) $observation['outcome']),
                'notes' => isset($observation['notes']) ? mb_substr(trim((string) $observation['notes']), 0, 1000) : null,
            ];
        }

        return [
            'battery_health_percent' => $batteryHealth === null ? null : round((float) $batteryHealth, 2),
            'battery_replacement_needed' => $replacement,
            'battery_replacement_estimate_amount' => $this->money($inspection['battery_replacement_estimate_amount'] ?? 0, 'Battery replacement estimate'),
            'cosmetic_grade' => $cosmeticGrade,
            'cosmetic_notes' => isset($inspection['cosmetic_notes']) ? mb_substr(trim((string) $inspection['cosmetic_notes']), 0, 2000) : null,
            'functional_observations' => $normalisedFunctional,
            'accessories_notes' => isset($inspection['accessories_notes']) ? mb_substr(trim((string) $inspection['accessories_notes']), 0, 2000) : null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function normaliseEvidence($evidence): array
    {
        if (! is_array($evidence) || $evidence === [] || count($evidence) > 20) {
            throw new LogicException('At least one bounded market-evidence record is required.');
        }
        $normalised = [];
        foreach (array_values($evidence) as $item) {
            if (! is_array($item)) {
                throw new LogicException('Market evidence is invalid.');
            }
            $type = strtoupper(trim((string) ($item['evidence_type'] ?? 'MARKETPLACE')));
            if (! preg_match('/^[A-Z0-9_]{1,32}$/', $type)) {
                throw new LogicException('Market evidence type is invalid.');
            }
            $source = trim((string) ($item['source_description'] ?? ''));
            if ($source === '' || mb_strlen($source) > 320) {
                throw new LogicException('Market evidence needs a bounded source description.');
            }
            $url = isset($item['reference_url']) ? trim((string) $item['reference_url']) : null;
            if ($url !== null && $url !== '' && (! filter_var($url, FILTER_VALIDATE_URL) || mb_strlen($url) > 1000)) {
                throw new LogicException('Market evidence reference URL is invalid.');
            }
            $observedAt = isset($item['observed_at']) && trim((string) $item['observed_at']) !== ''
                ? Carbon::parse((string) $item['observed_at'])
                : now();
            $normalised[] = [
                'evidence_type' => $type,
                'reference_amount' => $this->money($item['reference_amount'] ?? null, 'Market evidence amount'),
                'source_description' => $source,
                'reference_url' => $url === '' ? null : $url,
                'observed_at' => $observedAt,
            ];
        }

        return $normalised;
    }

    protected function assertReferences(array $data): void
    {
        if (! BusinessLocation::query()->where('id', $data['location_id'])->where('business_id', $data['business_id'])->exists()) {
            throw new LogicException('Trade-in location is outside the business.');
        }
        if (! Product::query()->where('id', $data['product_id'])->where('business_id', $data['business_id'])->exists()
            || ! Variation::query()->where('id', $data['variation_id'])->where('product_id', $data['product_id'])->exists()) {
            throw new LogicException('Trade-in requires an existing UltimatePOS product and variation mapping.');
        }
        if (! Contact::query()->where('id', $data['customer_contact_id'])->where('business_id', $data['business_id'])->whereIn('type', ['customer', 'both'])->exists()) {
            throw new LogicException('Trade-in customer contact is invalid.');
        }
        if (! Contact::query()->where('id', $data['supplier_contact_id'])->where('business_id', $data['business_id'])->whereIn('type', ['supplier', 'both'])->exists()) {
            throw new LogicException('Trade-in requires an explicitly selected supplier-capable contact.');
        }
    }

    protected function assertCustomerDevice(?Device $device, array $data): void
    {
        if (! $device || $device->ownership_kind !== 'CUSTOMER'
            || (int) $device->current_owner_contact_id !== (int) $data['customer_contact_id']
            || $device->stock_participation !== 'NONE') {
            throw new LogicException('Trade-in requires the selected customer-owned Device.');
        }
        if ($device->current_location_id !== null && (int) $device->current_location_id !== (int) $data['location_id']) {
            throw new LogicException('Customer Device is in a different location.');
        }
        if (($device->product_id !== null && (int) $device->product_id !== (int) $data['product_id'])
            || ($device->variation_id !== null && (int) $device->variation_id !== (int) $data['variation_id'])) {
            throw new LogicException('Selected product and variation do not match the existing Device mapping.');
        }
    }

    protected function recordCustomerHandInIfNeeded(Device $device, array $data, int $actorId): void
    {
        if ($device->custody_kind === 'LOCATION' && (int) $device->current_location_id === (int) $data['location_id']) {
            return;
        }
        if ($device->custody_kind !== 'CUSTOMER' || $device->current_location_id !== null) {
            throw new LogicException('Customer Device custody cannot be prepared for trade-in.');
        }
        CustodyPeriod::query()->where('device_id', $device->id)->whereNotNull('open_period_key')
            ->update(['open_period_key' => null, 'ends_at' => now(), 'recorded_by' => $actorId]);
        $movement = DeviceMovement::create([
            'device_id' => $device->id,
            'business_id' => $device->business_id,
            'movement_type' => 'TRADE_IN_INTAKE',
            'from_custody_kind' => 'CUSTOMER',
            'to_custody_kind' => 'LOCATION',
            'to_location_id' => $data['location_id'],
            'command_uuid' => $data['command_uuid'],
            'occurred_at' => now(),
            'recorded_by' => $actorId,
        ]);
        $device->update([
            'custody_kind' => 'LOCATION',
            'current_location_id' => $data['location_id'],
            'lifecycle_state' => 'CUSTOMER_CUSTODY',
            'updated_by' => $actorId,
            'lock_version' => (int) $device->lock_version + 1,
        ]);
        CustodyPeriod::create([
            'device_id' => $device->id,
            'business_id' => $device->business_id,
            'custody_kind' => 'LOCATION',
            'location_id' => $data['location_id'],
            'starts_at' => now(),
            'open_period_key' => $device->id,
            'source_movement_id' => $movement->id,
            'reason' => 'TRADE_IN_INTAKE',
            'recorded_by' => $actorId,
        ]);
    }

    protected function returnCustomerCustody(Device $device, TradeInValuation $valuation, int $actorId, string $movementType): void
    {
        if ($device->custody_kind === 'CUSTOMER') {
            return;
        }
        if ($device->custody_kind !== 'LOCATION' || (int) $device->current_location_id !== (int) $valuation->location_id) {
            throw new LogicException('Customer Device custody cannot be returned from this location.');
        }
        CustodyPeriod::query()->where('device_id', $device->id)->whereNotNull('open_period_key')
            ->update(['open_period_key' => null, 'ends_at' => now(), 'recorded_by' => $actorId]);
        $movement = DeviceMovement::create([
            'device_id' => $device->id,
            'business_id' => $device->business_id,
            'movement_type' => $movementType,
            'from_custody_kind' => 'LOCATION',
            'from_location_id' => $valuation->location_id,
            'to_custody_kind' => 'CUSTOMER',
            'occurred_at' => now(),
            'recorded_by' => $actorId,
        ]);
        $device->update([
            'custody_kind' => 'CUSTOMER',
            'current_location_id' => null,
            'lifecycle_state' => 'CUSTOMER_CUSTODY',
            'updated_by' => $actorId,
            'lock_version' => (int) $device->lock_version + 1,
        ]);
        CustodyPeriod::create([
            'device_id' => $device->id,
            'business_id' => $device->business_id,
            'custody_kind' => 'CUSTOMER',
            'starts_at' => now(),
            'open_period_key' => $device->id,
            'source_movement_id' => $movement->id,
            'reason' => $movementType,
            'recorded_by' => $actorId,
        ]);
    }

    protected function closeOpenPeriods(Device $device, int $actorId): void
    {
        OwnershipPeriod::query()->where('device_id', $device->id)->whereNotNull('open_period_key')
            ->update(['open_period_key' => null, 'ends_at' => now(), 'recorded_by' => $actorId]);
        CustodyPeriod::query()->where('device_id', $device->id)->whereNotNull('open_period_key')
            ->update(['open_period_key' => null, 'ends_at' => now(), 'recorded_by' => $actorId]);
    }

    protected function assertActorBusiness(User $user, int $businessId): void
    {
        if ((int) $user->business_id !== $businessId) {
            throw new AuthorizationException('Recommerce business scope denied.');
        }
    }

    protected function assertWrite(User $user, string $permission, array $scope): void
    {
        if (! $this->authorizationGate->allowsWrite($user, $permission, $scope['business_id'], $scope['location_id'], $scope['variation_id'])) {
            throw new AuthorizationException('Recommerce trade-in scope denied.');
        }
    }

    protected function assertUuid(string $value, string $label): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw new LogicException($label.' requires a valid idempotency UUID.');
        }
    }

    protected function money($value, string $label): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
            throw new LogicException($label.' must be a non-negative amount.');
        }

        return round((float) $value, 4);
    }
}
