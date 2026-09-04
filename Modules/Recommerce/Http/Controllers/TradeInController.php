<?php

namespace Modules\Recommerce\Http\Controllers;

use App\Contact;
use App\User;
use App\Variation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use LogicException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Entities\TradeInQuickQuote;
use Modules\Recommerce\Entities\TradeInRuleSet;
use Modules\Recommerce\Entities\TradeInAuthorityRule;
use Modules\Recommerce\Entities\TradeInSellerRepresentation;
use Modules\Recommerce\Entities\TradeInValuation;
use Modules\Recommerce\Services\TradeInService;
use Modules\Recommerce\Services\TradeInDeviceIntakeService;
use Modules\Recommerce\Services\TradeInNegotiationService;
use Modules\Recommerce\Services\TradeInPhotoService;
use Modules\Recommerce\Services\TradeInQuickQuoteService;
use Modules\Recommerce\Services\TradeInAuthorityService;
use Modules\Recommerce\Services\TradeInQcReleaseService;
use Modules\Recommerce\Services\TradeInRefurbishmentService;
use Modules\Recommerce\Services\TradeInRuleResolver;
use Modules\Recommerce\Services\TradeInSellerService;
use Modules\Recommerce\Support\AuthorizationGate;
use Throwable;

class TradeInController extends Controller
{
    public function index(Request $request, AuthorizationGate $authorizationGate)
    {
        return $this->renderWorkspace('overview', $request, $authorizationGate);
    }

    public function acquisitions(Request $request, AuthorizationGate $authorizationGate)
    {
        return $this->renderWorkspace('acquisitions', $request, $authorizationGate);
    }

    public function approvals(Request $request, AuthorizationGate $authorizationGate)
    {
        return $this->renderWorkspace('approvals', $request, $authorizationGate);
    }

    public function reports(Request $request, AuthorizationGate $authorizationGate)
    {
        return $this->renderWorkspace('reports', $request, $authorizationGate);
    }

    public function create(Request $request, AuthorizationGate $authorizationGate)
    {
        return $this->renderWorkspace('create', $request, $authorizationGate);
    }

    public function show(int $valuationId, Request $request, AuthorizationGate $authorizationGate)
    {
        $valuation = TradeInValuation::query()->where('business_id', auth()->user()->business_id)->findOrFail($valuationId);

        return $this->renderWorkspace('show', $request, $authorizationGate, $valuation);
    }

