<?php

namespace Modules\Recommerce\Http\Controllers;

use App\User;
use App\BusinessLocation;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\Identity\DeviceCode;
use Modules\Recommerce\Services\DeviceEventTimelineService;
use Modules\Recommerce\Services\DeviceIdentityResolver;
use Modules\Recommerce\Services\DeviceRegistryQuery;
use Modules\Recommerce\Entities\DeviceSaleDisposition;
use Illuminate\Support\Facades\DB;

class DeviceController extends Controller
{
    /** Read-only, paginated operations registry; lifecycle writes live elsewhere. */
    public function index(
        Request $request,
        AuthorizationGate $authorizationGate,
        DeviceIdentityResolver $identityResolver,
        DeviceRegistryQuery $registryQuery
    )
    {
        $user = auth()->user();
        $businessId = (int) $user->business_id;
        // The initial pilot location remains the default, while a transfer
        // operator can explicitly inspect either approved branch. Do not
        // silently fall back to a different branch when the supplied scope is
        // invalid: the authorization gate below fails closed.
        $locationId = (int) $request->query('location_id', config('recommerce.cohort.location_id'));

        if (! User::can_access_this_location($locationId, $businessId)
            || ! $authorizationGate->allowsRead($user, 'recommerce.device.view', $businessId, $locationId)) {
            abort(404);
        }

        $term = trim((string) $request->query('q', ''));
        $filters = $this->registryFilters($request);
        $variationIds = array_values(array_filter(array_map('intval', (array) config('recommerce.cohort.variation_ids', []))));
        $base = $registryQuery->base($businessId, $locationId, $variationIds);
        $filtered = $registryQuery->apply(clone $base, $filters);

        // Identity resolution stays exact and is intentionally attempted
        // before descriptive matching. A serial/IMEI/QR can never select a
        // neighbouring Device through a partial registry search.
        $resolvedDevice = $term === '' ? null : $identityResolver->resolve($businessId, $term);
        if ($resolvedDevice) {
            $filtered->whereKey($resolvedDevice->id);
        } elseif ($term !== '') {
            $registryQuery->applyDescriptiveSearch($filtered, $term);
        }

        // Quick-filter totals ignore the selected quick scope. Quick links
        // reset it too, so each total matches its resulting records.
        $summary = (clone $registryQuery->apply(clone $base, array_diff_key($filters, ['state' => true, 'custody' => true, 'transfer_state' => true])))
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN lifecycle_state = 'AVAILABLE' THEN 1 ELSE 0 END) as ready, SUM(CASE WHEN lifecycle_state = 'RECEIVED_PENDING_INSPECTION' THEN 1 ELSE 0 END) as inspection, SUM(CASE WHEN lifecycle_state = 'REFURBISHMENT_REQUIRED' THEN 1 ELSE 0 END) as exceptions, SUM(CASE WHEN transfer_state <> 'NONE' THEN 1 ELSE 0 END) as transfer")
            ->first();

        $devices = $filtered->orderBy('device_code')
            ->paginate(min(max((int) $request->query('per_page', 50), 10), 100))
            ->withQueryString();

        $filterOptions = [
            'products' => DB::table('products')->whereIn('id', (clone $base)->select('product_id')->distinct())->orderBy('name')->limit(200)->get(['id', 'name']),
            'variations' => DB::table('variations')->whereIn('id', $variationIds)->orderBy('name')->limit(200)->get(['id', 'name', 'sub_sku']),
            'categories' => (clone $base)->whereNotNull('category_code')->distinct()->orderBy('category_code')->limit(100)->pluck('category_code'),
        ];

