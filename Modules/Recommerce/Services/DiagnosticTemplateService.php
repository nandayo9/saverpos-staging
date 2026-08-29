<?php

namespace Modules\Recommerce\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Recommerce\Entities\DiagnosticObservation;
use Modules\Recommerce\Entities\DiagnosticSession;
use Modules\Recommerce\Entities\DiagnosticTemplateVersion;
use Modules\Recommerce\Entities\RepairJob;

class DiagnosticTemplateService
{
    public function publish(DiagnosticTemplateVersion $version, int $actorId): DiagnosticTemplateVersion
    {
        return DB::transaction(function () use ($version, $actorId): DiagnosticTemplateVersion {
            $locked = DiagnosticTemplateVersion::query()->whereKey($version->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->status !== 'DRAFT') {
                throw new LogicException('Only a draft diagnostic version can be published.');
            }

            $locked->template()->firstOrFail()->versions()
                ->where('id', '<>', $locked->getKey())
                ->where('status', 'PUBLISHED')
                ->update(['status' => 'RETIRED', 'retired_at' => now()]);

            $locked->status = 'PUBLISHED';
            $locked->published_at = now();
            $locked->published_by = $actorId;
            $locked->save();

            return $locked->fresh(['template', 'checks']);
        });
    }

    public function retire(DiagnosticTemplateVersion $version): DiagnosticTemplateVersion
    {
        return DB::transaction(function () use ($version): DiagnosticTemplateVersion {
            $locked = DiagnosticTemplateVersion::query()->whereKey($version->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->status !== 'PUBLISHED') {
                throw new LogicException('Only a published diagnostic version can be retired.');
            }

            $locked->status = 'RETIRED';
            $locked->retired_at = now();
            $locked->save();

            return $locked;
        });
    }

    public function startSession(RepairJob $job, DiagnosticTemplateVersion $version, int $actorId): DiagnosticSession
    {
        return DB::transaction(function () use ($job, $version, $actorId): DiagnosticSession {
            $version = DiagnosticTemplateVersion::query()
                ->with(['template', 'checks'])
                ->whereKey($version->getKey())
                ->lockForUpdate()
                ->first();

            if (! $version || ! $version->isPublished()) {
                throw new LogicException('Diagnostic sessions require a published template version.');
            }

            if ((int) $version->business_id !== (int) $job->business_id) {
                throw new LogicException('Diagnostic template and Repair job business do not match.');
            }

            $template = $version->template;
            $device = $job->device;

            if ($template->job_type !== null && $template->job_type !== $job->job_type) {
                throw new LogicException('Diagnostic template is not valid for this Repair job type.');
            }

            if ($template->category_code !== null && $template->category_code !== $device->category_code) {
                throw new LogicException('Diagnostic template is not valid for this Device category.');
            }

            $session = new DiagnosticSession([
                'business_id' => $job->business_id,
                'location_id' => $job->location_id,
                'repair_job_id' => $job->getKey(),
                'template_version_id' => $version->getKey(),
                'started_by' => $actorId,
            ]);
            $session->session_uuid = (string) Str::uuid();
            $session->status = 'DRAFT';
            $session->template_snapshot_json = $version->snapshot();
            $session->save();

            return $session;
        });
    }

    public function submitSession(DiagnosticSession $session, array $observations, string $gradeCode, ?string $overrideReason, int $actorId): DiagnosticSession
    {
        return DB::transaction(function () use ($session, $observations, $gradeCode, $overrideReason, $actorId): DiagnosticSession {
            $locked = DiagnosticSession::query()
                ->with(['templateVersion.checks'])
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status !== 'DRAFT') {
                throw new LogicException('Only a draft diagnostic session can be submitted.');
            }

            if (trim($gradeCode) === '') {
                throw new LogicException('A submitted diagnostic requires a grade.');
            }

            $byKey = [];
            foreach ($observations as $observation) {
                $key = trim((string) ($observation['check_key'] ?? ''));
                if ($key === '' || isset($byKey[$key])) {
                    throw new LogicException('Diagnostic observations must have unique check keys.');
                }
                $byKey[$key] = $observation;
            }

            $checksByKey = $locked->templateVersion->checks->keyBy('check_key');
            foreach (array_keys($byKey) as $key) {
                if (! $checksByKey->has($key)) {
                    throw new LogicException('Diagnostic observation references an unknown check.');
                }
            }

            foreach ($locked->templateVersion->checks as $check) {
                if ($check->is_required && ! isset($byKey[$check->check_key])) {
                    throw new LogicException('All required diagnostic checks must be completed.');
                }

                if (! isset($byKey[$check->check_key])) {
                    continue;
                }

                $observation = $byKey[$check->check_key];
                $outcome = (string) ($observation['outcome'] ?? '');
                $allowed = $check->allowed_outcomes_json ?? [];
                if ($outcome === '' || ($allowed !== [] && ! in_array($outcome, $allowed, true))) {
                    throw new LogicException('Diagnostic observation has an invalid outcome.');
                }

                if ($check->evidence_required && empty($observation['evidence'])) {
                    throw new LogicException('Required diagnostic evidence is missing.');
                }

                if ($check->outcome_type === 'NUMERIC') {
                    $numericValue = $observation['value_numeric'] ?? null;
                    if (! is_numeric($numericValue)) {
                        throw new LogicException('Numeric diagnostic checks require a numeric value.');
                    }
                    if ($check->minimum_value !== null && (float) $numericValue < (float) $check->minimum_value) {
                        throw new LogicException('Diagnostic value is below the allowed minimum.');
                    }
                    if ($check->maximum_value !== null && (float) $numericValue > (float) $check->maximum_value) {
                        throw new LogicException('Diagnostic value is above the allowed maximum.');
                    }
                }

                DiagnosticObservation::create([
                    'business_id' => $locked->business_id,
                    'session_id' => $locked->getKey(),
                    'diagnostic_check_id' => $check->getKey(),
                    'check_key' => $check->check_key,
                    'outcome' => $outcome,
                    'value_numeric' => $observation['value_numeric'] ?? null,
                    'value_text' => $observation['value_text'] ?? null,
                    'notes' => $observation['notes'] ?? null,
                    'evidence_json' => $observation['evidence'] ?? null,
                    'observed_by' => $actorId,
                    'observed_at' => now(),
                ]);
            }

            if ($overrideReason !== null && trim($overrideReason) === '') {
                throw new LogicException('A grade override reason cannot be blank.');
            }

            $locked->status = 'SUBMITTED';
            $locked->grade_code = $gradeCode;
            $locked->grade_override_reason = $overrideReason;
            $locked->submitted_by = $actorId;
            $locked->submitted_at = now();
            $locked->save();

            return $locked->fresh(['observations']);
        });
    }
}
