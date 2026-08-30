<?php

namespace Modules\Recommerce\Services;

use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Recommerce\Entities\DiagnosticObservation;
use Modules\Recommerce\Entities\DiagnosticCheck;
use Modules\Recommerce\Entities\DiagnosticTemplate;
use Modules\Recommerce\Entities\DiagnosticSession;
use Modules\Recommerce\Entities\DiagnosticTemplateVersion;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Support\AuthorizationGate;

class DiagnosticTemplateService
{
    public function __construct(protected ?AuthorizationGate $authorizationGate = null)
    {
    }

    /**
     * Create an explicitly branch-scoped draft template and its first draft
     * version. No published or session history is touched.
     *
     * @param array<string, mixed> $attributes
     * @param array<int, array<string, mixed>> $checks
     */
    public function createDraft(User $actor, int $locationId, array $attributes, array $checks): DiagnosticTemplateVersion
    {
        $this->assertManage($actor, $locationId);
        $prepared = $this->normaliseChecks($checks);
        $templateValues = $this->normaliseTemplate($attributes);

        return DB::transaction(function () use ($actor, $locationId, $templateValues, $prepared): DiagnosticTemplateVersion {
            $template = new DiagnosticTemplate($templateValues);
            $template->business_id = (int) $actor->business_id;
            $template->location_id = $locationId;
            $template->template_uuid = (string) Str::uuid();
            $template->status = 'ACTIVE';
            $template->created_by = $actor->getAuthIdentifier();
            $template->updated_by = $actor->getAuthIdentifier();
            $template->save();

            return $this->writeDraftVersion($template, $actor, $prepared, $attributes['rubric'] ?? null);
        });
    }

