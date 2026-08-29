<?php

namespace Modules\Recommerce\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Services\DeviceCertificationService;

class DeviceCertificationController extends Controller
{
    public function store(Request $request, int $deviceId, DeviceCertificationService $service)
    {
        $device = Device::query()
            ->where('business_id', auth()->user()->business_id)
            ->findOrFail($deviceId);

        try {
            $service->publish(auth()->user(), $device, [
                'grade' => $request->input('grade'),
                'qc_passed' => $request->boolean('qc_passed'),
                'battery_health_percent' => $request->input('battery_health_percent'),
                'purchased_at' => $request->input('purchased_at'),
                'warranty_expires_at' => $request->input('warranty_expires_at'),
            ]);
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['certification' => $exception->getMessage()]);
        }

        return redirect()
            ->route('recommerce.devices.show', ['deviceCode' => $device->device_code])
            ->with('status', 'SaverBro certification is published.');
    }
}
