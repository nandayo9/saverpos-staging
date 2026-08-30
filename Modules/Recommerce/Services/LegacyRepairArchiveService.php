<?php

namespace Modules\Recommerce\Services;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Recommerce\Support\AuthorizationGate;

class LegacyRepairArchiveService
{
    public const PERMISSION_ARCHIVE = 'recommerce.repair.archive';

    public function __construct(
        protected AuthorizationGate $authorizationGate
    ) {
    }

    public function archive(User $user, string $commandUuid): array
    {
        $authorizedLocations = $this->assertArchiveAccess($user);

        return DB::transaction(function () use ($user, $commandUuid, $authorizedLocations): array {
            DB::table('business')->where('id', $user->business_id)->lockForUpdate()->first();

            $archivedCount = 0;
            $skippedCount = 0;
            $snapshot = [];

            $transactions = DB::table('transactions')
                ->where('business_id', $user->business_id)
                ->whereIn('location_id', $authorizedLocations)
                ->where('sub_type', 'repair')
                ->orderBy('id')
                ->get([
                    'id', 'business_id', 'location_id', 'type', 'status',
                    'sub_type', 'invoice_no', 'transaction_date', 'final_total',
                    'contact_id', 'created_by', 'source',
                ]);

            foreach ($transactions as $transaction) {
                $existing = DB::table('recommerce_repair_archives')
                    ->where('transaction_id', $transaction->id)
                    ->where('business_id', $user->business_id)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $skippedCount++;
                    continue;
                }

                $snapshot = [
                    'source_scope' => 'POS_TRANSACTION_ONLY',
                    'source_completeness' => 'The legacy Repair module source and related tables were not present in this checkout.',
                    'source_transaction' => (array) $transaction,
                    'archived_by' => (int) $user->getAuthIdentifier(),
                    'archived_at' => now()->toIso8601String(),
                    'archive_command_uuid' => $commandUuid,
                ];

                try {
                    $snapshotHash = hash('sha256', (string) json_encode($snapshot, JSON_THROW_ON_ERROR));
                } catch (\JsonException $exception) {
                    throw new LogicException('Repair archive snapshot could not be encoded.', 0, $exception);
                }

                DB::table('recommerce_repair_archives')->insert([
                    'business_id' => $transaction->business_id,
                    'location_id' => $transaction->location_id,
                    'transaction_id' => $transaction->id,
                    'source_sub_type' => (string) $transaction->sub_type,
                    'archive_uuid' => (string) Str::uuid(),
                    'command_uuid' => $commandUuid,
                    'invoice_no' => $transaction->invoice_no,
                    'status' => $transaction->status,
                    'transaction_date' => $transaction->transaction_date,
                    'total_amount' => $transaction->final_total,
                    'contact_id' => $transaction->contact_id,
                    'snapshot_json' => json_encode($snapshot),
                    'snapshot_sha256' => $snapshotHash,
                    'archived_at' => now(),
                    'archived_by' => $user->getAuthIdentifier(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $archivedCount++;
            }

            return [
                'scanned' => $transactions->count(),
                'archived' => $archivedCount,
                'skipped' => $skippedCount,
                'source_scope' => 'POS_TRANSACTION_ONLY',
                'source_completeness' => 'Legacy Repair module records are not available in this checkout.',
            ];
        });
    }

    /**
     * Return archive metadata within the operator's read scope.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(User $user): array
    {
        $locations = $this->assertArchiveReadAccess($user);

        return DB::table('recommerce_repair_archives')
            ->where('business_id', (int) $user->business_id)
            ->whereIn('location_id', $locations)
            ->orderByDesc('archived_at')
            ->get([
                'id', 'archive_uuid', 'transaction_id', 'invoice_no', 'status',
                'location_id', 'total_amount', 'archived_at', 'snapshot_sha256',
            ])
            ->map(fn ($archive): array => (array) $archive)
            ->all();
    }

    /** @return array<string, mixed> */
    public function show(User $user, int $archiveId): array
    {
        $locations = $this->assertArchiveReadAccess($user);
        $archive = DB::table('recommerce_repair_archives')
            ->where('business_id', (int) $user->business_id)
            ->whereIn('location_id', $locations)
            ->where('id', $archiveId)
            ->first();

        if ($archive === null) {
            throw new AuthorizationException();
        }

        $archive = (array) $archive;
        $archive['snapshot_json'] = json_decode((string) $archive['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);

        return $archive;
    }

    /**
     * Return the explicitly configured cohort locations the operator may archive.
     * The AuthorizationGate checks both catalogued and actually granted permission.
     *
     * @return array<int, int>
     */
    protected function assertArchiveAccess(User $user): array
    {
        $businessId = (int) $user->business_id;
        $configuredLocations = config('recommerce.cohort.location_ids', []);
        if (! is_array($configuredLocations) || $configuredLocations === []) {
            $configuredLocations = [config('recommerce.cohort.location_id')];
        }

        $authorizedLocations = [];
        foreach ($configuredLocations as $locationId) {
            $locationId = (int) $locationId;
            if ($locationId > 0 && $this->authorizationGate->allowsWriteLocation(
                $user,
                self::PERMISSION_ARCHIVE,
                $businessId,
                $locationId
            )) {
                $authorizedLocations[] = $locationId;
            }
        }

        if ($authorizedLocations === []) {
            throw new AuthorizationException();
        }

        return array_values(array_unique($authorizedLocations));
    }

    /** @return array<int, int> */
    protected function assertArchiveReadAccess(User $user): array
    {
        $businessId = (int) $user->business_id;
        $configuredLocations = config('recommerce.cohort.location_ids', []);
        if (! is_array($configuredLocations) || $configuredLocations === []) {
            $configuredLocations = [config('recommerce.cohort.location_id')];
        }

        $authorizedLocations = [];
        foreach ($configuredLocations as $locationId) {
            $locationId = (int) $locationId;
            if ($locationId > 0 && $this->authorizationGate->allowsRead(
                $user,
                self::PERMISSION_ARCHIVE,
                $businessId,
                $locationId
            )) {
                $authorizedLocations[] = $locationId;
            }
        }

        if ($authorizedLocations === []) {
            throw new AuthorizationException();
        }

        return array_values(array_unique($authorizedLocations));
    }
}
