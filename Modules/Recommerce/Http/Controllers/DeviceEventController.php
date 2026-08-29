<?php

namespace Modules\Recommerce\Http\Controllers;

use App\User;
use Illuminate\Routing\Controller;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Services\DeviceEventTimelineService;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\Identity\DeviceCode;

class DeviceEventController extends Controller
{
    public function index(
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
            ->where('business_id', $businessId)
            ->where('device_code', $normalizedCode)
            ->first();

        if (! $device || empty($device->current_location_id)) {
            abort(404);
        }

        if (! User::can_access_this_location($device->current_location_id, $businessId)
            || ! $authorizationGate->allowsRead(
                $user,
                'recommerce.device.view',
                $businessId,
                $device->current_location_id,
                $device->variation_id
            )
            || ! $authorizationGate->allowsRead(
                $user,
                'recommerce.audit.view',
                $businessId,
                $device->current_location_id,
                $device->variation_id
            )) {
            abort(404);
        }

        $events = $timelineService->forDevice($device);

        return response()->json([
            'device_code' => $device->device_code,
            'events' => $events,
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
