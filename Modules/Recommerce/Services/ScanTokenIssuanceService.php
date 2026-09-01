<?php

namespace Modules\Recommerce\Services;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\ScanToken;
use Modules\Recommerce\Services\DeviceEventRecorder;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\Identity\OpaqueScanToken;
use Modules\Recommerce\Support\Identity\ResolverHost;

class ScanTokenIssuanceService
{
    public function __construct(
        protected AuthorizationGate $authorizationGate,
        protected OpaqueScanToken $tokenService,
        protected ?DeviceEventRecorder $deviceEventRecorder = null
    ) {
    }

    /**
     * Issue a token once. The raw token is returned only to the authorized
     * caller and is never written to application logs. Labels issued under
     * V2.2 retain it only in the encrypted model attribute for exact reprints.
     */
    public function issue(User $user, Device $device, bool $rotate = false): array
    {
        return $this->issueInternal($user, $device, $rotate);
    }

    /**
     * Issue and build a safe response in one transaction. A preparation
     * failure must not leave an active token without a usable response.
     */
    public function issueAndPrepare(
        User $user,
        Device $device,
        bool $rotate,
        callable $builder
    ): array {
        return $this->issueInternal($user, $device, $rotate, $builder);
    }

    /**
     * Issue and render a label in one transaction. A renderer failure must
     * roll back the token and its timeline event so the operator can retry
     * without requiring a privileged token rotation.
     */
    public function issueAndRender(
        User $user,
        Device $device,
        bool $rotate,
        callable $renderer
    ): array {
        return $this->issueAndPrepare($user, $device, $rotate, $renderer);
    }

