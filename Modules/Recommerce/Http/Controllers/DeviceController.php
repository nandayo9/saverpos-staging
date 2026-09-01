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
use Modules\Recommerce\Entities\DeviceSaleDisposition;
use Illuminate\Support\Facades\DB;

class DeviceController extends Controller
{
    /**
     * Safe registry for native POS navigation. Identifiers are deliberately
     * excluded from both search and the result projection.
     */
    public function index(Request $request, AuthorizationGate $authorizationGate)
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

        $query = Device::query()
            ->with(['product', 'variation', 'currentLocation', 'labelJobItems.job'])
            ->where('business_id', $businessId)
            ->where(function ($builder) use ($locationId) {
                $builder->where('current_location_id', $locationId)
                    ->orWhere(function ($transit) use ($locationId) {
                        $transit->where('custody_kind', 'IN_TRANSIT')
                            ->whereHas('transferAssignments', function ($assignment) use ($locationId) {
                                $assignment->whereIn('status', ['IN_TRANSIT', 'RECEIVED', 'RECEIVED_WITH_ISSUE'])
                                    ->where(function ($scope) use ($locationId) {
                                        $scope->where('from_location_id', $locationId)
                                            ->orWhere('to_location_id', $locationId);
                                    });
                            });
                    });
            })
            ->whereIn('variation_id', array_values(array_filter(array_map('intval', (array) config('recommerce.cohort.variation_ids', [])))))
            ->orderBy('device_code');

        $term = trim((string) $request->query('q', ''));
        $state = strtoupper(trim((string) $request->query('state', '')));
        $labelStatus = strtoupper(trim((string) $request->query('label_status', '')));
        $state = in_array($state, ['RECEIVED_PENDING_INSPECTION', 'REFURBISHMENT_REQUIRED', 'AVAILABLE'], true)
            ? $state
            : '';
        $labelStatus = in_array($labelStatus, ['NEEDS_LABEL', 'NOT_PRINTED', 'PRINT_VIEW_OPENED', 'PRINTED', 'REPRINTED'], true)
            ? $labelStatus
            : '';
        if ($state !== '') {
            $query->where('lifecycle_state', $state);
        }
        if ($term !== '') {
            $query->where(function ($builder) use ($term) {
                $builder->where('device_code', 'like', '%'.strtoupper($term).'%')
                    ->orWhereHas('product', function ($product) use ($term) {
                        $product->where('name', 'like', '%'.$term.'%');
                    });
            });
        }

        $devices = $query->limit(100)->get()->filter(function (Device $device) use ($authorizationGate, $user, $businessId, $locationId, $labelStatus) {
            if (! $authorizationGate->allowsRead(
                $user,
                'recommerce.device.view',
                $businessId,
                $locationId,
                $device->variation_id
            )) {
                return false;
            }

            if ($labelStatus === '') {
                return true;
            }

            $latestItem = $device->labelJobItems->sortByDesc('id')->first();
            $currentStatus = ! $latestItem
                ? 'NOT_PRINTED'
                : (($latestItem->job?->status === 'REPRINT_CONFIRMED')
                    ? 'REPRINTED'
                    : (($latestItem->job?->status === 'PRINT_CONFIRMED') ? 'PRINTED' : 'PRINT_VIEW_OPENED'));

            return $labelStatus === 'NEEDS_LABEL'
                ? in_array($currentStatus, ['NOT_PRINTED', 'PRINT_VIEW_OPENED'], true)
                : $currentStatus === $labelStatus;
        })->values();

        return response()->view('recommerce::device.index', [
            'devices' => $devices,
            'locationId' => $locationId,
            'locationName' => BusinessLocation::query()->where('id', $locationId)->value('name'),
            'query' => $term,
            'state' => $state,
            'labelStatus' => $labelStatus,
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
