<?php

namespace Modules\Recommerce\Services;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Recommerce\Entities\TradeInNegotiationEvent;
use Modules\Recommerce\Entities\TradeInValuation;
use Modules\Recommerce\Support\AuthorizationGate;

class TradeInNegotiationService
{
    public function __construct(
        protected AuthorizationGate $authorizationGate,
        protected TradeInAuthorityService $authorityService
    ) {
    }

    public function record(User $user, TradeInValuation $valuation, string $eventType, $amount, ?string $note = null): TradeInNegotiationEvent
    {
        $eventType = strtoupper(trim($eventType));
        if (! in_array($eventType, [TradeInNegotiationEvent::STAFF_OFFER, TradeInNegotiationEvent::CUSTOMER_COUNTER], true)) {
            throw new LogicException('Only a staff offer or customer counter can be added to a live negotiation.');
        }
        if (! is_numeric($amount) || (float) $amount < 0) {
            throw new LogicException('Negotiation amount must be a non-negative number.');
        }
        if ((int) $user->business_id !== (int) $valuation->business_id
            || ! $this->authorizationGate->allowsWrite($user, TradeInService::PERMISSION_MANAGE, $valuation->business_id, $valuation->location_id, $valuation->variation_id)) {
            throw new AuthorizationException('Trade-in negotiation scope denied.');
        }

        return DB::transaction(function () use ($user, $valuation, $eventType, $amount, $note): TradeInNegotiationEvent {
            $locked = TradeInValuation::query()->whereKey($valuation->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [TradeInValuation::STATUS_READY_TO_ACCEPT, TradeInValuation::STATUS_PENDING_APPROVAL, TradeInValuation::STATUS_APPROVED], true)) {
                throw new LogicException('This valuation is no longer open for negotiation.');
            }

            $event = TradeInNegotiationEvent::create([
                'event_uuid' => (string) Str::uuid(), 'valuation_id' => $locked->id, 'business_id' => $locked->business_id,
                'event_type' => $eventType, 'actor_type' => $eventType === TradeInNegotiationEvent::CUSTOMER_COUNTER ? 'CUSTOMER' : 'STAFF',
                'amount' => round((float) $amount, 4), 'currency' => $locked->currency,
                'note' => $note === null ? null : mb_substr(trim($note), 0, 1000), 'recorded_by' => $user->id, 'occurred_at' => now(),
            ]);

            if ($eventType === TradeInNegotiationEvent::STAFF_OFFER) {
                $offer = round((float) $amount, 4);
                $authority = $this->authorityService->authorityFor($user, (int) $locked->location_id, $offer);
                $requiresApproval = $offer > (float) $locked->negotiation_ceiling_amount || $authority['requires_approval'];
                $locked->staff_proposed_amount = $offer;
                $locked->final_acquisition_amount = $offer;
                $locked->approval_required = $requiresApproval;
                $locked->authority_limit_amount = $authority['limit'];
                $locked->authority_approval_required = $authority['requires_approval'];
                $locked->status = $requiresApproval
                    ? TradeInValuation::STATUS_PENDING_APPROVAL
                    : TradeInValuation::STATUS_READY_TO_ACCEPT;
                $locked->approved_by = null;
                $locked->approved_at = null;
                $locked->approval_reason = null;
                $locked->lock_version = (int) $locked->lock_version + 1;
                $locked->save();
            }

            return $event;
        });
    }
}
