<?php

namespace Modules\Recommerce\Http\Controllers;

use App\User;
use App\BusinessLocation;
use App\Transaction;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\ScanToken;
use Modules\Recommerce\Entities\DeviceTransferAssignment;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\Identity\OpaqueScanToken;
use Modules\Recommerce\Services\DeviceCertificationService;
use Modules\Recommerce\Services\DeviceIdentityResolver;

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
        OpaqueScanToken $tokenService,
        ?DeviceIdentityResolver $identityResolver = null
    ) {
        $input = $request->input('value');

        if (! is_string($input)) {
            return $this->notFoundResponse();
        }

        $user = auth()->user();
        $businessId = $user->business_id;
        $device = ($identityResolver ?: new DeviceIdentityResolver($tokenService))->resolve((int) $businessId, $input);

        if (! $device || ! $this->authorizedLocationForDevice($user, $device, $authorizationGate)) {
            return $this->notFoundResponse();
        }

        $device->loadMissing('currentLocation');

        return response()->json([
            'type' => 'DEVICE',
            'device_code' => $device->device_code,
            'product' => optional($device->product)->name,
            'lifecycle_state' => $device->lifecycle_state,
            'custody_kind' => $device->custody_kind,
            'location_name' => $device->custody_kind === 'LOCATION' ? optional($device->currentLocation)->name : null,
            'transfer' => $this->transferContext($device),
            // Future workflow contexts can add a permitted target here; the
            // stable resolver is deliberately shared rather than per-module.
            'actions' => [['key' => 'VIEW_DEVICE', 'url' => '/recommerce/devices/'.rawurlencode($device->device_code)]],
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
            return $this->publicUnavailableResponse();
        }

        $scanToken = ScanToken::query()
            ->where('token_hash', $tokenHash)
            ->where('subject_type', 'DEVICE')
            ->where('status', 'ACTIVE')
            ->with(['device.product', 'device.certification'])
            ->first();

        if (! $scanToken || ! $scanToken->device) {
            return $this->publicUnavailableResponse();
        }

        $device = $scanToken->device;
        $user = auth()->user();
        if ($user) {
            $businessId = $user->business_id;
            if ((string) $device->business_id === (string) $businessId
                && $this->authorizedLocationForDevice($user, $device, $authorizationGate)) {
                return redirect('/recommerce/devices/'.rawurlencode($device->device_code))
                    ->header('Cache-Control', 'no-store')
                    ->header('Referrer-Policy', 'no-referrer');
            }
        }

        $profile = $certificationService->publicProfile($device);
        if ($profile === null) {
            return $this->publicUnavailableResponse();
        }

        return response()->view('recommerce::device.public-certification', compact('profile'))
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    /** A transit scan is visible only to authorized source or destination staff. */
    private function authorizedLocationForDevice($user, Device $device, AuthorizationGate $authorizationGate): ?int
    {
        $businessId = (int) $user->business_id;
        $assignment = DeviceTransferAssignment::query()
            ->where('device_id', $device->id)
            ->whereIn('status', ['IN_TRANSIT', 'RECEIVED', 'RECEIVED_WITH_ISSUE'])
            ->orderByDesc('id')
            ->first();
        // Preserve both branch scopes while a transfer remains open. A
        // partially received Device has a destination location but source
        // staff still need a truthful read-only scan result.
        $candidateLocations = array_values(array_unique(array_filter([
            (int) $device->current_location_id,
            (int) optional($assignment)->to_location_id,
            (int) optional($assignment)->from_location_id,
        ])));

        foreach ($candidateLocations as $locationId) {
            if (User::can_access_this_location($locationId, $businessId)
                && $authorizationGate->allowsRead($user, 'recommerce.device.view', $businessId, $locationId, $device->variation_id)) {
                return $locationId;
            }
        }

        return null;
    }

    /** Staff-only transfer context for a normal Device scan; never public QR output. */
    private function transferContext(Device $device): ?array
    {
        $assignment = DeviceTransferAssignment::query()
            ->where('device_id', $device->id)
            ->whereIn('status', ['IN_TRANSIT', 'RECEIVED', 'RECEIVED_WITH_ISSUE'])
            ->orderByDesc('id')
            ->first();
        if (! $assignment) {
            return null;
        }
        $names = BusinessLocation::query()
            ->whereIn('id', [$assignment->from_location_id, $assignment->to_location_id])
            ->pluck('name', 'id');
        $transfer = Transaction::query()->find($assignment->sell_transfer_transaction_id);

        return [
            'state' => $assignment->status === 'IN_TRANSIT' ? 'IN_TRANSIT' : 'RECEIVED_PENDING_COMPLETION',
            'reference' => $transfer?->ref_no ?: 'Transfer #'.$assignment->sell_transfer_transaction_id,
            'from_location' => $names->get($assignment->from_location_id, 'Source #'.$assignment->from_location_id),
            'to_location' => $names->get($assignment->to_location_id, 'Destination #'.$assignment->to_location_id),
        ];
    }

    /**
     * A permanent physical QR must remain useful before a customer profile is
     * published, without revealing whether its opaque token names a Device.
     * Unknown, revoked, and not-yet-public tokens therefore get the exact
     * same neutral document and status.
     */
    protected function publicUnavailableResponse()
    {
        return response()->view('recommerce::device.public-unavailable', [], 404)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    protected function notFoundResponse()
    {
        return response()->json([
            'message' => 'No matching Device was found in your authorized scope. Check the code or QR label, then try the serial, IMEI, or service tag. No Device was created.',
        ], 404)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
