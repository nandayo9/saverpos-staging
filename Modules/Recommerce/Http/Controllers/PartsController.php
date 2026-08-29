<?php

namespace Modules\Recommerce\Http\Controllers;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Recommerce\Entities\RepairCostEntry;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Entities\RepairPartReservation;
use Modules\Recommerce\Entities\RepairPartUsage;
use Modules\Recommerce\Services\RepairBillingService;
use Modules\Recommerce\Services\RepairPartService;
use Modules\Recommerce\Support\AuthorizationGate;

class PartsController extends Controller
{
    public function show(string $jobCode, AuthorizationGate $authorizationGate)
    {
        try {
            $job = $this->scopedJob($jobCode, $authorizationGate);
        } catch (AuthorizationException $exception) {
            abort(404);
        }

        $reservations = $job->partReservations()
            ->with(['usage.costEntry', 'variation.product'])
            ->latest('id')
            ->get();
        $costEntries = RepairCostEntry::query()
            ->where('business_id', $job->business_id)
            ->where('repair_job_id', $job->getKey())
            ->latest('id')
            ->get();
        $reservedByVariation = RepairPartReservation::query()
            ->where('business_id', $job->business_id)
            ->where('location_id', $job->location_id)
            ->whereIn('status', ['RESERVED', 'ISSUED', 'INSTALLED_PENDING_BILLING'])
            ->whereIn('variation_id', array_values(array_filter(array_map('intval', (array) config('recommerce.cohort.variation_ids', [])))))
            ->where(function (Builder $query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->select('variation_id', DB::raw('SUM(quantity) as quantity'))
            ->groupBy('variation_id')
            ->get()
            ->keyBy('variation_id');

        $stockOptions = DB::table('variation_location_details as stock')
            ->join('variations as variation', 'variation.id', '=', 'stock.variation_id')
            ->join('products as product', 'product.id', '=', 'stock.product_id')
            ->where('stock.location_id', $job->location_id)
            ->where('product.business_id', $job->business_id)
            ->whereIn('stock.variation_id', array_values(array_filter(array_map('intval', (array) config('recommerce.cohort.variation_ids', [])))))
            ->whereNull('variation.deleted_at')
            ->where('stock.qty_available', '>', 0)
            ->orderBy('product.name')
            ->orderBy('variation.sub_sku')
            ->select([
                'stock.product_id',
                'stock.variation_id',
                'stock.qty_available',
                'product.name as product_name',
                'variation.name as variation_name',
                'variation.sub_sku',
            ])
            ->get()
            ->map(function ($option) use ($reservedByVariation) {
                $reserved = (float) optional($reservedByVariation->get($option->variation_id))->quantity;
                $option->reserved_quantity = $reserved;
                $option->available_quantity = max(0, (float) $option->qty_available - $reserved);

                return $option;
            })
            ->filter(fn ($option) => $option->available_quantity > 0)
            ->values();

        $user = auth()->user();
        $businessId = (int) $user->business_id;
        $locationId = (int) $job->location_id;

        $billing = null;
        if ($job->isCustomerRepair()) {
            try {
                $billing = app(RepairBillingService::class)->project($user, $job);
            } catch (LogicException|AuthorizationException $exception) {
                $billing = null;
            }
        }

        return response()->view('recommerce::parts.show', [
            'job' => $job,
            'reservations' => $reservations,
            'costEntries' => $costEntries,
            'stockOptions' => $stockOptions,
            'billing' => $billing,
            'canBill' => $authorizationGate->allowsWriteLocation($user, 'recommerce.repair.billing', $businessId, $locationId),
            'canReserve' => $authorizationGate->allowsWriteLocation($user, 'recommerce.repair.parts.reserve', $businessId, $locationId),
            'canUse' => $authorizationGate->allowsWriteLocation($user, 'recommerce.repair.parts.use', $businessId, $locationId),
            'canResolve' => $authorizationGate->allowsWriteLocation($user, 'recommerce.repair.parts.resolve', $businessId, $locationId),
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function reserve(Request $request, string $jobCode, AuthorizationGate $authorizationGate, RepairPartService $partService)
    {
        try {
            $validated = $request->validate([
                'command_uuid' => ['required', 'uuid'],
                'variation_id' => ['required', 'integer', 'min:1'],
                'quantity' => ['required', 'numeric', 'gt:0'],
            ]);
            $job = $this->scopedJob($jobCode, $authorizationGate);
            $reservation = $partService->reserve(
                auth()->user(),
                $job,
                (int) $validated['variation_id'],
                $validated['command_uuid'],
                (string) $validated['quantity']
            );
        } catch (ValidationException|LogicException $exception) {
            return $this->rejected('Part reservation was rejected.');
        } catch (AuthorizationException $exception) {
            abort(404);
        }

        return response()->json(['status' => 'PART_RESERVED', 'reservation_id' => $reservation->getKey()], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function issue(Request $request, string $jobCode, int $reservationId, AuthorizationGate $authorizationGate, RepairPartService $partService)
    {
        try {
            $validated = $request->validate(['command_uuid' => ['required', 'uuid']]);
            $job = $this->scopedJob($jobCode, $authorizationGate);
            $reservation = RepairPartReservation::query()
                ->where('business_id', $job->business_id)
                ->where('repair_job_id', $job->getKey())
                ->whereKey($reservationId)
                ->firstOrFail();
            $usage = $partService->issue(auth()->user(), $reservation, $validated['command_uuid'], $job->isCustomerRepair() ? 'CUSTOMER' : 'INTERNAL');
        } catch (ValidationException|LogicException $exception) {
            return $this->rejected('Part issue was rejected.');
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            abort(404);
        }

        return response()->json(['status' => 'PART_ISSUED', 'usage_id' => $usage->getKey()], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function install(string $jobCode, int $usageId, AuthorizationGate $authorizationGate, RepairPartService $partService)
    {
        try {
            $job = $this->scopedJob($jobCode, $authorizationGate);
            $usage = RepairPartUsage::query()->where('business_id', $job->business_id)->where('repair_job_id', $job->getKey())->whereKey($usageId)->firstOrFail();
            $updated = $partService->install(auth()->user(), $usage);
        } catch (LogicException $exception) {
            return $this->rejected('Part installation was rejected.');
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            abort(404);
        }

        return response()->json(['status' => 'PART_INSTALLED', 'usage_id' => $updated->getKey()])->header('Cache-Control', 'no-store');
    }

    public function consumeInternal(Request $request, string $jobCode, int $usageId, AuthorizationGate $authorizationGate, RepairPartService $partService)
    {
        try {
            $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);
            $job = $this->scopedJob($jobCode, $authorizationGate);
            $usage = RepairPartUsage::query()->where('business_id', $job->business_id)->where('repair_job_id', $job->getKey())->whereKey($usageId)->firstOrFail();
            $updated = $partService->consumeInternal(auth()->user(), $usage, $validated['reason']);
        } catch (ValidationException|LogicException $exception) {
            return $this->rejected('Internal part consumption was rejected.');
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            abort(404);
        }

        return response()->json(['status' => 'PART_CONSUMED', 'usage_id' => $updated->getKey()])->header('Cache-Control', 'no-store');
    }

    public function resolve(Request $request, string $jobCode, int $usageId, AuthorizationGate $authorizationGate, RepairPartService $partService)
    {
        try {
            $validated = $request->validate([
                'source_transaction_id' => ['required', 'integer', 'min:1'],
                'source_line_id' => ['required', 'integer', 'min:1'],
                'source_type' => ['required', 'in:SALE'],
            ]);
            $job = $this->scopedJob($jobCode, $authorizationGate);
            $usage = RepairPartUsage::query()->where('business_id', $job->business_id)->where('repair_job_id', $job->getKey())->whereKey($usageId)->firstOrFail();
            $updated = $partService->resolve(
                auth()->user(),
                $usage,
                (int) $validated['source_transaction_id'],
                (int) $validated['source_line_id'],
                $validated['source_type']
            );
        } catch (ValidationException|LogicException $exception) {
            return $this->rejected('Customer part billing resolution was rejected.');
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            abort(404);
        }

        return response()->json(['status' => 'PART_RESOLVED', 'usage_id' => $updated->getKey()])->header('Cache-Control', 'no-store');
    }

    public function release(Request $request, string $jobCode, int $reservationId, AuthorizationGate $authorizationGate, RepairPartService $partService)
    {
        try {
            $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);
            $job = $this->scopedJob($jobCode, $authorizationGate);
            $reservation = RepairPartReservation::query()->where('business_id', $job->business_id)->where('repair_job_id', $job->getKey())->whereKey($reservationId)->firstOrFail();
            $updated = $partService->release(auth()->user(), $reservation, $validated['reason']);
        } catch (ValidationException|LogicException $exception) {
            return $this->rejected('Part release was rejected.');
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            abort(404);
        }

        return response()->json(['status' => 'PART_RELEASED', 'reservation_id' => $updated->getKey()])->header('Cache-Control', 'no-store');
    }

    protected function scopedJob(string $jobCode, AuthorizationGate $authorizationGate): RepairJob
    {
        $user = auth()->user();
        $job = RepairJob::query()->with('device')->where('business_id', (int) $user->business_id)->where('job_code', strtoupper(trim($jobCode)))->first();
        $internalScopeAllowed = $job && ! $job->isCustomerRepair()
            ? ($job->device && $job->device->variation_id !== null && $authorizationGate->allowsRead(
                $user,
                'recommerce.repair.view',
                $user->business_id,
                $job->location_id,
                $job->device->variation_id ?? null
            ))
            : true;
        if (! $job || ! $job->device || ! User::can_access_this_location($job->location_id, $user->business_id)
            || ! $authorizationGate->allowsRead($user, 'recommerce.repair.view', $user->business_id, $job->location_id)
            || ! $internalScopeAllowed) {
            throw new AuthorizationException();
        }

        return $job;
    }

    protected function rejected(string $message)
    {
        return response()->json(['message' => $message], 422)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
