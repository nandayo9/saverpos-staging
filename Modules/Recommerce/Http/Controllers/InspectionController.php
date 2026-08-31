<?php

namespace Modules\Recommerce\Http\Controllers;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceInspection;
use Modules\Recommerce\Entities\DeviceIntakeObservation;
use Modules\Recommerce\Services\DeviceInspectionService;
use Modules\Recommerce\Support\AuthorizationGate;

class InspectionController extends Controller
{
    public function index(Request $request, AuthorizationGate $authorizationGate)
    {
        $user = auth()->user();
        $businessId = (int) $user->business_id;
        $locationId = (int) $request->query('location_id', config('recommerce.cohort.location_id'));
        if (! User::can_access_this_location($locationId, $businessId)
            || ! $authorizationGate->allowsRead($user, 'recommerce.inspection.view', $businessId, $locationId)) {
            abort(404);
        }

        $requestedStatus = strtoupper(trim((string) $request->query('status', '')));
        $allowedStatuses = [
            DeviceInspectionService::STATUS_PENDING,
            DeviceInspectionService::STATUS_ASSIGNED,
            DeviceInspectionService::STATUS_IN_INSPECTION,
            DeviceInspectionService::STATUS_FAILED,
            DeviceInspectionService::STATUS_PASSED,
        ];
        $status = in_array($requestedStatus, $allowedStatuses, true) ? $requestedStatus : '';
        $base = DeviceInspection::query()
            ->join('recommerce_devices as d', 'd.id', '=', 'recommerce_device_inspections.device_id')
            ->leftJoin('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('variations as v', 'v.id', '=', 'd.variation_id')
            ->leftJoin('transactions as t', 't.id', '=', 'recommerce_device_inspections.purchase_transaction_id')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('recommerce_device_inspections.business_id', $businessId)
            ->where('recommerce_device_inspections.location_id', $locationId)
            ->whereIn('recommerce_device_inspections.variation_id', array_values(array_filter(array_map('intval', (array) config('recommerce.cohort.variation_ids', [])))))
            ->select([
                'recommerce_device_inspections.*', 'd.device_code', 'd.lifecycle_state',
                'p.name as product_name', 'v.name as variation_name', 't.ref_no as purchase_reference',
                'c.name as supplier_name', 'c.supplier_business_name',
            ])
            ->selectSub(
                DeviceIntakeObservation::query()->selectRaw('COUNT(*)')
                    ->whereColumn('inspection_id', 'recommerce_device_inspections.id')
                    ->where('status', 'OPEN'),
                'open_observation_count'
            );
        if ($status !== '') {
            $base->where('recommerce_device_inspections.status', $status);
        } else {
            $base->whereIn('recommerce_device_inspections.status', [
                DeviceInspectionService::STATUS_PENDING,
                DeviceInspectionService::STATUS_ASSIGNED,
                DeviceInspectionService::STATUS_IN_INSPECTION,
                DeviceInspectionService::STATUS_FAILED,
            ]);
        }
        $inspections = $base->orderBy('recommerce_device_inspections.received_at')->limit(200)->get()
            ->filter(fn ($inspection) => $authorizationGate->allowsRead(
                $user,
                'recommerce.inspection.view',
                $businessId,
                $locationId,
                (int) $inspection->variation_id
            ))->values();

        $counts = DeviceInspection::query()
            ->where('business_id', $businessId)->where('location_id', $locationId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')->pluck('count', 'status');
        $inspectors = DB::table('users')->where('business_id', $businessId)->orderBy('id')->limit(100)->get(['id']);
        $canAssign = $authorizationGate->allowsWriteLocation($user, 'recommerce.inspection.assign', $businessId, $locationId);
        $canComplete = $authorizationGate->allowsWriteLocation($user, 'recommerce.inspection.complete', $businessId, $locationId);

        return response()->view('recommerce::inspection.index', compact('locationId', 'status', 'inspections', 'counts', 'inspectors', 'canAssign', 'canComplete'))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function assign(Request $request, DeviceInspectionService $service)
    {
        try {
            $data = $request->validate([
                'device_ids' => ['required', 'array', 'min:1', 'max:50'],
                'device_ids.*' => ['required', 'integer', 'min:1'],
                'inspector_id' => ['required', 'integer', 'min:1'],
            ]);
            $count = $service->assign(auth()->user(), $data['device_ids'], (int) $data['inspector_id']);
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (ValidationException|InvalidArgumentException|LogicException $exception) {
            return back()->withErrors(['inspection' => $exception->getMessage()]);
        }

        return back()->with('status', $count.' Device inspection'.($count === 1 ? '' : 's').' assigned.');
    }

    public function start(int $deviceId, DeviceInspectionService $service)
    {
        try {
            $device = Device::query()->where('business_id', auth()->user()->business_id)->findOrFail($deviceId);
            $service->start(auth()->user(), $device);
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (InvalidArgumentException|LogicException $exception) {
            return back()->withErrors(['inspection' => $exception->getMessage()]);
        }

        return back()->with('status', 'Inspection started.');
    }

    public function complete(Request $request, int $deviceId, DeviceInspectionService $service)
    {
        try {
            $data = $request->validate([
                'outcome' => ['required', 'in:PASS,FAIL'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]);
            $device = Device::query()->where('business_id', auth()->user()->business_id)->findOrFail($deviceId);
            $completed = $service->complete(auth()->user(), $device, $data['outcome'] === 'PASS', $data['notes'] ?? null);
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (ValidationException|InvalidArgumentException|LogicException $exception) {
            return back()->withErrors(['inspection' => $exception->getMessage()]);
        }

        return back()->with('status', $completed->device_code.' marked '.($data['outcome'] === 'PASS' ? 'available' : 'action required').'.');
    }
}
