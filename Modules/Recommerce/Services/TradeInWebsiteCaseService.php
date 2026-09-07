<?php

namespace Modules\Recommerce\Services;

use App\User;
use App\Transaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceAcquisition;
use Modules\Recommerce\Entities\TradeInApprovedOffer;
use Modules\Recommerce\Entities\TradeInCustomerDecision;
use Modules\Recommerce\Entities\TradeInIntake;
use Modules\Recommerce\Entities\TradeInNegotiationEvent;
use Modules\Recommerce\Entities\TradeInValuation;
use Modules\Recommerce\Support\AuthorizationGate;

final class TradeInWebsiteCaseService
{
    public function __construct(
        private AuthorizationGate $authorizationGate,
        private TradeInService $tradeIn,
        private ?TradeInOutboxService $outbox = null,
        private ?TradeInPhotoAiIntakeService $photoAi = null
    ) {
    }

    /** @param array<string, mixed> $command @return array{intake:TradeInIntake,replayed:bool} */
    public function receive(array $command, TradeInAcquisitionCommandAccess $access): array
    {
        $fingerprint = $this->fingerprint($this->submissionFingerprintInput($command));

        return DB::transaction(function () use ($command, $access, $fingerprint): array {
            DB::table('business')->where('id', $access->businessId())->lockForUpdate()->first();
            $existing = TradeInIntake::query()
                ->where('business_id', $access->businessId())
                ->where('source_system', $command['source_system'])
                ->where('external_case_reference', $command['external_case_reference'])
                ->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals((string) $existing->submission_fingerprint, $fingerprint)) {
                    throw new LogicException('Trade-In intake idempotency key was reused for different submission details.');
                }
                return ['intake' => $existing, 'replayed' => true];
            }

            $intake = TradeInIntake::create([
                'intake_uuid' => (string) Str::uuid(),
                'business_id' => $access->businessId(),
                'source_system' => $command['source_system'],
                'external_case_reference' => $command['external_case_reference'],
                'submission_id' => $command['submission_id'],
                'submission_version' => $command['submission_version'],
                'submission_fingerprint' => $fingerprint,
                'category_code' => $command['category'],
                'brand' => $command['brand'] ?: null,
                'model' => $command['model'],
                'specifications_json' => $command['specifications'],
                'declared_condition_json' => $command['declared_condition'],
                'indicative_snapshot_json' => $command['indicative_snapshot'],
                'evidence_references_json' => $command['evidence_references'],
                'customer_name' => $command['customer']['name'],
                'customer_email' => $command['customer']['email'],
                'customer_phone' => $command['customer']['phone'],
                'preferred_branch' => $command['preferred_branch'] ?: null,
                'submitted_at' => $command['submitted_at'],
                'status' => 'SUBMITTED',
                'projection_version' => 1,
            ]);

            if (is_array($command['photo_ai'] ?? null)) {
                ($this->photoAi ?: app(TradeInPhotoAiIntakeService::class))->attach($intake, $command['photo_ai']);
            }

            $this->recordProjection($intake, 'INTAKE_ACKNOWLEDGED');

            return ['intake' => $intake, 'replayed' => false];
        });
    }

    public function linkValuation(User $user, TradeInIntake $intake, TradeInValuation $valuation): TradeInIntake
    {
        $this->assertStaff($user, $intake, TradeInService::PERMISSION_MANAGE, $valuation);

        return DB::transaction(function () use ($intake, $valuation): TradeInIntake {
            $locked = TradeInIntake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
            if ((int) $valuation->business_id !== (int) $locked->business_id) {
                throw new LogicException('The native valuation belongs to a different business.');
            }
            if ($locked->offers()->whereHas('decision')->exists() || in_array($locked->status, ['ACQUIRED', 'DECLINED'], true)) {
                throw new LogicException('A decided Trade-In intake cannot be relinked.');
            }
            $locked->valuation_id = $valuation->id;
            $locked->location_id = $valuation->location_id;
            $locked->status = 'VALUED';
            $locked->projection_version++;
            $locked->save();
            $this->recordProjection($locked, 'VALUATION_LINKED');
            return $locked->fresh('valuation');
        });
    }

    public function publish(User $user, TradeInIntake $intake): TradeInApprovedOffer
    {
        return DB::transaction(function () use ($user, $intake): TradeInApprovedOffer {
            $locked = TradeInIntake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
            $valuation = $locked->valuation_id
                ? TradeInValuation::query()->whereKey($locked->valuation_id)->lockForUpdate()->first()
                : null;
            if (! $valuation) {
                throw new LogicException('Link a completed native Trade-In valuation before publishing an offer.');
            }
            if (in_array($locked->status, ['ACQUIRED', 'DECLINED'], true) || $locked->offers()->whereHas('decision')->exists()) {
                throw new LogicException('A decided Trade-In intake cannot publish another offer.');
            }
            $this->assertStaff($user, $locked, TradeInService::PERMISSION_MANAGE, $valuation);
            if (! in_array($valuation->status, [TradeInValuation::STATUS_READY_TO_ACCEPT, TradeInValuation::STATUS_APPROVED], true)) {
                throw new LogicException('Only a native valuation ready for acceptance can be published.');
            }
            if ($valuation->approval_required && $valuation->status !== TradeInValuation::STATUS_APPROVED) {
                throw new LogicException('This valuation requires the existing SAVERPOS approval before publication.');
            }
            if ((float) $valuation->final_acquisition_amount < 0) {
                throw new LogicException('The native valuation amount is invalid.');
            }

            $published = TradeInApprovedOffer::query()->where('intake_id', $locked->id)
                ->where('status', 'PUBLISHED')->latest('offer_version')->lockForUpdate()->first();
            if ($published && (int) $published->valuation_id === (int) $valuation->id) {
                return $published;
            }

            TradeInApprovedOffer::query()->where('intake_id', $locked->id)->where('status', 'PUBLISHED')->update(['status' => 'SUPERSEDED']);
            $version = ((int) TradeInApprovedOffer::query()->where('intake_id', $locked->id)->lockForUpdate()->max('offer_version')) + 1;
            $snapshot = (array) $valuation->pricing_snapshot_json;
            $projection = [
                'amount_minor' => (int) round(((float) $valuation->final_acquisition_amount) * 100),
                'currency' => (string) $valuation->currency,
                'explanation' => 'Final offer confirmed after SaverBro inspection and approval.',
                'valuation_version' => (int) $valuation->version_number,
                'pricing_policy_version' => (string) data_get($snapshot, 'policy_version', 'SAVERPOS-RULE-'.$valuation->rule_set_id.'-V'.$valuation->version_number),
                'engine_version' => (string) data_get($snapshot, 'engine_version', 'SAVERPOS-TRADEIN-1'),
            ];
            $offer = TradeInApprovedOffer::create([
                'offer_uuid' => (string) Str::uuid(),
                'intake_id' => $locked->id,
                'valuation_id' => $valuation->id,
                'offer_version' => $version,
                'amount' => $valuation->final_acquisition_amount,
                'currency' => $valuation->currency,
                'customer_projection_json' => $projection,
                'status' => 'PUBLISHED',
                'approved_by' => $valuation->approved_by ?: $user->getAuthIdentifier(),
                'approved_at' => $valuation->approved_at ?: now(),
                'published_at' => now(),
            ]);
            $locked->status = 'WAITING_CUSTOMER_DECISION';
            $locked->projection_version++;
            $locked->save();
            $this->recordProjection($locked, 'APPROVED_OFFER_PUBLISHED');
            return $offer;
        });
    }

    /** @param array<string, mixed> $command @return array{decision:TradeInCustomerDecision,replayed:bool} */
    public function decide(TradeInIntake $intake, array $command, TradeInAcquisitionCommandAccess $access): array
    {
        $fingerprint = $this->fingerprint([
            'offer_id' => $command['offer_id'], 'offer_version' => (int) $command['offer_version'],
            'decision' => $command['decision'], 'native_mapping' => $command['native_mapping'] ?? [],
        ]);

        try {
            return DB::transaction(function () use ($intake, $command, $access, $fingerprint): array {
            DB::table('business')->where('id', $access->businessId())->lockForUpdate()->first();
            $locked = TradeInIntake::query()->whereKey($intake->id)->lockForUpdate()->firstOrFail();
            $existing = TradeInCustomerDecision::query()
                ->where('intake_id', $locked->id)->where('idempotency_key', $command['idempotency_key'])
                ->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                    throw new LogicException('Customer decision idempotency key was reused for different details.');
                }
                return ['decision' => $existing, 'replayed' => true];
            }
            $offer = TradeInApprovedOffer::query()->with('valuation')->where('intake_id', $locked->id)
                ->where('offer_uuid', $command['offer_id'])->where('offer_version', $command['offer_version'])
                ->lockForUpdate()->first();
            if (! $offer || $offer->status !== 'PUBLISHED' || $locked->status !== 'WAITING_CUSTOMER_DECISION') {
                throw new LogicException('The selected offer is stale or not eligible for a customer decision.');
            }
            if ($offer->decision()->exists()) {
                throw new LogicException('This offer already has a recorded customer decision.');
            }

            $acquisition = null;
            if ($command['decision'] === 'ACCEPTED') {
                $valuation = $offer->valuation;
                if (! $valuation || (int) $locked->valuation_id !== (int) $valuation->id || ! $access->allows($valuation)) {
                    throw new LogicException('The approved offer is outside the acquisition allowlist.');
                }
                $this->assertMapping($locked, $valuation, (array) $command['native_mapping'], (string) $command['idempotency_key'], $access);
                $actor = User::query()->find($access->actorUserId());
                if (! $actor || (int) $actor->business_id !== (int) $locked->business_id) {
                    throw new LogicException('The acquisition service actor is unavailable for this business.');
                }
                request()->attributes->set('recommerce.approved_customer_decision', true);
                $acquisition = $this->tradeIn->accept($actor, $valuation, (string) $command['idempotency_key']);
                $offer->status = 'ACCEPTED';
                $locked->status = 'ACQUIRED';
            } else {
                $offer->status = 'DECLINED';
                $locked->status = 'DECLINED';
            }
            $offer->save();
            $decision = TradeInCustomerDecision::create([
                'decision_uuid' => (string) Str::uuid(),
                'idempotency_key' => $command['idempotency_key'],
                'request_fingerprint' => $fingerprint,
                'intake_id' => $locked->id,
                'offer_id' => $offer->id,
                'decision' => $command['decision'],
                'acquisition_id' => $acquisition?->id,
                'decided_at' => now(),
            ]);
            $locked->projection_version++;
            $locked->save();
            $this->recordProjection($locked, $locked->status === 'ACQUIRED' ? 'ACQUISITION_COMMITTED' : 'OFFER_DECLINED');
            return ['decision' => $decision, 'replayed' => false];
            });
        } catch (NativeMappingMismatch $error) {
            $context = [
                'failure_class' => 'native_mapping_mismatch', 'trade_in_case_id' => $error->caseReference,
                'pos_case_reference' => $error->posCaseReference, 'acquisition_command_id' => $error->commandId,
                'field' => $error->field, 'expected' => $error->expected, 'received' => $error->received,
                'actor_user_id' => $access->actorUserId(),
            ];
            Log::warning('Trade-In acquisition native mapping mismatch.', $context);
            TradeInNegotiationEvent::create([
                'event_uuid' => (string) Str::uuid(), 'valuation_id' => $error->valuationId,
                'business_id' => $access->businessId(), 'event_type' => TradeInNegotiationEvent::NATIVE_MAPPING_REJECTED,
                'actor_type' => 'SYSTEM', 'amount' => null, 'currency' => 'MYR',
                'note' => sprintf('Website case %s; command %s; %s expected %d received %s.', $error->caseReference, $error->commandId, $error->field, $error->expected, $error->received === null ? 'missing' : (string) $error->received),
                'recorded_by' => $access->actorUserId(), 'occurred_at' => now(),
            ]);
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    public function projection(TradeInIntake $intake): array
    {
        $intake->load(['valuation.device', 'valuation.acquisition', 'offers.decision']);
        $offer = $intake->offers->sortByDesc('offer_version')->first();
        $valuation = $intake->valuation;
        $acquisition = $valuation?->acquisition;
        $transaction = $acquisition ? Transaction::query()->find($acquisition->transaction_id) : null;
        $paymentStatus = strtolower((string) optional($transaction)->payment_status);
        $settlementStatus = match ($paymentStatus) {
            'paid' => 'PAID',
            'partial' => 'PARTIAL',
            'due' => 'PENDING',
            default => 'NOT_RECORDED',
        };
        return [
            'contract_version' => 'trade-in-pos-authority.v2',
            'website_case_reference' => (string) $intake->external_case_reference,
            'pos_case_reference' => (string) $intake->intake_uuid,
            'status' => (string) $intake->status,
            'projection_version' => (int) $intake->projection_version,
            'approved_offer' => $offer ? [
                'offer_id' => (string) $offer->offer_uuid,
                'offer_version' => (int) $offer->offer_version,
                'status' => (string) $offer->status,
            ] + (array) $offer->customer_projection_json : null,
            'native_mapping' => $valuation ? [
                'trade_in_valuation_id' => (int) $valuation->id,
                'location_id' => (int) $valuation->location_id,
                'product_id' => (int) $valuation->product_id,
                'variation_id' => (int) $valuation->variation_id,
                'device_id' => (int) $valuation->device_id,
            ] : null,
            'acquisition' => $acquisition ? [
                'acquisition_id' => (int) $acquisition->id,
                'purchase_id' => (int) $acquisition->transaction_id,
                'device_id' => (int) $acquisition->device_id,
            ] : null,
            'settlement' => [
                'status' => $settlementStatus,
                'amount_minor' => $offer ? (int) round(((float) $offer->amount) * 100) : null,
            ],
        ];
    }

    /** @param array<string, mixed> $received */
    private function assertMapping(TradeInIntake $intake, TradeInValuation $valuation, array $received, string $commandId, TradeInAcquisitionCommandAccess $access): void
    {
        $device = Device::query()->whereKey($valuation->device_id)->where('business_id', $valuation->business_id)->first();
        if (! $device) throw new LogicException('Trade-In native mapping is unavailable.');
        $expected = ['location_id' => (int) $valuation->location_id, 'product_id' => (int) $valuation->product_id, 'variation_id' => (int) $valuation->variation_id, 'device_id' => (int) $device->id];
        foreach ($expected as $field => $value) {
            if (! isset($received[$field]) || (int) $received[$field] !== $value) {
                throw new NativeMappingMismatch(
                    (int) $valuation->id,
                    (string) $intake->external_case_reference,
                    (string) $intake->intake_uuid,
                    $commandId,
                    $field,
                    $value,
                    isset($received[$field]) ? (int) $received[$field] : null
                );
            }
        }
    }

    private function assertStaff(User $user, TradeInIntake $intake, string $permission, TradeInValuation $valuation): void
    {
        if ((int) $user->business_id !== (int) $intake->business_id || ! $this->authorizationGate->allowsWrite($user, $permission, (int) $intake->business_id, (int) $valuation->location_id, (int) $valuation->variation_id)) {
            throw new AuthorizationException('Trade-In action is not authorized.');
        }
    }

    private function recordProjection(TradeInIntake $intake, string $eventType): void
    {
        ($this->outbox ?: app(TradeInOutboxService::class))->record($intake, $eventType, $this->projection($intake->fresh()));
    }

    /** @param array<string, mixed> $value */
    private function fingerprint(array $value): string { return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); }

    /** @param array<string, mixed> $command @return array<string, mixed> */
    private function submissionFingerprintInput(array $command): array
    {
        return array_intersect_key($command, array_flip(['source_system','external_case_reference','submission_id','submission_version','category','brand','model','specifications','declared_condition','indicative_snapshot','evidence_references','photo_ai','customer','preferred_branch','submitted_at']));
    }
}

final class NativeMappingMismatch extends LogicException
{
    public function __construct(
        public int $valuationId,
        public string $caseReference,
        public string $posCaseReference,
        public string $commandId,
        public string $field,
        public int $expected,
        public ?int $received
    ) {
        parent::__construct('native_mapping_mismatch');
    }
}
