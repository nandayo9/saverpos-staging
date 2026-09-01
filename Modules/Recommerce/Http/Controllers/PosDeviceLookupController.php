<?php

namespace Modules\Recommerce\Http\Controllers;

use App\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Recommerce\Services\DeviceIdentityResolver;
use Modules\Recommerce\Support\AuthorizationGate;

/**
 * Staff-only POS shortcut for an exact physical Device identity.
 *
 * This is intentionally a lookup only: unknown or ambiguous input falls back
 * to UltimatePOS's normal product search, and final sale still revalidates the
 * selected Device inside DeviceLifecycleService.
 */
class PosDeviceLookupController extends Controller
{
    public function resolve(
        Request $request,
        AuthorizationGate $authorizationGate,
        DeviceIdentityResolver $identityResolver
    ) {
        $data = $request->validate([
            'value' => ['required', 'string', 'max:2048'],
            'location_id' => ['required', 'integer', 'min:1'],
        ]);

        $user = auth()->user();
        $businessId = $user ? (int) $user->business_id : 0;
        $locationId = (int) $data['location_id'];

        if (! $user || ! User::can_access_this_location($locationId, $businessId)) {
            return $this->notFoundResponse();
        }

        $device = $identityResolver->resolve($businessId, $data['value']);
        if (! $device
            || ! $device->variation_id
            || ! $authorizationGate->allowsWrite($user, 'recommerce.device.sell', $businessId, $locationId, $device->variation_id)) {
            return $this->notFoundResponse();
        }

        // Do not reveal a Device's private state to a POS user outside its
        // selling branch. A genuine but unavailable Device gets only an
        // operationally useful, non-sensitive message.
        if ((int) $device->current_location_id !== $locationId
            || $device->lifecycle_state !== 'AVAILABLE'
            || $device->stock_participation !== 'ON_HAND') {
            return response()->json([
                'message' => 'This Device is not available to sell at this branch.',
            ], 422)->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        }

        return response()->json([
            'status' => 'MATCHED',
            'variation_id' => (int) $device->variation_id,
            // Prefill the canonical public-safe Device code, never the raw
            // manufacturer identifier or opaque QR token.
            'device_code' => $device->device_code,
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    private function notFoundResponse()
    {
        return response()->json([
            'message' => 'Device scan could not be resolved.',
        ], 404)->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