    /**
     * Reuse the permanent QR identity for an ordinary physical reprint. New
     * labels are issued only once; token rotation remains an exceptional,
     * explicitly authorised recovery action.
     */
    public function issueOrReuseForLabel(User $user, Device $device, callable $renderer): array
    {
        if (ResolverHost::value() === null) {
            throw new InvalidArgumentException('Resolver host is required before token issuance.');
        }

        $this->assertIssuanceScope($user, $device, false);

        return DB::transaction(function () use ($user, $device, $renderer) {
            $lockedDevice = Device::query()
                ->where('id', $device->id)
                ->where('business_id', $user->business_id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertIssuanceScope($user, $lockedDevice, false);

            $activeToken = ScanToken::query()
                ->where('business_id', $user->business_id)
                ->where('device_id', $lockedDevice->id)
                ->where('subject_type', 'DEVICE')
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->first();

            if ($activeToken) {
                $rawToken = $activeToken->raw_token_encrypted;
                if (! is_string($rawToken) || preg_match('/^[A-Fa-f0-9]{64}$/D', $rawToken) !== 1) {
                    throw new \LogicException('This legacy QR identity cannot be safely reprinted because its opaque token predates encrypted label material. Its existing label remains valid; use the documented token-rotation recovery only if a replacement label is approved.');
                }

                $rendered = $renderer([
                    'token_id' => $activeToken->id,
                    'device_id' => $lockedDevice->id,
                    'device_code' => $lockedDevice->device_code,
                    'raw_token' => $rawToken,
                    'qr_path' => '/s/d/'.$rawToken,
                    'reprint' => true,
                ], $lockedDevice);

                if (! is_array($rendered)) {
                    throw new \LogicException('Label renderer returned an invalid result.');
                }

                return $rendered;
            }

            return $this->issueInternal($user, $lockedDevice, false, $renderer);
        });
    }

    /**
     * Resolve permanent token material for a bounded, all-or-nothing label
     * batch. The caller receives each rendered result only after every Device
     * has been re-queried and passed the same issuance gate as a single label.
     *
     * @param array<int, int|string> $deviceIds
     * @return array<int, mixed>
     */
    public function issueOrReuseForLabels(User $user, array $deviceIds, callable $renderer): array
    {
        if (ResolverHost::value() === null) {
            throw new InvalidArgumentException('Resolver host is required before token issuance.');
        }

        $limit = max(1, (int) config('recommerce.bulk_label_limit', 100));
        $ids = array_values(array_unique(array_map(function ($value): int {
            if (! is_numeric($value) || (int) $value < 1) {
                throw new InvalidArgumentException('Label selection contains an invalid Device.');
            }

            return (int) $value;
        }, $deviceIds)));

        if ($ids === [] || count($ids) > $limit) {
            throw new InvalidArgumentException('Choose between 1 and '.$limit.' Devices to print.');
        }

        return DB::transaction(function () use ($user, $ids, $renderer): array {
            $devices = Device::query()
                ->with('product')
                ->where('business_id', $user->business_id)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // A missing row is deliberately indistinguishable from a
            // cross-tenant submission. Do not render an authorised subset.
            if ($devices->count() !== count($ids)) {
                throw new AuthorizationException('Recommerce label scope denied.');
            }

            foreach ($devices as $device) {
                $this->assertIssuanceScope($user, $device, false);
            }

            $activeTokens = ScanToken::query()
                ->where('business_id', $user->business_id)
                ->where('subject_type', 'DEVICE')
                ->where('status', 'ACTIVE')
                ->whereIn('device_id', $devices->pluck('id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('device_id');

            $results = [];
            foreach ($devices as $device) {
                $activeToken = $activeTokens->get($device->id);
                if ($activeToken) {
                    $rawToken = $activeToken->raw_token_encrypted;
                    if (! is_string($rawToken) || preg_match('/^[A-Fa-f0-9]{64}$/D', $rawToken) !== 1) {
                        throw new \LogicException('This legacy QR identity cannot be safely reprinted because its opaque token predates encrypted label material. Its existing label remains valid; use the documented token-rotation recovery only if a replacement label is approved.');
                    }

                    $issued = [
                        'token_id' => $activeToken->id,
                        'device_id' => $device->id,
                        'device_code' => $device->device_code,
                        'raw_token' => $rawToken,
                        'qr_path' => '/s/d/'.$rawToken,
                        'reprint' => true,
                    ];
                } else {
                    $rawToken = $this->tokenService->issue();
                    $token = ScanToken::create([
                        'business_id' => $user->business_id,
                        'subject_type' => 'DEVICE',
                        'device_id' => $device->id,
                        'token_hash' => $this->tokenService->hash($rawToken),
                        'raw_token_encrypted' => $rawToken,
                        'token_hint' => substr($rawToken, -8),
                        'status' => 'ACTIVE',
                        'issued_at' => now(),
                        'issued_by' => $user->id,
                        'reason' => 'INITIAL_ISSUANCE',
                    ]);
                    ($this->deviceEventRecorder ?: new DeviceEventRecorder())->recordLabelIssued(
                        $device,
                        (int) $token->id,
                        false,
                        (int) $user->id
                    );
                    $issued = [
                        'token_id' => $token->id,
                        'device_id' => $device->id,
                        'device_code' => $device->device_code,
                        'raw_token' => $rawToken,
                        'qr_path' => '/s/d/'.$rawToken,
                        'reprint' => false,
                    ];
                }

                $rendered = $renderer($issued, $device);
                if (! is_array($rendered)) {
                    throw new \LogicException('Label renderer returned an invalid result.');
                }

                $results[] = $rendered;
            }

            return $results;
        });
    }

    protected function issueInternal(
        User $user,
        Device $device,
        bool $rotate = false,
        ?callable $renderer = null
    ): array
    {
        if (ResolverHost::value() === null) {
            throw new InvalidArgumentException('Resolver host is required before token issuance.');
        }

        $this->assertIssuanceScope($user, $device, $rotate);

        return DB::transaction(function () use ($user, $device, $rotate, $renderer) {
            $lockedDevice = Device::query()
                ->where('id', $device->id)
                ->where('business_id', $user->business_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Re-check the row after locking it. The pre-transaction Device
            // instance can be stale if custody or the cohort scope changed
            // between authorization and issuance.
            $this->assertIssuanceScope($user, $lockedDevice, $rotate);

            $activeToken = ScanToken::query()
                ->where('business_id', $user->business_id)
                ->where('device_id', $lockedDevice->id)
                ->where('subject_type', 'DEVICE')
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->first();

            if ($activeToken && ! $rotate) {
                throw new \LogicException('Device already has an active scan token.');
            }

            $rawToken = $this->tokenService->issue();
            $newToken = ScanToken::create([
                'business_id' => $user->business_id,
                'subject_type' => 'DEVICE',
                'device_id' => $lockedDevice->id,
                'token_hash' => $this->tokenService->hash($rawToken),
                'raw_token_encrypted' => $rawToken,
                'token_hint' => substr($rawToken, -8),
                'status' => 'ACTIVE',
                'issued_at' => now(),
                'issued_by' => $user->id,
                'reason' => $rotate ? 'ROTATION' : 'INITIAL_ISSUANCE',
            ]);

            if ($activeToken) {
                $activeToken->update([
                    'status' => 'REPLACED',
                    'revoked_at' => now(),
                    'replaced_by_id' => $newToken->id,
                ]);
            }

            ($this->deviceEventRecorder ?: new DeviceEventRecorder())->recordLabelIssued(
                $lockedDevice,
                (int) $newToken->id,
                $rotate,
                (int) $user->id,
                $activeToken?->id
            );

            $issued = [
                'token_id' => $newToken->id,
                'device_id' => $lockedDevice->id,
                'device_code' => $lockedDevice->device_code,
                'raw_token' => $rawToken,
                'qr_path' => '/s/d/'.$rawToken,
            ];

            if ($renderer !== null) {
                $rendered = $renderer($issued, $lockedDevice);
                if (! is_array($rendered)) {
                    throw new \LogicException('Label renderer returned an invalid result.');
                }

                return $rendered;
            }

            return $issued;
        });
    }

    public function assertIssuanceScope(User $user, Device $device, bool $rotate): void
    {
        $businessId = $user->business_id;

        if ((string) $device->business_id !== (string) $businessId
            || empty($device->current_location_id)
            || ! User::can_access_this_location($device->current_location_id, $businessId)
            || ! $this->authorizationGate->allowsWrite(
                $user,
                'recommerce.device.print_label',
                $businessId,
                $device->current_location_id,
                $device->variation_id
            )) {
            throw new AuthorizationException('Recommerce token issuance scope denied.');
        }

        if ($rotate && ! $this->authorizationGate->allowsWrite(
            $user,
            'recommerce.device.rotate_token',
            $businessId,
            $device->current_location_id,
            $device->variation_id
        )) {
            throw new AuthorizationException('Recommerce token rotation scope denied.');
        }
    }
}