    /** @param array<string, mixed> $attributes
     *  @param array<int, array<string, mixed>> $checks */
    public function updateDraft(User $actor, DiagnosticTemplateVersion $version, array $attributes, array $checks): DiagnosticTemplateVersion
    {
        $this->assertManage($actor, (int) ($version->template?->location_id ?? 0));
        $prepared = $this->normaliseChecks($checks);
        $templateValues = $this->normaliseTemplate($attributes);

        return DB::transaction(function () use ($actor, $version, $templateValues, $prepared, $attributes): DiagnosticTemplateVersion {
            $locked = DiagnosticTemplateVersion::query()->with('template')->whereKey($version->getKey())->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'DRAFT' || (int) $locked->business_id !== (int) $actor->business_id) {
                throw new LogicException('Only an editable draft in this business can be changed.');
            }
            $template = $locked->template;
            if (! $template || (int) $template->business_id !== (int) $actor->business_id) {
                throw new LogicException('Diagnostic template is not available for this business.');
            }
            $template->fill($templateValues);
            $template->updated_by = $actor->getAuthIdentifier();
            $template->save();
            $locked->rubric_json = $attributes['rubric'] ?? null;
            $locked->save();
            $locked->checks()->delete();
            foreach ($prepared as $check) {
                $locked->checks()->create(array_merge($check, ['business_id' => $actor->business_id]));
            }

            return $locked->fresh(['template', 'checks']);
        });
    }

    public function createRevision(User $actor, DiagnosticTemplate $template): DiagnosticTemplateVersion
    {
        $this->assertManage($actor, (int) $template->location_id);
        return DB::transaction(function () use ($actor, $template): DiagnosticTemplateVersion {
            $lockedTemplate = DiagnosticTemplate::query()->whereKey($template->getKey())->lockForUpdate()->first();
            if (! $lockedTemplate || (int) $lockedTemplate->business_id !== (int) $actor->business_id) {
                throw new LogicException('Diagnostic template is not available for this business.');
            }
            $existing = $lockedTemplate->versions()->where('status', 'DRAFT')->latest('id')->first();
            if ($existing) {
                return $existing->fresh(['template', 'checks']);
            }
            $source = $lockedTemplate->versions()->where('status', 'PUBLISHED')->latest('version_number')->with('checks')->first();
            if (! $source) {
                throw new LogicException('Publish the first draft before creating a revision.');
            }
            $version = new DiagnosticTemplateVersion([
                'business_id' => $actor->business_id,
                'rubric_json' => $source->rubric_json,
                'created_by' => $actor->getAuthIdentifier(),
            ]);
            $version->template_id = $lockedTemplate->getKey();
            $version->version_number = (int) $lockedTemplate->versions()->max('version_number') + 1;
            $version->status = 'DRAFT';
            $version->save();
            foreach ($source->checks as $check) {
                $version->checks()->create(array_merge($check->only([
                    'business_id', 'check_key', 'label', 'outcome_type', 'unit',
                    'minimum_value', 'maximum_value', 'allowed_outcomes_json',
                    'is_required', 'evidence_required', 'sort_order',
                ]), ['business_id' => $actor->business_id]));
            }

            return $version->fresh(['template', 'checks']);
        });
    }

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

            if ($template->location_id !== null && (int) $template->location_id !== (int) $job->location_id) {
                throw new LogicException('Diagnostic template is not valid for this branch.');
            }

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

    /** @param array<string, mixed> $attributes
     *  @return array<string, mixed> */
    protected function normaliseTemplate(array $attributes, bool $requireCode = true): array
    {
        $code = strtoupper(trim((string) ($attributes['template_code'] ?? '')));
        if ($requireCode && ! preg_match('/^[A-Z0-9][A-Z0-9_-]{1,63}$/', $code)) {
            throw new LogicException('Template code must use 2-64 letters, numbers, hyphens, or underscores.');
        }
        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) {
            throw new LogicException('Diagnostic template name is required.');
        }

        return [
            'template_code' => $code,
            'name' => mb_substr($name, 0, 160),
            'category_code' => ($attributes['category_code'] ?? null) !== null ? mb_substr(trim((string) $attributes['category_code']), 0, 32) : null,
            'job_type' => ($attributes['job_type'] ?? null) !== null ? mb_substr(strtoupper(trim((string) $attributes['job_type'])), 0, 32) : null,
        ];
    }

    /** @param array<int, array<string, mixed>> $checks
     *  @return array<int, array<string, mixed>> */
    protected function normaliseChecks(array $checks): array
    {
        if ($checks === []) {
            throw new LogicException('A diagnostic template requires at least one check.');
        }
        $normalised = [];
        $keys = [];
        foreach (array_values($checks) as $index => $check) {
            $key = strtolower(trim((string) ($check['check_key'] ?? '')));
            $label = trim((string) ($check['label'] ?? ''));
            $type = strtoupper(trim((string) ($check['outcome_type'] ?? 'STATUS')));
            if (! preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $key) || isset($keys[$key])) {
                throw new LogicException('Diagnostic check keys must be unique and use safe characters.');
            }
            if ($label === '' || ! in_array($type, ['STATUS', 'TEXT', 'NUMERIC'], true)) {
                throw new LogicException('Each diagnostic check needs a label and supported check type.');
            }
            $minimum = ($check['minimum_value'] ?? null) === '' || ($check['minimum_value'] ?? null) === null ? null : (float) $check['minimum_value'];
            $maximum = ($check['maximum_value'] ?? null) === '' || ($check['maximum_value'] ?? null) === null ? null : (float) $check['maximum_value'];
            if ($type !== 'NUMERIC') {
                $minimum = $maximum = null;
            }
            if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
                throw new LogicException('A diagnostic minimum cannot exceed its maximum.');
            }
            $allowed = array_values(array_filter(array_map('trim', (array) ($check['allowed_outcomes'] ?? ['PASS', 'FAIL', 'NOT_APPLICABLE']))));
            $keys[$key] = true;
            $normalised[] = [
                'check_key' => $key,
                'label' => mb_substr($label, 0, 160),
                'outcome_type' => $type,
                'unit' => $type === 'NUMERIC' && ($check['unit'] ?? '') !== '' ? mb_substr(trim((string) $check['unit']), 0, 24) : null,
                'minimum_value' => $minimum,
                'maximum_value' => $maximum,
                'allowed_outcomes_json' => $allowed,
                'is_required' => filter_var($check['is_required'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'evidence_required' => filter_var($check['evidence_required'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'sort_order' => $index,
            ];
        }

        return $normalised;
    }

    /** @param array<int, array<string, mixed>> $checks */
    protected function writeDraftVersion(DiagnosticTemplate $template, User $actor, array $checks, mixed $rubric): DiagnosticTemplateVersion
    {
        $version = new DiagnosticTemplateVersion([
            'business_id' => $template->business_id,
            'rubric_json' => is_array($rubric) ? $rubric : null,
            'created_by' => $actor->getAuthIdentifier(),
        ]);
        $version->template_id = $template->getKey();
        $version->version_number = 1;
        $version->status = 'DRAFT';
        $version->save();
        foreach ($checks as $check) {
            $version->checks()->create(array_merge($check, ['business_id' => $template->business_id]));
        }

        return $version->fresh(['template', 'checks']);
    }

    protected function assertManage(User $actor, int $locationId): void
    {
        if ($locationId < 1 || $this->authorizationGate === null
            || ! User::can_access_this_location($locationId, $actor->business_id)
            || ! $this->authorizationGate->allowsWriteLocation(
                $actor,
                'recommerce.diagnostic.manage',
                $actor->business_id,
                $locationId
            )) {
            throw new \Illuminate\Auth\Access\AuthorizationException();
        }
    }
}
