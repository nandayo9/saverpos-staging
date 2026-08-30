<?php

namespace Modules\Recommerce\Http\Controllers;

use App\Contact;
use App\User;
use App\Variation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use LogicException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\TradeInRuleSet;
use Modules\Recommerce\Entities\TradeInValuation;
use Modules\Recommerce\Services\TradeInService;
use Modules\Recommerce\Support\AuthorizationGate;
use Throwable;

class TradeInController extends Controller
{
    public function index(AuthorizationGate $authorizationGate)
    {
        $user = auth()->user();
        $businessId = (int) $user->business_id;
        $locationId = (int) config('recommerce.cohort.location_id');
        $variationIds = array_values(array_filter(array_map('intval', (array) config('recommerce.cohort.variation_ids', []))));

        if ($locationId < 1 || ! User::can_access_this_location($locationId, $businessId)
            || ! $authorizationGate->allowsRead($user, TradeInService::PERMISSION_VIEW, $businessId, $locationId)) {
            abort(404);
        }

        $variations = Variation::query()
            ->with('product')
            ->whereIn('id', $variationIds)
            ->whereHas('product', fn ($query) => $query->where('business_id', $businessId))
            ->orderBy('id')
            ->get()
            ->filter(fn (Variation $variation) => $authorizationGate->allowsRead(
                $user, TradeInService::PERMISSION_VIEW, $businessId, $locationId, (int) $variation->id
            ))
            ->values();
        $firstVariation = $variations->first();
        $canManage = $firstVariation && $authorizationGate->allowsWrite($user, TradeInService::PERMISSION_MANAGE, $businessId, $locationId, $firstVariation->id);
        $canApprove = $firstVariation && $authorizationGate->allowsWrite($user, TradeInService::PERMISSION_APPROVE, $businessId, $locationId, $firstVariation->id);
        $canAccept = $firstVariation && $authorizationGate->allowsWrite($user, TradeInService::PERMISSION_ACCEPT, $businessId, $locationId, $firstVariation->id);

        return response()->view('recommerce::tradein.index', [
            'businessId' => $businessId,
            'locationId' => $locationId,
            'variations' => $variations,
            'ruleSets' => TradeInRuleSet::query()->where('business_id', $businessId)->where('status', 'ACTIVE')->orderBy('rule_code')->orderByDesc('version_number')->get(),
            'customers' => Contact::query()->where('business_id', $businessId)->whereIn('type', ['customer', 'both'])->whereNull('deleted_at')->orderBy('name')->limit(200)->get(),
            'suppliers' => Contact::query()->where('business_id', $businessId)->whereIn('type', ['supplier', 'both'])->whereNull('deleted_at')->orderBy('name')->limit(200)->get(),
            'devices' => Device::query()->where('business_id', $businessId)->where('ownership_kind', 'CUSTOMER')->orderBy('device_code')->limit(200)->get(),
            'valuations' => TradeInValuation::query()->with(['device', 'marketEvidence', 'ruleSet'])->where('business_id', $businessId)->where('location_id', $locationId)->latest('id')->limit(50)->get(),
            'canManage' => (bool) $canManage,
            'canApprove' => (bool) $canApprove,
            'canAccept' => (bool) $canAccept,
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request, TradeInService $service): RedirectResponse
    {
        try {
            $variation = $this->scopedVariation((int) $request->input('variation_id'));
            $valuation = $service->createValuation(auth()->user(), [
                'business_id' => (int) auth()->user()->business_id,
                'location_id' => (int) config('recommerce.cohort.location_id'),
                'device_id' => (int) $request->input('device_id'),
                'customer_contact_id' => (int) $request->input('customer_contact_id'),
                'supplier_contact_id' => (int) $request->input('supplier_contact_id'),
                'product_id' => (int) $variation->product_id,
                'variation_id' => (int) $variation->id,
                'rule_set_id' => (int) $request->input('rule_set_id'),
                'command_uuid' => (string) $request->input('command_uuid'),
                'currency' => 'MYR',
                'market_reference_amount' => $request->input('market_reference_amount'),
                'expected_resale_amount' => $request->input('expected_resale_amount'),
                'expected_refurbishment_amount' => $request->input('expected_refurbishment_amount', 0),
                'staff_proposed_amount' => $request->input('staff_proposed_amount'),
                'customer_requested_amount' => $request->input('customer_requested_amount'),
                'inspection' => [
                    'battery_health_percent' => $request->input('battery_health_percent'),
                    'battery_replacement_needed' => $request->input('battery_replacement_needed', 'NO'),
                    'battery_replacement_estimate_amount' => $request->input('battery_replacement_estimate_amount', 0),
                    'cosmetic_grade' => $request->input('cosmetic_grade'),
                    'cosmetic_notes' => $request->input('cosmetic_notes'),
                    'functional_observations' => [
                        ['key' => 'DISPLAY', 'outcome' => $request->input('display_outcome', 'NOT_TESTED'), 'notes' => $request->input('display_notes')],
                        ['key' => 'TOUCH', 'outcome' => $request->input('touch_outcome', 'NOT_TESTED'), 'notes' => $request->input('touch_notes')],
                        ['key' => 'CHARGING', 'outcome' => $request->input('charging_outcome', 'NOT_TESTED'), 'notes' => $request->input('charging_notes')],
                    ],
                    'accessories_notes' => $request->input('accessories_notes'),
                ],
                'market_evidence' => [
                    ['evidence_type' => 'MARKETPLACE', 'reference_amount' => $request->input('market_evidence_1_amount'), 'source_description' => $request->input('market_evidence_1_source'), 'reference_url' => $request->input('market_evidence_1_url'), 'observed_at' => now()->toDateTimeString()],
                    ['evidence_type' => 'COMPETITOR', 'reference_amount' => $request->input('market_evidence_2_amount'), 'source_description' => $request->input('market_evidence_2_source'), 'reference_url' => $request->input('market_evidence_2_url'), 'observed_at' => now()->toDateTimeString()],
                ],
            ]);

            return redirect()->route('recommerce.tradeins.index')->with('status', [
                'success' => true,
                'msg' => 'Trade-in valuation '.$valuation->valuation_uuid.' recorded as '.$valuation->status.'.',
            ]);
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (LogicException $exception) {
            return back()->withInput()->with('status', ['success' => false, 'msg' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);
            return back()->withInput()->with('status', ['success' => false, 'msg' => 'Trade-in valuation could not be recorded.']);
        }
    }

    public function createRule(Request $request, TradeInService $service): RedirectResponse
    {
        try {
            $rule = $service->createRuleSet(auth()->user(), [
                'business_id' => (int) auth()->user()->business_id,
                'location_id' => (int) config('recommerce.cohort.location_id'),
                'variation_id' => (int) $request->input('variation_id'),
                'rule_code' => (string) $request->input('rule_code'),
                'parameters' => $request->only([
                    'target_margin_percent', 'warranty_reserve_percent', 'hidden_defect_reserve_percent', 'markdown_reserve_percent',
                    'opening_offer_ratio', 'target_acquisition_ratio', 'negotiation_ceiling_ratio',
                ]),
            ]);

            return redirect()->route('recommerce.tradeins.index')->with('status', ['success' => true, 'msg' => 'Pricing rule '.$rule->rule_code.' v'.$rule->version_number.' is active.']);
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (LogicException $exception) {
            return back()->withInput()->with('status', ['success' => false, 'msg' => $exception->getMessage()]);
        }
    }

    public function approve(int $valuationId, Request $request, TradeInService $service): RedirectResponse
    {
        return $this->action($valuationId, fn (TradeInValuation $valuation) => $service->approve(auth()->user(), $valuation, (string) $request->input('reason')));
    }

    public function accept(int $valuationId, Request $request, TradeInService $service): RedirectResponse
    {
        return $this->action($valuationId, fn (TradeInValuation $valuation) => $service->accept(auth()->user(), $valuation, (string) $request->input('command_uuid')));
    }

    public function reject(int $valuationId, Request $request, TradeInService $service): RedirectResponse
    {
        return $this->action($valuationId, fn (TradeInValuation $valuation) => $service->reject(auth()->user(), $valuation, (string) $request->input('reason')));
    }

    protected function action(int $valuationId, callable $action): RedirectResponse
    {
        $valuation = TradeInValuation::query()->where('business_id', auth()->user()->business_id)->findOrFail($valuationId);
        try {
            $result = $action($valuation);

            return redirect()->route('recommerce.tradeins.index')->with('status', ['success' => true, 'msg' => 'Trade-in action recorded for reference #'.$result->id.'.']);
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (LogicException $exception) {
            return back()->with('status', ['success' => false, 'msg' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);
            return back()->with('status', ['success' => false, 'msg' => 'Trade-in action could not be completed.']);
        }
    }

    protected function scopedVariation(int $variationId): Variation
    {
        $variation = Variation::query()->whereKey($variationId)->with('product')->firstOrFail();
        if (! $variation->product || (int) $variation->product->business_id !== (int) auth()->user()->business_id) {
            abort(404);
        }

        return $variation;
    }
}
