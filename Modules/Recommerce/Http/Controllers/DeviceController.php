<?php

namespace Modules\Recommerce\Http\Controllers;

use App\User;
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
            ->with(['product', 'variation'])
            ->where('business_id', $businessId)
            ->where('current_location_id', $locationId)
            ->whereIn('variation_id', array_values(array_filter(array_map('intval', (array) config('recommerce.cohort.variation_ids', [])))))
            ->orderBy('device_code');

        $term = trim((string) $request->query('q', ''));
        $state = strtoupper(trim((string) $request->query('state', '')));
        $state = in_array($state, ['RECEIVED_PENDING_INSPECTION', 'REFURBISHMENT_REQUIRED', 'AVAILABLE'], true)
            ? $state
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

        $devices = $query->limit(100)->get()->filter(function (Device $device) use ($authorizationGate, $user, $businessId, $locationId) {
            return $authorizationGate->allowsRead(
                $user,
                'recommerce.device.view',
                $businessId,
                $locationId,
                $device->variation_id
            );
        })->values();

        return response()->view('recommerce::device.index', [
            'devices' => $devices,
            'locationId' => $locationId,
            'query' => $term,
            'state' => $state,
            'canReceive' => $authorizationGate->allowsWriteLocation(
                $user,
                'recommerce.receiving.prepare',
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
                'product',
                'variation',
                'purchaseAssignment',
                'inspection',
                'intakeObservations',
                'costOverrideEvents',
                'certification',
                'ownershipPeriods' => fn ($query) => $query->orderBy('starts_at')->orderBy('id'),
                'custodyPeriods' => fn ($query) => $query->orderBy('starts_at')->orderBy('id'),
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

        $events = $auditVisible ? $timelineService->forDevice($device) : collect();

        return response()->view('recommerce::device.show', [
            'device' => $device,
            'auditVisible' => $auditVisible,
            'events' => $events,
            'labelPrintEnabled' => $labelPrintEnabled,
            'certificationPublishEnabled' => $certificationPublishEnabled,
            'acquisition' => $acquisition,
            'economicsVisible' => $economicsVisible,
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
