<?php

namespace Modules\Recommerce\Http\Controllers;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Services\RepairCollectionService;
use Modules\Recommerce\Support\AuthorizationGate;

class RepairCollectionController extends Controller
{
    public function summary(string $jobCode, AuthorizationGate $authorizationGate, RepairCollectionService $collectionService)
    {
        try {
            $job = $this->scopedJob($jobCode, $authorizationGate);
            $summary = $collectionService->summary(auth()->user(), $job);
        } catch (AuthorizationException $exception) {
            abort(404);
        }

        return response()->json(['status' => 'REPAIR_COLLECTION_SUMMARY'] + $summary)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function collect(Request $request, string $jobCode, AuthorizationGate $authorizationGate, RepairCollectionService $collectionService)
    {
        try {
            $validated = $request->validate([
                'collector_name' => ['required', 'string', 'max:160'],
                'collector_phone' => ['nullable', 'string', 'max:60'],
                'override_reason' => ['nullable', 'string', 'max:255'],
            ]);
            $job = $this->scopedJob($jobCode, $authorizationGate);
            $closed = $collectionService->collect(
                auth()->user(),
                $job,
                $validated,
                $validated['override_reason'] ?? null
            );
        } catch (ValidationException|LogicException $exception) {
            return $this->rejected('Collection was rejected by the collection policy.');
        } catch (AuthorizationException $exception) {
            abort(404);
        }

        return response()->json([
            'status' => 'REPAIR_COLLECTED',
            'job_code' => $closed->job_code,
            'state' => $closed->state,
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function repeat(Request $request, string $jobCode, AuthorizationGate $authorizationGate, RepairCollectionService $collectionService)
    {
        try {
            $validated = $request->validate(['command_uuid' => ['required', 'uuid']]);
            $job = $this->scopedJob($jobCode, $authorizationGate);
            $repeat = $collectionService->startRepeat(auth()->user(), $job, (string) $validated['command_uuid']);
        } catch (ValidationException|LogicException $exception) {
            return $this->rejected('A repeat visit could not be created from this job.');
        } catch (AuthorizationException $exception) {
            abort(404);
        }

        return response()->json([
            'status' => 'REPEAT_REPAIR_CREATED',
            'job_code' => $repeat->job_code,
            'parent_repair_job_id' => (int) $repeat->parent_repair_job_id,
            'state' => $repeat->state,
        ], 201)->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    protected function scopedJob(string $jobCode, AuthorizationGate $authorizationGate): RepairJob
    {
        $user = auth()->user();
        $job = RepairJob::query()
            ->with('device')
            ->where('business_id', (int) $user->business_id)
            ->where('job_code', strtoupper(trim($jobCode)))
            ->first();

        if (! $job || ! $job->device || ! User::can_access_this_location($job->location_id, $user->business_id)
            || ! $authorizationGate->allowsRead($user, 'recommerce.repair.view', $user->business_id, $job->location_id)) {
            throw new AuthorizationException();
        }

        return $job;
    }

    protected function rejected(string $message)
    {
        return response()->json(['message' => $message], 422)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }}