    public function store(Request $request, TradeInService $service, TradeInSellerService $sellerService, TradeInDeviceIntakeService $deviceIntake, TradeInRuleResolver $ruleResolver, TradeInPhotoService $photoService, TradeInQuickQuoteService $quickQuoteService): RedirectResponse
    {
        try {
            $valuation = DB::transaction(function () use ($request, $service, $sellerService, $deviceIntake, $ruleResolver, $photoService, $quickQuoteService): TradeInValuation {
                $variation = $this->scopedVariation((int) $request->input('variation_id'));
                $user = auth()->user();
                $customer = $sellerService->resolveOrCreateCustomer(
                    $user,
                    $request->filled('customer_contact_id') ? (int) $request->input('customer_contact_id') : null,
                    $request->input('seller_name'),
                    $request->input('seller_phone')
                );
                $device = $deviceIntake->resolveOrCreate($user, [
                    'location_id' => (int) config('recommerce.cohort.location_id'), 'customer_contact_id' => $customer->id,
                    'product_id' => $variation->product_id, 'variation_id' => $variation->id, 'device_id' => $request->input('device_id'),
                    'identifier_type' => $request->input('identifier_type'), 'identifier_value' => $request->input('identifier_value'),
                    'category_code' => $request->input('category_code', 'LAPTOP'), 'brand' => $request->input('laptop_brand'),
                    'model' => $request->input('laptop_model'), 'command_uuid' => $request->input('command_uuid'),
                    'specifications' => $this->deviceSpecifications($request),
                ]);
                $supplier = $sellerService->resolveSupplierRepresentation($user, (int) $customer->id, $request->input('seller_phone'));
                $rule = $ruleResolver->resolve((int) $user->business_id, (int) $variation->id, (string) $request->input('category_code', 'LAPTOP'));
                $valuation = $service->createValuation(auth()->user(), [
                    'business_id' => (int) $user->business_id,
                    'location_id' => (int) config('recommerce.cohort.location_id'),
                    'device_id' => (int) $device->id,
                    'customer_contact_id' => (int) $customer->id,
                    'supplier_contact_id' => (int) $supplier->id,
                    'product_id' => (int) $variation->product_id,
                    'variation_id' => (int) $variation->id,
                    'rule_set_id' => (int) $rule->id,
                    'command_uuid' => (string) $request->input('command_uuid'),
                    'currency' => 'MYR',
                    'acquisition_type' => $request->input('acquisition_type', 'SELL_TO_SAVERBRO'),
                    'seller_phone_snapshot' => $request->input('seller_phone') ?: $customer->mobile,
                    'seller_identity_reference' => $request->input('seller_identity_reference'),
                    'seller_declaration_text' => config('recommerce.tradein_seller_declaration'),
                    'seller_declaration_version' => 'V2',
                    'seller_declaration_accepted' => $request->input('seller_declaration_accepted'),
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
                            ['key' => 'KEYBOARD', 'outcome' => $request->input('keyboard_outcome', 'NOT_TESTED'), 'notes' => $request->input('keyboard_notes')],
                            ['key' => 'TRACKPAD', 'outcome' => $request->input('trackpad_outcome', 'NOT_TESTED'), 'notes' => $request->input('trackpad_notes')],
                            ['key' => 'WIFI', 'outcome' => $request->input('wifi_outcome', 'NOT_TESTED'), 'notes' => $request->input('wifi_notes')],
                            ['key' => 'WEBCAM', 'outcome' => $request->input('webcam_outcome', 'NOT_TESTED'), 'notes' => $request->input('webcam_notes')],
                            ['key' => 'CHARGING', 'outcome' => $request->input('charging_outcome', 'NOT_TESTED'), 'notes' => $request->input('charging_notes')],
                            ['key' => 'POWER_ON', 'outcome' => $request->input('power_on_outcome', 'NOT_TESTED'), 'notes' => $request->input('power_on_notes')],
                        ],
                        'accessories_notes' => $request->input('accessories_notes'),
                    ],
                    'laptop_inspection' => $this->laptopInspection($request),
                    'market_evidence' => [
                        ['evidence_type' => 'MARKETPLACE', 'reference_amount' => $request->input('market_evidence_1_amount'), 'source_description' => $request->input('market_evidence_1_source'), 'reference_url' => $request->input('market_evidence_1_url'), 'observed_at' => now()->toDateTimeString()],
                        ['evidence_type' => 'COMPETITOR', 'reference_amount' => $request->input('market_evidence_2_amount'), 'source_description' => $request->input('market_evidence_2_source'), 'reference_url' => $request->input('market_evidence_2_url'), 'observed_at' => now()->toDateTimeString()],
                    ],
                ]);
                if ($request->hasFile('photos')) {
                    // Empty controls are omitted by PHP, so retain their source index before
                    // pairing each received image with its purpose.
                    $files = [];
                    $purposes = [];
                    foreach ((array) $request->file('photos') as $index => $file) {
                        if ($file) {
                            $files[] = $file;
                            $purposes[] = ((array) $request->input('photo_purpose', []))[$index] ?? null;
                        }
                    }
                    if ($files) {
                        $photoService->attach($user, $device, $valuation, $files, $purposes);
                    }
                }

                if ($request->filled('quick_quote_id') && Schema::hasTable('recommerce_trade_in_quick_quotes')) {
                    $quote = TradeInQuickQuote::query()
                        ->where('business_id', $user->business_id)
                        ->where('location_id', config('recommerce.cohort.location_id'))
                        ->findOrFail((int) $request->input('quick_quote_id'));
                    $quickQuoteService->continueToValuation($quote, $valuation);
                }

                return $valuation;
            });

