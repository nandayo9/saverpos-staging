<?php

namespace Modules\Recommerce\Services;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\StockCountAudit;
use Modules\Recommerce\Entities\StockCountEntry;
use Modules\Recommerce\Entities\StockCountException;
use Modules\Recommerce\Entities\StockCountItem;
use Modules\Recommerce\Entities\StockCountSession;
use Modules\Recommerce\Entities\SerializationProfile;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\Identity\OpaqueScanToken;

/** Physical observation and evidence; it is not an inventory ledger. */
class StockCountService
{
    private const ACTIVE = ['DRAFT', 'IN_PROGRESS', 'REVIEW', 'AWAITING_APPROVAL'];

    public function __construct(
        protected AuthorizationGate $authorizationGate,
        protected UltimatePosStockCountAdjustmentWriter $adjustmentWriter,
        ?OpaqueScanToken $scanTokens = null,
        ?DeviceIdentityResolver $identityResolver = null
    ) {
        $this->identityResolver = $identityResolver ?: new DeviceIdentityResolver($scanTokens);
    }

    protected DeviceIdentityResolver $identityResolver;

    public function create(User $user, int $locationId, string $type, array $variationIds = [], bool $blind = false): StockCountSession
    {
        $businessId = (int) $user->business_id;
        $this->assertLocation($user, 'recommerce.stockcount.create', $businessId, $locationId);
        if (! in_array($type, ['FULL_BRANCH', 'CYCLE_COUNT'], true)) {
            throw new LogicException('Choose a supported count type.');
        }
        $variationIds = $this->permittedVariations($user, $businessId, $locationId, $variationIds);
        if ($variationIds === []) {
            throw new LogicException('No authorized product variations are available for this count.');
        }
        return DB::transaction(function () use ($user, $businessId, $locationId, $type, $variationIds, $blind) {
            $session = StockCountSession::create([
                'session_uuid' => (string) Str::uuid(), 'business_id' => $businessId, 'location_id' => $locationId,
                'count_type' => $type, 'status' => 'DRAFT', 'scope_json' => ['variation_ids' => $variationIds],
                'blind_count' => $blind, 'created_by' => $user->id,
            ]);
            $this->audit($session, $user->id, 'SESSION_CREATED', ['count_type' => $type, 'variation_count' => count($variationIds), 'blind_count' => $blind]);
            return $session;
        });
    }

