<?php

namespace Modules\Recommerce\Http\Controllers;

use App\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\ScanToken;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\Identity\OpaqueScanToken;
use Modules\Recommerce\Support\Identity\ScanInput;
use Modules\Recommerce\Services\DeviceCertificationService;

class ScanController extends Controller
{
    public function index(AuthorizationGate $authorizationGate)
    {
        $user = auth()->user();
        $businessId = $user ? (int) $user->business_id : 0;
        $locationId = (int) config('recommerce.cohort.location_id');

        if (! $user
            || ! User::can_access_this_location($locationId, $businessId)
            || ! $authorizationGate->allowsRead($user, 'recommerce.device.view', $businessId, $locationId)) {
            abort(404);
        }

        return response()->view('recommerce::scans.index', [
            'canReceive' => $authorizationGate->allowsWriteLocation(
                $user,
                'recommerce.receiving.prepare',
                $businessId,
                $locationId
            ),
            'canRepair' => $authorizationGate->allowsRead($user, 'recommerce.repair.view', $businessId, $locationId)
                || $authorizationGate->allowsWriteLocation($user, 'recommerce.repair.intake', $businessId, $locationId),
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function resolve(
        Request $request,
        AuthorizationGate $authorizationGate,
        OpaqueScanToken $tokenService
    ) {
        $input = $request->input('value');

        if (! is_string($input)) {
            return $this->notFoundResponse();
        }

        $parsed = ScanInput::parse($input);

        if ($parsed === null) {
            return $this->notFoundResponse();
        }

        $user = auth()->user();
        $businessId = $user->business_id;
        $device = null;

        if ($parsed['type'] === 'DEVICE_CODE') {
            $device = Device::query()
                ->where('business_id', $businessId)
                ->where('device_code', $parsed['value'])
                ->first();
        } elseif ($parsed['type'] === 'DEVICE_TOKEN') {
            try {
                $tokenHash = $tokenService->hash($parsed['value']);
            } catch (\Throwable $exception) {
                return $this->notFoundResponse();
            }

            $scanToken = ScanToken::query()
                ->where('token_hash', $tokenHash)
                ->where('business_id', $businessId)
                ->where('subject_type', 'DEVICE')
                ->where('status', 'ACTIVE')
                ->with('device')
                ->first();

            $device = $scanToken ? $scanToken->device : null;
        }

        if (! $device
            || empty($device->current_location_id)
            || ! User::can_access_this_location($device->current_location_id, $businessId)
            || ! $authorizationGate->allowsRead(
                $user,
                'recommerce.device.view',
                $businessId,
                $device->current_location_id,
                $device->variation_id
            )) {
            return $this->notFoundResponse();
        }

        return response()->json([
            'type' => 'DEVICE',
            'device_code' => $device->device_code,
            'lifecycle_state' => $device->lifecycle_state,
            'custody_kind' => $device->custody_kind,
            'actions' => [],
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function device(
        string $token,
        OpaqueScanToken $tokenService,
        AuthorizationGate $authorizationGate,
        DeviceCertificationService $certificationService
    ) {
        try {
            $tokenHash = $tokenService->hash($token);
        } catch (\Throwable $exception) {
            return $this->notFoundResponse();
        }

        $scanToken = ScanToken::query()
            ->where('token_hash', $tokenHash)
            ->where('subject_type', 'DEVICE')
            ->where('status', 'ACTIVE')
            ->with(['device.product', 'device.certification'])
            ->first();

        if (! $scanToken || ! $scanToken->device) {
            return $this->notFoundResponse();
        }

        $device = $scanToken->device;
        $user = auth()->user();
        if ($user && ! empty($device->current_location_id)) {
            $businessId = $user->business_id;
            if ((string) $device->business_id === (string) $businessId
                && User::can_access_this_location($device->current_location_id, $businessId)
                && $authorizationGate->allowsRead(
                    $user,
                    'recommerce.device.view',
                    $businessId,
                    $device->current_location_id,
                    $device->variation_id
                )) {
                return redirect('/recommerce/devices/'.rawurlencode($device->device_code))
                    ->header('Cache-Control', 'no-store')
                    ->header('Referrer-Policy', 'no-referrer');
            }
        }

        $profile = $certificationService->publicProfile($device);
        if ($profile === null) {
            return $this->notFoundResponse();
        }

        return response()->view('recommerce::device.public-certification', compact('profile'))
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    protected function notFoundResponse()
    {
        return response()->json([
            'message' => 'Scan could not be resolved.',
        ], 404)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