            return redirect()->route('recommerce.tradeins.show', $valuation->id)->with('status', [
                'success' => true,
                'msg' => 'Inspection and offer recorded. Review the Deal Desk before closing the acquisition.',
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
                'category_code' => $request->input('category_code'),
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

    public function configureAuthority(Request $request, TradeInAuthorityService $service): RedirectResponse
    {
        $roleName = $request->filled('role_name') ? (string) $request->input('role_name') : null;
        $validRoles = Role::query()->where('business_id', auth()->user()->business_id)->pluck('name')
            ->map(fn ($name) => str_replace('#'.auth()->user()->business_id, '', (string) $name))->all();
        if ($roleName !== null && ! in_array($roleName, $validRoles, true)) {
            return back()->with('status', ['success' => false, 'msg' => 'Choose a role configured for this business.']);
        }
        try {
            $rule = $service->configure(auth()->user(), (int) config('recommerce.cohort.location_id'), $roleName, $request->input('maximum_without_approval'));
            return redirect()->route('recommerce.tradeins.index')->with('status', ['success' => true, 'msg' => 'Authority rule #'.$rule->id.' is active for this branch.']);
        } catch (AuthorizationException $exception) { abort(404); }
        catch (LogicException $exception) { return back()->withInput()->with('status', ['success' => false, 'msg' => $exception->getMessage()]); }
    }

    public function storeQuickQuote(Request $request, TradeInQuickQuoteService $service): RedirectResponse
    {
        try {
            $quote = $service->create(auth()->user(), [
                'business_id' => (int) auth()->user()->business_id,
                'location_id' => (int) config('recommerce.cohort.location_id'),
                'command_uuid' => $request->input('command_uuid'),
                'customer_contact_id' => $request->input('customer_contact_id'),
                'seller_name' => $request->input('seller_name'),
                'seller_phone' => $request->input('seller_phone'),
                'acquisition_type' => $request->input('acquisition_type'),
                'variation_id' => $request->input('variation_id'),
                'brand' => $request->input('brand'),
                'model' => $request->input('model'),
                'cpu' => $request->input('cpu'),
                'ram' => $request->input('ram'),
                'storage' => $request->input('storage'),
                'gpu' => $request->input('gpu'),
                'cosmetic_grade' => $request->input('cosmetic_grade'),
                'battery_health_percent' => $request->input('battery_health_percent'),
                'major_defects' => $request->input('major_defects'),
                'charger_included' => $request->boolean('charger_included'),
                'customer_expected_amount' => $request->input('customer_expected_amount'),
                'customer_expected_unknown' => $request->boolean('customer_expected_unknown'),
                'expected_resale_amount' => $request->input('expected_resale_amount'),
                'supersedes_quote_id' => $request->input('supersedes_quote_id'),
            ]);

            return redirect()->route('recommerce.tradeins.create', ['quote' => $quote->id])
                ->with('status', ['success' => true, 'msg' => 'Quick Quote saved. The estimate remains subject to full inspection.']);
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (LogicException $exception) {
            return back()->withInput()->with('status', ['success' => false, 'msg' => $exception->getMessage()]);
        }
    }

    public function declineQuickQuote(int $quoteId, Request $request, TradeInQuickQuoteService $service): RedirectResponse
    {
        $quote = TradeInQuickQuote::query()->where('business_id', auth()->user()->business_id)->findOrFail($quoteId);
        try {
            $service->decline(auth()->user(), $quote, (string) $request->input('reason_code'), (string) $request->input('reason'));

            return redirect()->route('recommerce.tradeins.acquisitions', ['filter' => 'lost'])
                ->with('status', ['success' => true, 'msg' => 'Quick Quote closed as Customer Declined.']);
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (LogicException $exception) {
            return back()->with('status', ['success' => false, 'msg' => $exception->getMessage()]);
        }
    }

    public function approve(int $valuationId, Request $request, TradeInService $service): RedirectResponse
    {
        return $this->action($valuationId, fn (TradeInValuation $valuation) => $service->approve(auth()->user(), $valuation, (string) $request->input('reason')));
    }

    public function returnForRevision(int $valuationId, Request $request, TradeInService $service): RedirectResponse
    {
        return $this->action($valuationId, fn (TradeInValuation $valuation) => $service->returnForRevision(auth()->user(), $valuation, (string) $request->input('reason')));
    }

    public function accept(int $valuationId, Request $request, TradeInService $service): RedirectResponse
    {
        return $this->action($valuationId, fn (TradeInValuation $valuation) => $service->accept(auth()->user(), $valuation, (string) $request->input('command_uuid')));
    }

    public function reject(int $valuationId, Request $request, TradeInService $service): RedirectResponse
    {
        return $this->action($valuationId, fn (TradeInValuation $valuation) => $service->reject(auth()->user(), $valuation, (string) $request->input('reason'), $request->only(['reason_code', 'competitor_name', 'competitor_offer_amount'])));
    }

    public function negotiate(int $valuationId, Request $request, TradeInNegotiationService $service): RedirectResponse
    {
        return $this->action($valuationId, fn (TradeInValuation $valuation) => $service->record(auth()->user(), $valuation, (string) $request->input('event_type'), $request->input('amount'), $request->input('note')));
    }

    public function reverse(int $valuationId, Request $request, TradeInService $service): RedirectResponse
    {
        $valuation = TradeInValuation::query()->where('business_id', auth()->user()->business_id)->with('acquisition')->findOrFail($valuationId);
        if (! $valuation->acquisition) {
            abort(404);
        }
        try {
            $service->recordReversal(auth()->user(), $valuation->acquisition, (int) $request->input('purchase_return_transaction_id'), (string) $request->input('command_uuid'), (string) $request->input('reason'));
            return redirect()->route('recommerce.tradeins.index')->with('status', ['success' => true, 'msg' => 'Trade-in reversal recorded after the matching native purchase return.']);
        } catch (AuthorizationException $exception) { abort(404); }
        catch (LogicException $exception) { return back()->with('status', ['success' => false, 'msg' => $exception->getMessage()]); }
    }

    public function refurbishment(int $valuationId, Request $request, TradeInRefurbishmentService $service): RedirectResponse
    {
        $valuation = TradeInValuation::query()->where('business_id', auth()->user()->business_id)->findOrFail($valuationId);
        try {
            $job = $service->create(auth()->user(), $valuation, $request->input('notes'));
            return redirect()->route('recommerce.repair.show', $job->job_code)->with('status', ['success' => true, 'msg' => 'Internal refurbishment job created from this acquisition.']);
        } catch (AuthorizationException $exception) { abort(404); }
        catch (LogicException $exception) { return back()->with('status', ['success' => false, 'msg' => $exception->getMessage()]); }
    }

    public function releaseForSale(int $valuationId, TradeInQcReleaseService $service): RedirectResponse
    {
        $valuation = TradeInValuation::query()->where('business_id', auth()->user()->business_id)->findOrFail($valuationId);
        try {
            $device = $service->release(auth()->user(), $valuation);
            return redirect()->route('recommerce.tradeins.index')->with('status', ['success' => true, 'msg' => 'Device '.$device->device_code.' is QC-approved and available for sale.']);
        } catch (AuthorizationException $exception) { abort(404); }
        catch (LogicException $exception) { return back()->with('status', ['success' => false, 'msg' => $exception->getMessage()]); }
    }

    protected function renderWorkspace(string $page, Request $request, AuthorizationGate $authorizationGate, ?TradeInValuation $selectedValuation = null)
    {
        $user = auth()->user();
        $businessId = (int) $user->business_id;
        $locationId = (int) config('recommerce.cohort.location_id');
        $variationIds = array_values(array_filter(array_map('intval', (array) config('recommerce.cohort.variation_ids', []))));
        if ($locationId < 1 || ! User::can_access_this_location($locationId, $businessId)
            || ! $authorizationGate->allowsRead($user, TradeInService::PERMISSION_VIEW, $businessId, $locationId)) {
            abort(404);
        }

        $variations = Variation::query()->with('product')
            ->whereIn('id', $variationIds)
            ->whereHas('product', fn ($query) => $query->where('business_id', $businessId))
            ->orderBy('id')->get()
            ->filter(fn (Variation $variation) => $authorizationGate->allowsRead(
                $user, TradeInService::PERMISSION_VIEW, $businessId, $locationId, (int) $variation->id
            ))->values();
        $firstVariation = $variations->first();

        $valuations = TradeInValuation::query()
            ->with(['device', 'customer', 'createdBy', 'marketEvidence', 'ruleSet', 'laptopInspection', 'negotiationEvents', 'acquisition'])
            ->where('business_id', $businessId)->where('location_id', $locationId)
            ->latest('id')->limit(500)->get();
        $quickQuotes = Schema::hasTable('recommerce_trade_in_quick_quotes')
            ? TradeInQuickQuote::query()->with(['customer', 'variation.product', 'createdBy', 'valuation'])
                ->where('business_id', $businessId)->where('location_id', $locationId)->latest('id')->limit(500)->get()
            : collect();
        $qcJobsByValuation = Schema::hasTable('recommerce_repair_jobs')
            ? RepairJob::query()->where('business_id', $businessId)->where('source_type', 'TRADE_IN_VALUATION')
                ->whereIn('source_id', $valuations->pluck('id'))->latest('id')->get()->keyBy('source_id')
            : collect();

        $customerLostCodes = ['OFFER_TOO_LOW', 'CUSTOMER_EXPECTED_MORE', 'COMPETITOR_OFFERED_MORE', 'CUSTOMER_DECIDED_NOT_TO_SELL', 'CUSTOMER_OTHER', 'NO_SUITABLE_UPGRADE', 'PRICE_CHECK_ONLY'];
        $isToday = static fn ($date) => $date && $date->isToday();
        $todayQuotes = $quickQuotes->filter(fn (TradeInQuickQuote $quote) => $isToday($quote->created_at));
        $linkedTodayValuationIds = $todayQuotes->pluck('continued_to_valuation_id')->filter()->map(fn ($id) => (int) $id);
        $todayValuations = $valuations->filter(fn (TradeInValuation $valuation) => $isToday($valuation->created_at));
        $quoteCount = $todayQuotes->count() + $todayValuations->reject(fn (TradeInValuation $valuation) => $linkedTodayValuationIds->contains((int) $valuation->id))->count();
        $todayAccepted = $valuations->filter(fn (TradeInValuation $valuation) => $valuation->status === TradeInValuation::STATUS_ACCEPTED && $isToday($valuation->accepted_at));
        $todayCustomerLostValuations = $valuations->filter(fn (TradeInValuation $valuation) => $valuation->status === TradeInValuation::STATUS_REJECTED
            && in_array($valuation->rejection_reason_code, $customerLostCodes, true) && $isToday($valuation->rejected_at));
        $todaySaverBroRejected = $valuations->filter(fn (TradeInValuation $valuation) => $valuation->status === TradeInValuation::STATUS_REJECTED
            && ! in_array($valuation->rejection_reason_code, $customerLostCodes, true) && $isToday($valuation->rejected_at));
        $todayQuickLost = $quickQuotes->filter(fn (TradeInQuickQuote $quote) => $quote->status === TradeInQuickQuote::STATUS_CUSTOMER_DECLINED && $isToday($quote->updated_at));
        $customerDecisions = $todayAccepted->count() + $todayCustomerLostValuations->count() + $todayQuickLost->count();
        $offersToday = $todayValuations->filter(fn (TradeInValuation $valuation) => $valuation->negotiationEvents->contains('event_type', 'STAFF_OFFER'));

        $pendingApprovals = $valuations->where('status', TradeInValuation::STATUS_PENDING_APPROVAL);
        $considering = $quickQuotes->filter(fn (TradeInQuickQuote $quote) => $quote->status === TradeInQuickQuote::STATUS_CONSIDERING && ! $quote->isExpired());
        $pendingQc = $valuations->filter(fn (TradeInValuation $valuation) => $valuation->status === TradeInValuation::STATUS_ACCEPTED
            && optional($valuation->device)->ownership_kind === 'BUSINESS'
            && optional($valuation->device)->lifecycle_state === 'PENDING_QC'
            && (int) optional($valuation->device)->current_location_id === (int) $valuation->location_id);
        $expiring = $considering->filter(fn (TradeInQuickQuote $quote) => $quote->expires_at && $quote->expires_at->lte(now()->addDay()->endOfDay()));
        $readyToday = $todayAccepted->filter(fn (TradeInValuation $valuation) => optional($valuation->device)->ownership_kind === 'BUSINESS'
            && optional($valuation->device)->lifecycle_state === 'AVAILABLE');

        $selectedQuote = null;
        if ($page === 'create' && $request->filled('quote') && Schema::hasTable('recommerce_trade_in_quick_quotes')) {
            $selectedQuote = $quickQuotes->firstWhere('id', (int) $request->input('quote'));
        }
        if ($selectedValuation) {
            $selectedValuation = $valuations->firstWhere('id', $selectedValuation->id) ?: $selectedValuation;
        }

        $data = [
            'workspacePage' => $page,
            'businessId' => $businessId,
            'locationId' => $locationId,
            'variations' => $variations,
            'customers' => Contact::query()->where('business_id', $businessId)->whereIn('type', ['customer', 'both'])
                ->whereNull('deleted_at')->orderBy('name')->limit(200)->get(),
            'devices' => Device::query()->where('business_id', $businessId)->where('ownership_kind', 'CUSTOMER')
                ->with(['identifiers', 'product'])->orderBy('device_code')->limit(200)->get(),
            'valuations' => $valuations,
            'quickQuotes' => $quickQuotes,
            'selectedValuation' => $selectedValuation,
            'selectedQuote' => $selectedQuote,
            'qcJobsByValuation' => $qcJobsByValuation,
            'customerLostCodes' => $customerLostCodes,
            'metrics' => [
                'quotes' => $quoteCount,
                'offers' => $offersToday->count(),
                'accepted' => $todayAccepted->count(),
                'lost' => $todayCustomerLostValuations->count() + $todayQuickLost->count(),
                'saverbro_rejected' => $todaySaverBroRejected->count(),
                'conversion' => $customerDecisions ? round(($todayAccepted->count() / $customerDecisions) * 100, 1) : null,
                'conversion_numerator' => $todayAccepted->count(),
                'conversion_denominator' => $customerDecisions,
                'spend' => $todayAccepted->sum('final_acquisition_amount'),
            ],
            'needsAttention' => [
                'approvals' => $pendingApprovals->count(),
                'considering' => $considering->count(),
                'pending_qc' => $pendingQc->count(),
                'expiring' => $expiring->count(),
            ],
            'funnel' => [
                'quotes' => $quoteCount,
                'inspections' => $todayValuations->count(),
                'offers' => $offersToday->count(),
                'acquired' => $todayAccepted->count(),
                'ready' => $readyToday->count(),
            ],
            'pendingApprovals' => $pendingApprovals,
            'activeValuations' => $valuations->filter(fn (TradeInValuation $valuation) => in_array($valuation->status, [
                TradeInValuation::STATUS_READY_TO_ACCEPT, TradeInValuation::STATUS_PENDING_APPROVAL,
                TradeInValuation::STATUS_APPROVED,
            ], true) || $pendingQc->contains('id', $valuation->id))->take(12),
            'reporting' => [
                'accepted_count' => $valuations->where('status', TradeInValuation::STATUS_ACCEPTED)->count(),
                'accepted_spend' => $valuations->where('status', TradeInValuation::STATUS_ACCEPTED)->sum('final_acquisition_amount'),
                'average_price' => $valuations->where('status', TradeInValuation::STATUS_ACCEPTED)->avg('final_acquisition_amount'),
                'customer_lost' => $quickQuotes->where('status', TradeInQuickQuote::STATUS_CUSTOMER_DECLINED)->count()
                    + $valuations->where('status', TradeInValuation::STATUS_REJECTED)->whereIn('rejection_reason_code', $customerLostCodes)->count(),
                'saverbro_rejected' => $valuations->where('status', TradeInValuation::STATUS_REJECTED)->reject(fn (TradeInValuation $valuation) => in_array($valuation->rejection_reason_code, $customerLostCodes, true))->count(),
                'lost_reasons' => $quickQuotes->where('status', TradeInQuickQuote::STATUS_CUSTOMER_DECLINED)->pluck('lost_reason_code')
                    ->merge($valuations->where('status', TradeInValuation::STATUS_REJECTED)->pluck('rejection_reason_code'))->filter()->countBy()->sortDesc(),
                'staff' => $valuations->groupBy(fn (TradeInValuation $valuation) => optional($valuation->createdBy)->first_name ?: 'Unassigned')
                    ->map(fn ($items) => ['offers' => $items->count(), 'accepted' => $items->where('status', TradeInValuation::STATUS_ACCEPTED)->count(), 'spend' => $items->where('status', TradeInValuation::STATUS_ACCEPTED)->sum('final_acquisition_amount')]),
                'qc_aging' => $pendingQc->map(fn (TradeInValuation $valuation) => ['valuation' => $valuation, 'days' => optional($valuation->accepted_at)->diffInDays(now()) ?? 0])->sortByDesc('days'),
            ],
            'canManage' => (bool) ($firstVariation && $authorizationGate->allowsWrite($user, TradeInService::PERMISSION_MANAGE, $businessId, $locationId, $firstVariation->id)),
            'canApprove' => (bool) ($firstVariation && $authorizationGate->allowsWrite($user, TradeInService::PERMISSION_APPROVE, $businessId, $locationId, $firstVariation->id)),
            'canAccept' => (bool) ($firstVariation && $authorizationGate->allowsWrite($user, TradeInService::PERMISSION_ACCEPT, $businessId, $locationId, $firstVariation->id)),
            'canOverrideEconomic' => (bool) ($firstVariation && $authorizationGate->allowsWrite($user, TradeInService::PERMISSION_OVERRIDE_ECONOMIC, $businessId, $locationId, $firstVariation->id)),
            'canReverse' => (bool) ($firstVariation && $authorizationGate->allowsWrite($user, TradeInService::PERMISSION_REVERSE, $businessId, $locationId, $firstVariation->id)),
            'sellerDeclarationText' => (string) config('recommerce.tradein_seller_declaration'),
        ];

        return response()->view('recommerce::tradein.index', $data)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    protected function action(int $valuationId, callable $action): RedirectResponse
    {
        $valuation = TradeInValuation::query()->where('business_id', auth()->user()->business_id)->findOrFail($valuationId);
        try {
            $result = $action($valuation);

            return redirect()->route('recommerce.tradeins.show', $valuationId)->with('status', ['success' => true, 'msg' => 'Trade-in action recorded.']);
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

    protected function deviceSpecifications(Request $request): array
    {
        return array_filter([
            'brand' => $request->input('laptop_brand'), 'model' => $request->input('laptop_model'), 'cpu' => $request->input('laptop_cpu'),
            'ram' => $request->input('laptop_ram'), 'storage' => $request->input('laptop_storage'), 'gpu' => $request->input('laptop_gpu'),
            'display_size' => $request->input('laptop_display_size'), 'operating_system' => $request->input('laptop_operating_system'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    protected function laptopInspection(Request $request): array
    {
        $functionalChecks = (array) $request->input('functional_checks', []);
        foreach (['DISPLAY' => 'display', 'KEYBOARD' => 'keyboard', 'TRACKPAD' => 'trackpad', 'WIFI' => 'wifi', 'BLUETOOTH' => 'bluetooth', 'WEBCAM' => 'webcam', 'MICROPHONE' => 'microphone', 'SPEAKERS' => 'speakers', 'USB_PORTS' => 'usb_ports', 'HDMI_OUTPUT' => 'hdmi_output', 'CHARGING' => 'charging', 'POWER_ON' => 'power_on', 'STORAGE_HEALTH' => 'storage_health'] as $key => $prefix) {
            $functionalChecks[$key] = $functionalChecks[$key] ?? $request->input($prefix.'_outcome', 'NOT_TESTED');
        }
        return [
            'brand' => $request->input('laptop_brand'), 'model' => $request->input('laptop_model'), 'cpu' => $request->input('laptop_cpu'),
            'ram' => $request->input('laptop_ram'), 'storage' => $request->input('laptop_storage'), 'gpu' => $request->input('laptop_gpu'),
            'display_size' => $request->input('laptop_display_size'), 'operating_system' => $request->input('laptop_operating_system'),
            'screen_condition' => $request->input('screen_condition'), 'body_condition' => $request->input('body_condition'),
            'palm_rest_condition' => $request->input('palm_rest_condition'), 'keyboard_condition' => $request->input('keyboard_condition'),
            'hinges_condition' => $request->input('hinges_condition'), 'battery_cycle_count' => $request->input('battery_cycle_count'),
            'charger_included' => $request->boolean('charger_included'), 'charger_type' => $request->input('charger_type'),
            'box_included' => $request->boolean('box_included'), 'accessories_other' => $request->input('accessories_other'),
            'risk_flags' => $request->input('risk_flags', []),
            'functional_checks' => $functionalChecks,
        ];
    }
}
