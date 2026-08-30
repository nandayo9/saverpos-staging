<?php

namespace Modules\Recommerce\Http\Controllers;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Services\WarrantyClaimService;
use Modules\Recommerce\Support\AuthorizationGate;

class WarrantyClaimController extends Controller
{
    public function store(Request $request, string $jobCode, AuthorizationGate $authorizationGate, WarrantyClaimService $warrantyService)
    {
        try {
            $validated = $request->validate([
                'command_uuid' => ['required', 'uuid'],
                'claimed_on' => ['required', 'date'],
                'covered_amount' => ['nullable', 'numeric', 'min:0'],
            ]);
            $job = $this->scopedJob($jobCode, $authorizationGate);
            $claim = $warrantyService->createClaim(
                auth()->user(),
                $job,
                (string) $validated['command_uuid'],
                $validated
            );
        } catch (ValidationException|LogicException $exception) {
            return $this->rejected('A warranty claim could not be created from this job.');
        } catch (AuthorizationException $exception) {
            abort(404);
        }

        return response()->json([
            'status' => 'WARRANTY_CLAIM_CREATED',
            'claim_number' => $claim->claim_number,
            'coverage_status' => $claim->coverage_status,
            'decision_reason' => $claim->decision_reason,
            'repair_job_id' => $claim->repair_job_id,
            'lines' => $claim->lines,
        ], 201)->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    protected function scopedJob(string $jobCode, AuthorizationGate $authorizationGate)
    {
        $user = auth()->user();
        $job = RepairJob::query()
            ->with('device')
            ->where('business_id', (int) $user->business_id)
            ->where('job_code', strtoupper(trim($jobCode)))
            ->first();

        if (
            ! $job ||
            ! $job->device ||
            ! User::can_access_this_location($job->location_id, $user->business_id) ||
            ! $authorizationGate->allowsWriteLocation(
                $user,
                WarrantyClaimService::PERMISSION_MANAGE,
                $user->business_id,
                $job->location_id
            )
        ) {
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
