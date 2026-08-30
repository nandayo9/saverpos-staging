<?php

namespace Modules\Recommerce\Http\Controllers;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Recommerce\Entities\DiagnosticSession;
use Modules\Recommerce\Entities\DiagnosticTemplateVersion;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Services\DiagnosticTemplateService;
use Modules\Recommerce\Support\AuthorizationGate;

class DiagnosticController extends Controller
{
    public function show(string $jobCode, AuthorizationGate $authorizationGate)
    {
        try {
            $job = $this->scopedJob($jobCode, $authorizationGate, false);
        } catch (AuthorizationException $exception) {
            abort(404);
        }
        $user = auth()->user();
        $businessId = (int) $user->business_id;
        $variationId = $job->device->variation_id !== null ? (int) $job->device->variation_id : null;
        $canSubmit = $job->isCustomerRepair()
            ? $authorizationGate->allowsWriteLocation(
                $user,
                'recommerce.diagnostic.submit',
                $businessId,
                (int) $job->location_id
            )
            : ($variationId !== null && $authorizationGate->allowsWrite(
                $user,
                'recommerce.diagnostic.submit',
                $businessId,
                (int) $job->location_id,
                $variationId
            ));

        $templates = DiagnosticTemplateVersion::query()
            ->with(['template', 'checks'])
            ->where('business_id', $businessId)
            ->where('status', 'PUBLISHED')
            ->whereHas('template', function ($query) use ($job): void {
                $query->whereNull('location_id')->orWhere('location_id', $job->location_id);
            })
            ->get()
            ->filter(function (DiagnosticTemplateVersion $version) use ($job): bool {
                $template = $version->template;

                return $template
                    && ($template->job_type === null || $template->job_type === $job->job_type)
                    && ($template->category_code === null || $template->category_code === $job->device->category_code);
            })
            ->sortByDesc('id')
            ->values();

        $diagnosticSession = $job->diagnosticSessions()
            ->with(['templateVersion.template', 'templateVersion.checks', 'observations'])
            ->latest('id')
            ->first();

        return response()->view('recommerce::diagnostics.show', [
            'job' => $job,
            'templates' => $templates,
            'diagnosticSession' => $diagnosticSession,
            'canSubmit' => $canSubmit,
            'variationId' => $variationId,
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function start(
        Request $request,
        string $jobCode,
        AuthorizationGate $authorizationGate,
        DiagnosticTemplateService $diagnosticTemplateService
    ) {
        try {
            $validated = $request->validate([
                'template_version_id' => ['required', 'integer', 'min:1'],
            ]);
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'Diagnostic session start was rejected.'], 422)
                ->header('Cache-Control', 'no-store');
        }

        try {
            $job = $this->scopedJob($jobCode, $authorizationGate, true);
            $version = DiagnosticTemplateVersion::query()
                ->with(['template', 'checks'])
                ->where('business_id', $job->business_id)
                ->whereKey($validated['template_version_id'])
                ->first();

            if (! $version) {
                throw new LogicException('Diagnostic template was not found.');
            }

            $session = $diagnosticTemplateService->startSession(
                $job,
                $version,
                (int) auth()->user()->getAuthIdentifier()
            );
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (LogicException $exception) {
            return response()->json(['message' => 'Diagnostic session start was rejected.'], 422)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'status' => 'DIAGNOSTIC_SESSION_STARTED',
            'session_id' => $session->getKey(),
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function submit(
        Request $request,
        string $jobCode,
        int $sessionId,
        AuthorizationGate $authorizationGate,
        DiagnosticTemplateService $diagnosticTemplateService
    ) {
        try {
            $validated = $request->validate([
                'grade_code' => ['required', 'string', 'max:40'],
                'override_reason' => ['nullable', 'string', 'max:255'],
                'observations' => ['required', 'array', 'min:1'],
                'observations.*.check_key' => ['required', 'string', 'max:64'],
                'observations.*.outcome' => ['required', 'string', 'max:24'],
                'observations.*.value_numeric' => ['nullable', 'numeric'],
                'observations.*.value_text' => ['nullable', 'string'],
                'observations.*.notes' => ['nullable', 'string'],
                'observations.*.evidence' => ['nullable'],
            ]);
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'Diagnostic submission was rejected.'], 422)
                ->header('Cache-Control', 'no-store');
        }

        try {
            $job = $this->scopedJob($jobCode, $authorizationGate, true);
            $session = DiagnosticSession::query()
                ->whereKey($sessionId)
                ->where('business_id', $job->business_id)
                ->where('location_id', $job->location_id)
                ->where('repair_job_id', $job->getKey())
                ->first();

            if (! $session) {
                throw new LogicException('Diagnostic session was not found.');
            }

            $updated = $diagnosticTemplateService->submitSession(
                $session,
                $validated['observations'],
                $validated['grade_code'],
                $validated['override_reason'] ?? null,
                (int) auth()->user()->getAuthIdentifier()
            );
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (LogicException $exception) {
            return response()->json(['message' => 'Diagnostic submission was rejected.'], 422)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'status' => 'DIAGNOSTIC_SUBMITTED',
            'session_id' => $updated->getKey(),
            'grade_code' => $updated->grade_code,
        ])->header('Cache-Control', 'no-store');
    }

    protected function scopedJob(string $jobCode, AuthorizationGate $authorizationGate, bool $write): RepairJob
    {
        $user = auth()->user();
        $job = RepairJob::query()
            ->with('device')
            ->where('business_id', (int) $user->business_id)
            ->where('job_code', strtoupper(trim($jobCode)))
            ->first();

        $variationId = $job && $job->device ? $job->device->variation_id : null;
        $scopeAllowed = $job && $job->isCustomerRepair()
            ? ($write
                ? $authorizationGate->allowsWriteLocation($user, 'recommerce.diagnostic.submit', $user->business_id, $job->location_id)
                : $authorizationGate->allowsRead($user, 'recommerce.diagnostic.view', $user->business_id, $job->location_id))
            : ($variationId !== null && ($write
                ? $authorizationGate->allowsWrite($user, 'recommerce.diagnostic.submit', $user->business_id, $job->location_id, $variationId)
                : $authorizationGate->allowsRead($user, 'recommerce.diagnostic.view', $user->business_id, $job->location_id, $variationId)));

        if (! $job
            || ! $job->device
            || ! User::can_access_this_location($job->location_id, $user->business_id)
            || ! $scopeAllowed) {
            throw new AuthorizationException();
        }

        return $job;
    }
}
