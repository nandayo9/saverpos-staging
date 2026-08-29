<?php

namespace Modules\Recommerce\Http\Controllers;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Services\RepairBillingService;
use Modules\Recommerce\Support\AuthorizationGate;

/**
 * Customer-repair billing endpoints. The service owns every billing rule;
 * this controller only validates transport payloads and returns scoped JSON.
 */
class RepairBillingController extends Controller
{
    public function project(string $jobCode, AuthorizationGate $authorizationGate, RepairBillingService $billingService)
    {
        try {
            $job = $this->scopedJob($jobCode, $authorizationGate);
            $projection = $billingService->project(auth()->user(), $job);
        } catch (AuthorizationException $exception) {
            abort(404);
        }

        return response()->json(['status' => 'REPAIR_BILLING_PROJECTED'] + $projection, 200)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function link(Request $request, string $jobCode, AuthorizationGate $authorizationGate, RepairBillingService $billingService)
    {
        try {
            $validated = $request->validate([
                'command_uuid' => ['required', 'uuid'],
                'sale_transaction_id' => ['required', 'integer', 'min:1'],
            ]);
            $job = $this->scopedJob($jobCode, $authorizationGate);
            $job = $billingService->linkSale(
                auth()->user(),
                $job,
                (string) $validated['command_uuid'],
                (int) $validated['sale_transaction_id']
            );
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (LogicException $exception) {
            return $this->rejected('A finalized POS sale could not be linked to this Repair job.');
        }

        return response()->json([
            'status' => 'REPAIR_BILLED',
            'job_code' => $job->job_code,
            'sale_transaction_id' => (int) $job->source_id,
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function release(Request $request, string $jobCode, AuthorizationGate $authorizationGate, RepairBillingService $billingService)
    {
        try {
            $validated = $request->validate([
                'sale_transaction_id' => ['required', 'integer', 'min:1'],
                'reason' => ['required', 'string', 'max:255'],
            ]);
            $job = $this->scopedJob($jobCode, $authorizationGate);
            $released = $billingService->releaseSale(
                auth()->user(),
                $job,
                (int) $validated['sale_transaction_id'],
                (string) $validated['reason']
            );
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (LogicException $exception) {
            return $this->rejected('Billed state reversal was rejected.');
        }

        return response()->json([
            'status' => 'BILLING_RELEASED',
            'released_parts' => $released,
        ])->header('Cache-Control', 'no-store');
    }

    protected function scopedJob(string $jobCode, AuthorizationGate $authorizationGate): RepairJob
    {
        $user = auth()->user();
        $job = RepairJob::query()
            ->with('device')
            ->where('business_id', (int) $user->business_id)
            ->where('job_code', strtoupper(trim($jobCode)))
            ->first();

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
        return response()->json(['message' => $message], 422)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