        return response()->view('recommerce::device.index', [
            'devices' => $devices,
            'locationId' => $locationId,
            'locationName' => BusinessLocation::query()->where('id', $locationId)->value('name'),
            'query' => $term,
            'state' => $filters['state'],
            'labelStatus' => $filters['label_status'],
            'filters' => $filters,
            'summary' => $summary,
            'filterOptions' => $filterOptions,
            'quickFilters' => [
                'AVAILABLE' => 'Ready for sale',
                'RECEIVED_PENDING_INSPECTION' => 'Needs inspection',
                'REFURBISHMENT_REQUIRED' => 'Exceptions',
            ],
            'canReceive' => $authorizationGate->allowsWriteLocation(
                $user,
                'recommerce.receiving.prepare',
                $businessId,
                $locationId
            ),
            // Every listed Device has already passed the variation-level read
            // cohort filter above, so this location-level check safely
            // controls the registry's direct print action without exposing a
            // second per-row permission implementation in the view.
            'canPrintLabel' => $authorizationGate->allowsWriteLocation(
                $user,
                'recommerce.device.print_label',
                $businessId,
                $locationId
            ),
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /** Lightweight, permission-scoped drawer payload; never includes raw identifiers. */
    public function quickView(
        string $deviceCode,
        AuthorizationGate $authorizationGate,
        DeviceEventTimelineService $timelineService
    ) {
        if (! DeviceCode::isValid($deviceCode)) {
            abort(404);
        }

        $user = auth()->user();
        $businessId = (int) $user->business_id;
        $device = Device::query()->with(['product', 'variation', 'currentLocation', 'inspection', 'certification', 'purchaseAssignment', 'latestLabelJobItem.job'])
            ->where('business_id', $businessId)->where('device_code', DeviceCode::normalize($deviceCode))->first();
        if (! $device) {
            abort(404);
        }
        $locationId = (int) $device->current_location_id;
        if ($locationId < 1 && $device->custody_kind === 'IN_TRANSIT') {
            $assignment = $device->transferAssignments()
                ->whereIn('status', ['IN_TRANSIT', 'RECEIVED', 'RECEIVED_WITH_ISSUE'])
                ->orderByDesc('id')->first();
            foreach (array_filter([$assignment?->to_location_id, $assignment?->from_location_id]) as $candidateLocationId) {
                if (User::can_access_this_location($candidateLocationId, $businessId)
                    && $authorizationGate->allowsRead($user, 'recommerce.device.view', $businessId, $candidateLocationId, $device->variation_id)) {
                    $locationId = (int) $candidateLocationId;
                    break;
                }
            }
        }
        if ($locationId < 1) {
            $saleDisposition = DeviceSaleDisposition::query()
                ->where('device_id', $device->id)
                ->whereNotNull('active_sale_key')
                ->orderByDesc('id')
                ->first();
            $locationId = $saleDisposition
                ? (int) DB::table('transactions')->where('id', $saleDisposition->sale_transaction_id)->value('location_id')
                : 0;
        }
        if ($locationId < 1 || ! User::can_access_this_location($locationId, $businessId)
            || ! $authorizationGate->allowsRead($user, 'recommerce.device.view', $businessId, $locationId, $device->variation_id)) {
            abort(404);
        }

        $latestLabel = $device->latestLabelJobItem;
        $labelStatus = ! $latestLabel ? 'Not printed' : ($latestLabel->job?->status === 'REPRINT_CONFIRMED' ? 'Reprinted' : ($latestLabel->job?->status === 'PRINT_CONFIRMED' ? 'Printed' : 'Print view opened'));
        $serial = trim((string) $device->manufacturer_serial_display);
        $serialHint = $serial === '' ? 'No display-safe serial recorded' : 'Serial ending '.substr($serial, -4);
        $auditVisible = $authorizationGate->allowsRead($user, 'recommerce.audit.view', $businessId, $locationId, $device->variation_id);
        $economicsVisible = $authorizationGate->allowsRead($user, 'recommerce.device.view_economics', $businessId, $locationId, $device->variation_id);

        return response()->view('recommerce::device.quick-view', [
            'device' => $device,
            'stateLabel' => ucwords(strtolower(str_replace('_', ' ', $device->lifecycle_state))),
            'holder' => $device->custody_kind === 'LOCATION' ? (optional($device->currentLocation)->name ?: 'Branch not recorded') : ucwords(strtolower(str_replace('_', ' ', $device->custody_kind))),
            'inventoryLabel' => ucwords(strtolower(str_replace('_', ' ', $device->stock_participation))),
            'labelStatus' => $labelStatus,
            'serialHint' => $serialHint,
            'auditVisible' => $auditVisible,
            'economicsVisible' => $economicsVisible,
            'events' => $auditVisible ? $timelineService->forDevice($device)->take(6) : collect(),
            'inspectionUrl' => $authorizationGate->allowsRead($user, 'recommerce.inspection.view', $businessId, $locationId, $device->variation_id) ? route('recommerce.inspection.index', ['location_id' => $locationId]) : null,
            'repairUrl' => $authorizationGate->allowsRead($user, 'recommerce.repair.view', $businessId, $locationId, $device->variation_id) ? route('recommerce.repair.index') : null,
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    /** @return array<string, mixed> */
    private function registryFilters(Request $request): array
    {
        $pick = static fn (string $key, array $allowed): string => in_array($value = strtoupper(trim((string) $request->query($key, ''))), $allowed, true) ? $value : '';

        return [
            'state' => $pick('state', DeviceRegistryQuery::LIFECYCLE_STATES),
            'transfer_state' => $pick('transfer_state', ['ACTIVE']),
            'label_status' => $pick('label_status', DeviceRegistryQuery::LABEL_STATES),
            'custody' => $pick('custody', DeviceRegistryQuery::CUSTODY_KINDS),
            'inventory' => $pick('inventory', DeviceRegistryQuery::STOCK_STATES),
            'inspection' => $pick('inspection', ['PENDING', 'ASSIGNED', 'IN_INSPECTION', 'FAILED', 'PASSED']),
            'grade' => $pick('grade', ['A', 'B', 'C', 'D']),
            'product_id' => max(0, (int) $request->query('product_id', 0)),
            'variation_id' => max(0, (int) $request->query('variation_id', 0)),
            'category' => strtoupper(substr(trim((string) $request->query('category', '')), 0, 32)),
            'age_days' => min(max(0, (int) $request->query('age_days', 0)), 36500),
            'received_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('received_from', '')) ? $request->query('received_from') : '',
            'received_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('received_to', '')) ? $request->query('received_to') : '',
            'has_repair' => $request->boolean('has_repair'),
        ];
    }

    public function show(
        string $deviceCode,
        AuthorizationGate $authorizationGate,
        DeviceEventTimelineService $timelineService
    )
    {
        if (! DeviceCode::isValid($deviceCode)) {
            abort(404);
        }

        $user = auth()->user();
        $businessId = $user->business_id;
        $normalizedCode = DeviceCode::normalize($deviceCode);

        $device = Device::query()
            ->with([
                'product.brand',
                'variation',
                'currentLocation',
                'purchaseAssignment',
                'inspection',
                'intakeObservations',
                'costOverrideEvents',
                'certification',
                'labelJobItems.job',
                'ownershipPeriods' => fn ($query) => $query->orderBy('starts_at')->orderBy('id'),
                'custodyPeriods' => fn ($query) => $query->with('location')->orderBy('starts_at')->orderBy('id'),
            ])
            ->where('business_id', $businessId)
            ->where('device_code', $normalizedCode)
            ->first();

        if (! $device) {
            abort(404);
        }

        // Sold devices deliberately have no current branch location. Use the
        // immutable selling branch solely for staff authorization so customer
        // history remains viewable without pretending it is still on-hand.
        $accessLocationId = $device->current_location_id;
        if (empty($accessLocationId) && $device->custody_kind === 'IN_TRANSIT') {
            $transferAssignment = $device->transferAssignments()
                ->whereIn('status', ['IN_TRANSIT', 'RECEIVED', 'RECEIVED_WITH_ISSUE'])
                ->orderByDesc('id')
                ->first();
            $candidateLocations = array_filter([$transferAssignment?->to_location_id, $transferAssignment?->from_location_id]);
            foreach ($candidateLocations as $candidateLocationId) {
                if (User::can_access_this_location($candidateLocationId, $businessId)
                    && $authorizationGate->allowsRead($user, 'recommerce.device.view', $businessId, $candidateLocationId, $device->variation_id)) {
                    $accessLocationId = $candidateLocationId;
                    break;
                }
            }
        }
        if (empty($accessLocationId)) {
            $saleDisposition = DeviceSaleDisposition::query()
                ->where('device_id', $device->id)
                ->whereNotNull('active_sale_key')
                ->orderByDesc('id')
                ->first();
            $accessLocationId = $saleDisposition
                ? DB::table('transactions')->where('id', $saleDisposition->sale_transaction_id)->value('location_id')
                : null;
        }
        if (empty($accessLocationId)) {
            abort(404);
        }

        if (! User::can_access_this_location($accessLocationId, $businessId)) {
            abort(404);
        }

        if (! $authorizationGate->allowsRead(
            $user,
            'recommerce.device.view',
            $businessId,
            $accessLocationId,
            $device->variation_id
        )) {
            abort(404);
        }

        $auditVisible = $authorizationGate->allowsRead(
            $user,
            'recommerce.audit.view',
            $businessId,
            $accessLocationId,
            $device->variation_id
        );

        $labelPrintEnabled = $authorizationGate->allowsWrite(
            $user,
            'recommerce.device.print_label',
            $businessId,
            $accessLocationId,
            $device->variation_id
        );
        $labelItems = $device->labelJobItems->sortByDesc('id')->values();
        $latestLabelItem = $labelItems->first();
        $labelStatus = $latestLabelItem
            ? (($latestLabelItem->job?->status === 'REPRINT_CONFIRMED') ? 'Reprinted' : (($latestLabelItem->job?->status === 'PRINT_CONFIRMED') ? 'Printed' : 'Print view opened'))
            : 'Not printed';

        $certificationPublishEnabled = $authorizationGate->allowsWrite(
            $user,
            'recommerce.device.certify',
            $businessId,
            $accessLocationId,
            $device->variation_id
        ) && ! empty($device->sold_at);

        $acquisition = null;
        if ($device->purchaseAssignment) {
            $acquisition = DB::table('transactions as t')
                ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
                ->leftJoin('business_locations as l', 'l.id', '=', 't.location_id')
                ->where('t.id', $device->purchaseAssignment->transaction_id)
                ->select(['t.id', 't.ref_no', 't.invoice_no', 't.transaction_date', 'c.name as supplier_name', 'c.supplier_business_name', 'l.name as location_name'])
                ->first();
        }
        $economicsVisible = $authorizationGate->allowsRead(
            $user,
            'recommerce.device.view_economics',
            $businessId,
            $accessLocationId,
            $device->variation_id
        );

        // The Device record is the operator's physical-unit view. Keep this
        // summary limited to bounded, non-sensitive profile fields; hashed
        // identifiers and QR token material never leave their protected
        // stores just to make the detail screen more descriptive.
        $specifications = (array) ($device->specifications_json ?: []);
        $safeText = static function ($value): ?string {
            if (! is_string($value) && ! is_numeric($value)) {
                return null;
            }

            $value = preg_replace('/[\x00-\x1F\x7F]/', '', trim((string) $value));

            if ($value === '') {
                return null;
            }

            return function_exists('mb_substr') ? mb_substr($value, 0, 160) : substr($value, 0, 160);
        };
        $productName = $safeText(optional($device->product)->name);
        $productBrand = $safeText(optional(optional($device->product)->brand)->name);
        $category = $safeText($device->category_code);
        $serialDisplay = $safeText($device->manufacturer_serial_display);
        $serialSuffix = $serialDisplay === null ? null : (function_exists('mb_substr') ? mb_substr($serialDisplay, -4) : substr($serialDisplay, -4));

        $deviceProfile = [
            'brand' => $safeText($specifications['brand'] ?? null) ?: $productBrand ?: 'Not recorded',
            'model' => $safeText($specifications['model'] ?? null) ?: $productName ?: 'Not recorded',
            'category' => $category ? ucwords(strtolower(str_replace('_', ' ', $category))) : 'Not recorded',
            'product' => $productName ?: 'Not recorded',
            'variation' => $safeText(optional($device->variation)->name) ?: 'Not recorded',
            'serial_hint' => $serialSuffix === null ? 'Not recorded' : 'Ending '.$serialSuffix,
        ];
        $technicalSpecificationLabels = [
            'cpu' => 'Processor', 'ram' => 'Memory', 'storage' => 'Storage', 'gpu' => 'Graphics',
            'display_size' => 'Display', 'operating_system' => 'Operating system', 'color' => 'Colour',
            'screen_condition' => 'Screen condition', 'body_condition' => 'Body condition',
        ];
        $technicalSpecifications = [];
        foreach ($technicalSpecificationLabels as $key => $label) {
            $value = $safeText($specifications[$key] ?? null);
            if ($value !== null) {
                $technicalSpecifications[$label] = $value;
            }
        }

        $events = $auditVisible ? $timelineService->forDevice($device) : collect();

        return response()->view('recommerce::device.show', [
            'device' => $device,
            'auditVisible' => $auditVisible,
            'events' => $events,
            'labelPrintEnabled' => $labelPrintEnabled,
            'labelStatus' => $labelStatus,
            'hasLabelPrintView' => $latestLabelItem !== null,
            'certificationPublishEnabled' => $certificationPublishEnabled,
            'acquisition' => $acquisition,
            'economicsVisible' => $economicsVisible,
            'deviceProfile' => $deviceProfile,
            'technicalSpecifications' => $technicalSpecifications,
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