    public function start(User $user, int $sessionId): StockCountSession
    {
        return DB::transaction(function () use ($user, $sessionId) {
            $session = StockCountSession::query()->lockForUpdate()->findOrFail($sessionId);
            $this->assertSession($user, 'recommerce.stockcount.count', $session);
            if ($session->status !== 'DRAFT') throw new LogicException('Only a draft count can be started.');
            $this->makeSnapshot($session);
            $snapshot = $session->items()->orderBy('id')->get()->map(fn ($item) => [$item->item_kind, $item->device_id, $item->variation_id, $item->expected_quantity, $item->snapshot_json])->all();
            $session->update(['status' => 'IN_PROGRESS', 'started_by' => $user->id, 'started_at' => now(), 'snapshot_at' => now(), 'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION))]);
            $this->audit($session, $user->id, 'SNAPSHOT_CREATED', ['items' => count($snapshot)]);
            return $session->fresh();
        });
    }

    public function scan(User $user, int $sessionId, string $input): array
    {
        return DB::transaction(function () use ($user, $sessionId, $input) {
            $session = StockCountSession::query()->lockForUpdate()->findOrFail($sessionId);
            $this->assertSession($user, 'recommerce.stockcount.count', $session);
            if ($session->status !== 'IN_PROGRESS') throw new LogicException('This count is not accepting scans.');
            $device = $this->resolveDevice((int) $session->business_id, $input);
            if (! $device) {
                StockCountEntry::create(['session_id' => $session->id, 'entry_type' => 'SCAN', 'result_type' => 'UNKNOWN', 'input_hash' => hash('sha256', trim($input)), 'recorded_by' => $user->id, 'recorded_at' => now()]);
                $this->audit($session, $user->id, 'UNKNOWN_SCAN', []);
                return ['result' => 'UNKNOWN', 'message' => 'This identifier is not a registered Device.'];
            }
            $existing = StockCountEntry::query()->where('session_id', $session->id)->where('device_id', $device->id)->first();
            if ($existing) return ['result' => 'DUPLICATE', 'message' => 'Already counted.', 'device' => $this->deviceCard($device), 'recorded_at' => $existing->recorded_at?->toISOString(), 'recorded_by' => $existing->recorded_by];

            $item = StockCountItem::query()->where('session_id', $session->id)->where('device_id', $device->id)->lockForUpdate()->first();
            $result = 'UNEXPECTED';
            $exception = 'UNEXPECTED_DEVICE';
            if ($item) { $result = 'EXPECTED'; $exception = null; }
            elseif ((int) $device->current_location_id !== (int) $session->location_id) { $result = 'WRONG_LOCATION'; $exception = 'WRONG_LOCATION'; }
            elseif (! in_array($device->stock_participation, ['ON_HAND', 'RESERVED'], true)) { $result = 'WRONG_STATE'; $exception = 'WRONG_STATE'; }

            try {
                StockCountEntry::create(['session_id' => $session->id, 'item_id' => $item?->id, 'device_id' => $device->id, 'entry_type' => 'SCAN', 'result_type' => $result, 'recorded_by' => $user->id, 'recorded_at' => now()]);
            } catch (QueryException $exceptionCaught) {
                $prior = StockCountEntry::query()->where('session_id', $session->id)->where('device_id', $device->id)->first();
                if ($prior) return ['result' => 'DUPLICATE', 'message' => 'Already counted by another staff member.', 'device' => $this->deviceCard($device), 'recorded_at' => $prior->recorded_at?->toISOString(), 'recorded_by' => $prior->recorded_by];
                throw $exceptionCaught;
            }
            if ($item) $item->update(['counted_quantity' => 1, 'counted_at' => now(), 'counted_by' => $user->id]);
            if ($exception) $this->exception($session, $item, $device, $exception, $result === 'WRONG_STATE' ? 'CRITICAL' : 'REVIEW', ['found_location_id' => $session->location_id, 'current_location_id' => $device->current_location_id, 'lifecycle_state' => $device->lifecycle_state, 'stock_participation' => $device->stock_participation]);
            $this->audit($session, $user->id, 'DEVICE_SCANNED', ['device_id' => $device->id, 'result' => $result]);
            return ['result' => $result, 'message' => $this->scanMessage($result), 'device' => $this->deviceCard($device)];
        });
    }

    public function recordQuantity(User $user, int $sessionId, int $itemId, float $quantity): StockCountItem
    {
        return DB::transaction(function () use ($user, $sessionId, $itemId, $quantity) {
            $session = StockCountSession::query()->lockForUpdate()->findOrFail($sessionId);
            $this->assertSession($user, 'recommerce.stockcount.count', $session);
            if ($session->status !== 'IN_PROGRESS' || $quantity < 0) throw new LogicException('This quantity cannot be recorded.');
            $item = StockCountItem::query()->where('session_id', $session->id)->whereKey($itemId)->where('item_kind', 'NON_SERIALIZED_VARIATION')->lockForUpdate()->firstOrFail();
            $item->update(['counted_quantity' => round($quantity, 4), 'counted_at' => now(), 'counted_by' => $user->id]);
            StockCountEntry::create(['session_id' => $session->id, 'item_id' => $item->id, 'entry_type' => 'QUANTITY', 'result_type' => 'RECORDED', 'quantity' => $item->counted_quantity, 'recorded_by' => $user->id, 'recorded_at' => now()]);
            $this->audit($session, $user->id, 'QUANTITY_RECORDED', ['item_id' => $item->id, 'quantity' => $item->counted_quantity]);
            return $item->fresh();
        });
    }

    public function review(User $user, int $sessionId): StockCountSession
    {
        return DB::transaction(function () use ($user, $sessionId) {
            $session = StockCountSession::query()->lockForUpdate()->findOrFail($sessionId);
            $this->assertSession($user, 'recommerce.stockcount.review', $session);
            if ($session->status !== 'IN_PROGRESS') throw new LogicException('Only an active count can move to review.');
            foreach ($session->items()->where('item_kind', 'SERIALIZED_DEVICE')->where('counted_quantity', '<', 1)->get() as $item) $this->exception($session, $item, $item->device, 'MISSING_DEVICE', 'CRITICAL', ['expected_location_id' => $session->location_id, 'snapshot' => $item->snapshot_json]);
            foreach ($session->items()->where('item_kind', 'NON_SERIALIZED_VARIATION')->get() as $item) if (abs((float) $item->expected_quantity - (float) $item->counted_quantity) > 0.000001) $this->exception($session, $item, null, 'AGGREGATE_QUANTITY_VARIANCE', 'REVIEW', ['expected' => $item->expected_quantity, 'counted' => $item->counted_quantity]);
            $this->createAggregateConflicts($session);
            $session->update(['status' => 'REVIEW']);
            $this->audit($session, $user->id, 'COUNT_SUBMITTED_FOR_REVIEW', ['open_exceptions' => $session->exceptions()->where('status', 'OPEN')->count()]);
            return $session->fresh();
        });
    }

    public function resolve(User $user, int $sessionId, int $exceptionId, string $code, string $note): void
    {
        DB::transaction(function () use ($user, $sessionId, $exceptionId, $code, $note) {
            $session = StockCountSession::query()->lockForUpdate()->findOrFail($sessionId);
            $this->assertSession($user, 'recommerce.stockcount.review', $session);
            if (! in_array($session->status, ['REVIEW', 'AWAITING_APPROVAL'], true) || trim($note) === '') throw new LogicException('A review note is required for this exception.');
            $exception = StockCountException::query()->where('session_id', $session->id)->whereKey($exceptionId)->lockForUpdate()->firstOrFail();
            $exception->update(['status' => 'RESOLVED', 'resolution_code' => $code, 'resolution_note' => trim($note), 'resolved_by' => $user->id, 'resolved_at' => now()]);
            $this->audit($session, $user->id, 'EXCEPTION_RESOLVED_NO_DOMAIN_MUTATION', ['exception_id' => $exception->id, 'code' => $code]);
        });
    }

    public function submitForApproval(User $user, int $sessionId): StockCountSession
    {
        return DB::transaction(function () use ($user, $sessionId) {
            $session = StockCountSession::query()->lockForUpdate()->findOrFail($sessionId);
            $this->assertSession($user, 'recommerce.stockcount.review', $session);
            if ($session->status !== 'REVIEW' || $session->exceptions()->where('status', 'OPEN')->exists()) throw new LogicException('Resolve every exception before requesting approval.');
            $session->update(['status' => 'AWAITING_APPROVAL']);
            $this->audit($session, $user->id, 'APPROVAL_REQUESTED', ['requires_approval' => $this->requiresApproval($session)]);
            return $session->fresh();
        });
    }

    public function approve(User $user, int $sessionId): StockCountSession
    {
        return DB::transaction(function () use ($user, $sessionId) {
            $session = StockCountSession::query()->lockForUpdate()->findOrFail($sessionId);
            $this->assertSession($user, 'recommerce.stockcount.approve', $session);
            if ($session->status !== 'AWAITING_APPROVAL' || $session->approved_at || ! $this->requiresApproval($session)) throw new LogicException('This count is not awaiting a required approval.');
            $session->update(['approved_by' => $user->id, 'approved_at' => now()]);
            $this->audit($session, $user->id, 'COUNT_APPROVED', []);
            return $session->fresh();
        });
    }

    public function reconcile(User $user, int $sessionId): StockCountSession
    {
        return DB::transaction(function () use ($user, $sessionId) {
            $session = StockCountSession::query()->lockForUpdate()->findOrFail($sessionId);
            $this->assertSession($user, 'recommerce.stockcount.reconcile', $session);
            if (! in_array($session->status, ['REVIEW', 'AWAITING_APPROVAL'], true) || $session->exceptions()->where('status', 'OPEN')->exists()) throw new LogicException('This count is not ready for reconciliation.');
            if ($this->requiresApproval($session) && ! $session->approved_at) throw new LogicException('A required stock-count approval is still outstanding.');
            if ($this->postSnapshotMovements($session)->isNotEmpty()) throw new LogicException('Post-snapshot movements require a fresh count or an explicit future movement-aware reconciliation.');
            if ($session->items()->where('item_kind', 'SERIALIZED_DEVICE')->where('counted_quantity', '<', 1)->exists()) throw new LogicException('Serialized discrepancies require a valid Device lifecycle or custody workflow; Stock Count will not change Devices directly.');
            foreach ($session->items()->where('item_kind', 'NON_SERIALIZED_VARIATION')->lockForUpdate()->get() as $item) {
                $difference = round((float) $item->counted_quantity - (float) $item->expected_quantity, 4);
                if ($difference > 0) throw new LogicException('A positive variance needs receiving, return, or identity provenance before stock can increase.');
                if ($difference < 0) $this->adjustmentWriter->writeNegativeVariance($session, $item, $user->id, 'Approved stock count '.$session->session_uuid);
                $item->update(['reconciled_quantity' => $item->counted_quantity]);
            }
            $session->update(['status' => 'RECONCILED', 'reconciled_by' => $user->id, 'reconciled_at' => now()]);
            $this->audit($session, $user->id, 'COUNT_RECONCILED', ['native_adjustments_only' => true]);
            return $session->fresh();
        });
    }

    public function close(User $user, int $sessionId): StockCountSession
    {
        return DB::transaction(function () use ($user, $sessionId) {
            $session = StockCountSession::query()->lockForUpdate()->findOrFail($sessionId);
            $this->assertSession($user, 'recommerce.stockcount.close', $session);
            if ($session->status !== 'RECONCILED') throw new LogicException('Only a reconciled count can be closed.');
            $session->update(['status' => 'CLOSED', 'closed_by' => $user->id, 'closed_at' => now()]);
            $this->audit($session, $user->id, 'COUNT_CLOSED', []);
            return $session->fresh();
        });
    }

    public function summary(StockCountSession $session): array
    {
        $items = $session->items()->get();
        $serialized = $items->where('item_kind', 'SERIALIZED_DEVICE');
        $generic = $items->where('item_kind', 'NON_SERIALIZED_VARIATION');
        return ['serialized_expected' => $serialized->count(), 'serialized_counted' => $serialized->where('counted_quantity', '>=', 1)->count(), 'non_serialized_expected' => (float) $generic->sum('expected_quantity'), 'non_serialized_counted' => (float) $generic->sum('counted_quantity'), 'exceptions' => $session->exceptions()->count(), 'open_exceptions' => $session->exceptions()->where('status', 'OPEN')->count(), 'movements_since_snapshot' => $this->postSnapshotMovements($session)->count(), 'three_way' => $this->threeWay($session)];
    }

    public function remaining(StockCountSession $session)
    {
        return $session->items()->where('item_kind', 'SERIALIZED_DEVICE')->where('counted_quantity', '<', 1)->with('device.product')->get();
    }

    public function postSnapshotMovements(StockCountSession $session)
    {
        if (! $session->snapshot_at) return collect();
        return DB::table('recommerce_device_movements as m')->join('recommerce_stock_count_items as i', 'i.device_id', '=', 'm.device_id')
            ->where('i.session_id', $session->id)->where('m.occurred_at', '>', $session->snapshot_at)->orderBy('m.occurred_at')->get();
    }

    private function makeSnapshot(StockCountSession $session): void
    {
        $variationIds = (array) ($session->scope_json['variation_ids'] ?? []);
        $devices = Device::query()->with(['product', 'variation', 'purchaseAssignment'])->where('business_id', $session->business_id)->where('current_location_id', $session->location_id)->whereIn('variation_id', $variationIds)->whereIn('stock_participation', ['ON_HAND', 'RESERVED'])->get();
        foreach ($devices as $device) StockCountItem::create(['session_id' => $session->id, 'item_kind' => 'SERIALIZED_DEVICE', 'device_id' => $device->id, 'product_id' => $device->product_id, 'variation_id' => $device->variation_id, 'expected_quantity' => 1, 'snapshot_json' => ['device_code' => $device->device_code, 'product' => optional($device->product)->name, 'variation' => optional($device->variation)->name, 'lifecycle_state' => $device->lifecycle_state, 'stock_participation' => $device->stock_participation, 'ownership_kind' => $device->ownership_kind, 'custody_kind' => $device->custody_kind, 'expected_location_id' => $session->location_id, 'acquisition_cost' => optional($device->purchaseAssignment)->unit_acquisition_cost]]);
        $serializedIds = SerializationProfile::query()->where('business_id', $session->business_id)->whereIn('variation_id', $variationIds)
            ->when(Schema::hasColumn('recommerce_serialization_profiles', 'inventory_tracking_mode'), fn ($query) => $query->where('inventory_tracking_mode', 'SERIALIZED_DEVICE'))
            ->pluck('variation_id')->all();
        $core = DB::table('variation_location_details as vld')->join('products as p', 'p.id', '=', 'vld.product_id')->leftJoin('variations as v', 'v.id', '=', 'vld.variation_id')->where('p.business_id', $session->business_id)->where('vld.location_id', $session->location_id)->whereIn('vld.variation_id', $variationIds)->when($serializedIds !== [], fn ($q) => $q->whereNotIn('vld.variation_id', $serializedIds))->select('vld.product_id', 'vld.variation_id', 'vld.qty_available', 'p.name as product_name', 'v.name as variation_name')->get();
        foreach ($core as $row) StockCountItem::create(['session_id' => $session->id, 'item_kind' => 'NON_SERIALIZED_VARIATION', 'product_id' => $row->product_id, 'variation_id' => $row->variation_id, 'expected_quantity' => $row->qty_available, 'snapshot_json' => ['product' => $row->product_name, 'variation' => $row->variation_name, 'expected_location_id' => $session->location_id]]);
    }

    private function resolveDevice(int $businessId, string $input): ?Device
    {
        return $this->identityResolver->resolve($businessId, $input);
    }

    private function exception(StockCountSession $session, ?StockCountItem $item, ?Device $device, string $type, string $severity, array $context): void
    {
        StockCountException::query()->updateOrCreate(['session_id' => $session->id, 'device_id' => $device?->id, 'item_id' => $item?->id, 'exception_type' => $type], ['severity' => $severity, 'status' => 'OPEN', 'context_json' => $context]);
    }

    private function createAggregateConflicts(StockCountSession $session): void
    {
        foreach ($this->threeWay($session) as $row) {
            if (abs($row['core_quantity'] - $row['recommerce_expected']) <= 0.000001
                && abs($row['recommerce_expected'] - $row['physical_count']) <= 0.000001) continue;
            $item = StockCountItem::query()->where('session_id', $session->id)
                ->where('item_kind', 'SERIALIZED_DEVICE')->where('variation_id', $row['variation_id'])->first();
            $this->exception($session, $item, null, 'SERIALIZED_AGGREGATE_CONFLICT', 'CRITICAL', $row);
        }
    }

    private function threeWay(StockCountSession $session): array
    {
        return $session->items()->where('item_kind', 'SERIALIZED_DEVICE')->get()->groupBy('variation_id')->map(function ($rows, $variationId) use ($session) {
            $core = DB::table('variation_location_details')->where('location_id', $session->location_id)->where('variation_id', $variationId)->value('qty_available');
            return ['variation_id' => (int) $variationId, 'core_quantity' => (float) ($core ?? 0), 'recommerce_expected' => $rows->count(), 'physical_count' => $rows->where('counted_quantity', '>=', 1)->count()];
        })->values()->all();
    }

    private function requiresApproval(StockCountSession $session): bool
    {
        if ($session->exceptions()->whereIn('exception_type', ['MISSING_DEVICE', 'UNEXPECTED_DEVICE', 'WRONG_LOCATION', 'WRONG_STATE', 'SERIALIZED_AGGREGATE_CONFLICT'])->exists()) return (bool) config('recommerce.stock_count.approval.serialized_requires_approval', true);
        $threshold = config('recommerce.stock_count.approval.generic_cost_threshold');
        return is_numeric($threshold) && (float) $threshold >= 0 && $session->items()->where('item_kind', 'NON_SERIALIZED_VARIATION')->whereRaw('ABS(expected_quantity - counted_quantity) > ?', [0.000001])->exists();
    }

    private function permittedVariations(User $user, int $businessId, int $locationId, array $requested): array
    {
        $configured = array_values(array_filter(array_map('intval', (array) config('recommerce.cohort.variation_ids', []))));
        $wanted = $requested === [] ? $configured : array_values(array_unique(array_map('intval', $requested)));
        return array_values(array_filter($wanted, fn ($variationId) => $variationId > 0 && in_array($variationId, $configured, true) && $this->authorizationGate->allowsWrite($user, 'recommerce.stockcount.create', $businessId, $locationId, $variationId)));
    }

    private function assertLocation(User $user, string $permission, int $businessId, int $locationId): void
    {
        if ((int) $user->business_id !== $businessId || ! User::can_access_this_location($locationId, $businessId) || ! $this->authorizationGate->allowsWriteLocation($user, $permission, $businessId, $locationId)) throw new AuthorizationException('Stock-count scope denied.');
    }

    private function assertSession(User $user, string $permission, StockCountSession $session): void { $this->assertLocation($user, $permission, (int) $session->business_id, (int) $session->location_id); }
    private function audit(StockCountSession $session, int $actorId, string $event, array $metadata): void { StockCountAudit::create(['session_id' => $session->id, 'actor_id' => $actorId, 'event_type' => $event, 'metadata_json' => $metadata, 'occurred_at' => now()]); }
    private function scanMessage(string $result): string { return match ($result) { 'EXPECTED' => 'Counted.', 'WRONG_LOCATION' => 'This Device is expected at another branch.', 'WRONG_STATE' => 'This Device is not currently stock-participating and needs investigation.', default => 'This Device was not in the starting snapshot and needs investigation.' }; }
    private function deviceCard(Device $device): array { return ['device_code' => $device->device_code, 'product' => optional($device->product)->name, 'lifecycle_state' => $device->lifecycle_state, 'current_location_id' => $device->current_location_id]; }
}
