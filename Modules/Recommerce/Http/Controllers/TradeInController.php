<?php

namespace Modules\Recommerce\Http\Controllers;

use App\Contact;
use App\User;
use App\Variation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use LogicException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\TradeInRuleSet;
use Modules\Recommerce\Entities\TradeInAuthorityRule;
use Modules\Recommerce\Entities\TradeInSellerRepresentation;
use Modules\Recommerce\Entities\TradeInValuation;
use Modules\Recommerce\Services\TradeInService;
use Modules\Recommerce\Services\TradeInDeviceIntakeService;
use Modules\Recommerce\Services\TradeInNegotiationService;
use Modules\Recommerce\Services\TradeInPhotoService;
use Modules\Recommerce\Services\TradeInAuthorityService;
use Modules\Recommerce\Services\TradeInQcReleaseService;
use Modules\Recommerce\Services\TradeInRefurbishmentService;
use Modules\Recommerce\Services\TradeInRuleResolver;
use Modules\Recommerce\Services\TradeInSellerService;
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

        $valuations = TradeInValuation::query()->with(['device', 'customer', 'createdBy', 'marketEvidence', 'ruleSet', 'laptopInspection', 'negotiationEvents'])->where('business_id', $businessId)->where('location_id', $locationId)->latest('id')->limit(50)->get();
        $today = $valuations->filter(fn (TradeInValuation $valuation) => $valuation->created_at && $valuation->created_at->isToday());
        $decidedToday = $today->whereIn('status', [TradeInValuation::STATUS_ACCEPTED, TradeInValuation::STATUS_REJECTED]);
        return response()->view('recommerce::tradein.index', [
            'businessId' => $businessId,
            'locationId' => $locationId,
            'variations' => $variations,
            'ruleSets' => TradeInRuleSet::query()->where('business_id', $businessId)->where('status', 'ACTIVE')->orderBy('rule_code')->orderByDesc('version_number')->get(),
            'authorityRules' => Schema::hasTable('recommerce_trade_in_authority_rules') ? TradeInAuthorityRule::query()->where('business_id', $businessId)->where('location_id', $locationId)->where('active', true)->orderBy('role_name')->get() : collect(),
            'authorityRoles' => Role::query()->where('business_id', $businessId)->orderBy('name')->pluck('name')->map(fn ($name) => str_replace('#'.$businessId, '', (string) $name))->values(),
            'customers' => Contact::query()->where('business_id', $businessId)->whereIn('type', ['customer', 'both'])->whereNull('deleted_at')->orderBy('name')->limit(200)->get(),
            'customerNativePayeeIds' => TradeInSellerRepresentation::query()
                ->join('contacts as supplier_payees', 'supplier_payees.id', '=', 'recommerce_trade_in_seller_representations.supplier_contact_id')
                ->where('recommerce_trade_in_seller_representations.business_id', $businessId)
                ->whereIn('supplier_payees.type', ['supplier', 'both'])
                ->whereNull('supplier_payees.deleted_at')
                ->pluck('recommerce_trade_in_seller_representations.customer_contact_id')
                ->map(fn ($id) => (int) $id)->all(),
            'devices' => Device::query()->where('business_id', $businessId)->where('ownership_kind', 'CUSTOMER')->orderBy('device_code')->limit(200)->get(),
            'valuations' => $valuations,
            'metrics' => [
                'today' => $today->count(), 'accepted' => $today->where('status', TradeInValuation::STATUS_ACCEPTED)->count(),
                'pending' => $today->where('status', TradeInValuation::STATUS_PENDING_APPROVAL)->count(),
                'rejected' => $today->where('status', TradeInValuation::STATUS_REJECTED)->count(),
                'spend' => $today->where('status', TradeInValuation::STATUS_ACCEPTED)->sum('final_acquisition_amount'),
                'conversion' => $decidedToday->isEmpty() ? null : round(($today->where('status', TradeInValuation::STATUS_ACCEPTED)->count() / $decidedToday->count()) * 100, 1),
            ],
            'canManage' => (bool) $canManage,
            'canApprove' => (bool) $canApprove,
            'canAccept' => (bool) $canAccept,
            'canReverse' => $firstVariation && $authorizationGate->allowsWrite($user, TradeInService::PERMISSION_REVERSE, $businessId, $locationId, $firstVariation->id),
            'sellerDeclarationText' => (string) config('recommerce.tradein_seller_declaration', 'Seller declares that they have the right to offer this device and that the information provided is accurate.'),
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request, TradeInService $service, TradeInSellerService $sellerService, TradeInDeviceIntakeService $deviceIntake, TradeInRuleResolver $ruleResolver, TradeInPhotoService $photoService): RedirectResponse
    {
        try {
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
