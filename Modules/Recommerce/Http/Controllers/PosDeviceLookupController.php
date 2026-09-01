<?php

namespace Modules\Recommerce\Http\Controllers;

use App\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Recommerce\Services\DeviceIdentityResolver;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\Identity\DeviceCode;

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
        if (! $device) {
            // A syntactically valid SaverBro Device ID is never a normal
            // product barcode. Give POS staff an actionable answer instead of
            // dropping into the generic "no products found" autocomplete.
            if (DeviceCode::isValid($data['value'])) {
                return $this->unregisteredDeviceResponse($data['value']);
            }

            return $this->notFoundResponse();
        }

        if (! $device->variation_id) {
            return response()->json([
                'message' => 'This Device has no sellable product assigned. Ask a manager to correct its Device record.',
            ], 422)->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        }

        if (! $authorizationGate->allowsWrite($user, 'recommerce.device.sell', $businessId, $locationId, $device->variation_id)) {
            return response()->json([
                'message' => 'You do not have permission to sell this Device at the selected POS branch.',
            ], 403)->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        }

        if ((int) $device->current_location_id !== $locationId) {
            return response()->json([
                'message' => 'This Device is held at a different branch. Switch the POS branch or complete a Device transfer before sale.',
            ], 422)->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        }

        if ($device->lifecycle_state !== 'AVAILABLE') {
            return response()->json([
                'message' => 'This Device is currently '.strtolower(str_replace('_', ' ', $device->lifecycle_state)).'. Only Available Devices can be sold.',
            ], 422)->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        }

        if ($device->stock_participation !== 'ON_HAND') {
            return response()->json([
                'message' => 'This Device is not on hand for sale. Resolve its current reservation or movement before selling it.',
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

    private function unregisteredDeviceResponse(string $deviceCode)
    {
        return response()->json([
            'code' => 'DEVICE_NOT_REGISTERED',
            'message' => 'SaverBro Device ID '.DeviceCode::normalize($deviceCode).' is not registered. Register it through Purchase Receiving before sale.',
        ], 404)->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
