<?php

namespace Database\Seeders;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Modules\Recommerce\Entities\DiagnosticCheck;
use Modules\Recommerce\Entities\DiagnosticTemplate;
use Modules\Recommerce\Entities\DiagnosticTemplateVersion;
use Modules\Recommerce\Services\DiagnosticTemplateService;

/**
 * One published diagnostic template for the disposable SAVERPOS demo estate.
 *
 * Without it the diagnostics screen has nothing to offer: `DiagnosticController`
 * only lists PUBLISHED versions whose template matches the job's type and device
 * category, and the module exposes no route that creates or publishes a
 * template, so an empty demo database leaves the whole diagnostics surface
 * unreachable rather than merely empty.
 *
 * The version is created as a DRAFT and published through the real
 * DiagnosticTemplateService, so the seeded row goes through the same
 * "only a draft can be published" path an operator would.
 *
 * The rubric is fictional demo content, not an approved grading policy. It
 * records a grade vocabulary so the screen has something coherent to show; no
 * business meaning should be read into the letters.
 */
class SaverposDemoDiagnosticFixture
{
    private const TEMPLATE_CODE = 'SB-DT-CUSTOMER-TRIAGE';

    /** Checks a counter technician can answer from the device in front of them. */
    private const CHECKS = [
        [
            'check_key' => 'powers_on', 'label' => 'Powers on and reaches the home screen',
            'outcome_type' => 'ENUM', 'allowed_outcomes_json' => ['PASS', 'FAIL'],
            'is_required' => true, 'sort_order' => 1,
        ],
        [
            'check_key' => 'battery_health', 'label' => 'Reported battery health',
            'outcome_type' => 'NUMERIC', 'allowed_outcomes_json' => ['RECORDED'],
            'unit' => '%', 'minimum_value' => 0, 'maximum_value' => 100,
            'is_required' => true, 'sort_order' => 2,
        ],
        [
            'check_key' => 'display_output', 'label' => 'Display output across the full panel',
            'outcome_type' => 'ENUM', 'allowed_outcomes_json' => ['PASS', 'FAIL', 'NOT_APPLICABLE'],
            'is_required' => true, 'sort_order' => 3,
        ],
        [
            'check_key' => 'charging_port', 'label' => 'Charging port holds a cable and charges',
            'outcome_type' => 'ENUM', 'allowed_outcomes_json' => ['PASS', 'FAIL'],
            'is_required' => true, 'sort_order' => 4,
        ],
        [
            'check_key' => 'liquid_indicator', 'label' => 'Liquid damage indicator',
            'outcome_type' => 'ENUM', 'allowed_outcomes_json' => ['PASS', 'FAIL', 'NOT_APPLICABLE'],
            'is_required' => false, 'sort_order' => 5,
        ],
    ];

    /**
     * Publishes the demo template if this business has none. Returns true when
     * one was created. Re-running leaves an existing template alone, including
     * one an operator has since edited.
     */
    public static function apply(int $businessId, int $userId, ?Command $command = null): bool
    {
        $existing = DiagnosticTemplate::query()
            ->where('business_id', $businessId)
            ->where('template_code', self::TEMPLATE_CODE)
            ->first();
        if ($existing) {
            return false;
        }

        $template = new DiagnosticTemplate([
            'business_id' => $businessId,
            'template_code' => self::TEMPLATE_CODE,
            'name' => 'Customer repair triage',
            // Scoped to customer repairs, open to every device category so one
            // template covers the whole demo queue.
            'job_type' => 'CUSTOMER_REPAIR',
            'category_code' => null,
            'status' => 'ACTIVE',
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        $template->template_uuid = (string) Str::uuid();
        $template->save();

        $version = new DiagnosticTemplateVersion([
            'template_id' => $template->id,
            'business_id' => $businessId,
            'rubric_json' => [
                'note' => 'Fictional demo rubric. Not an approved grading policy.',
                'grades' => [
                    ['code' => 'A', 'label' => 'No faults found'],
                    ['code' => 'B', 'label' => 'Minor faults, device usable'],
                    ['code' => 'C', 'label' => 'Major fault confirmed'],
                ],
            ],
            'created_by' => $userId,
        ]);
        $version->version_number = 1;
        $version->status = 'DRAFT';
        $version->save();

        foreach (self::CHECKS as $check) {
            DiagnosticCheck::create($check + [
                'template_version_id' => $version->id,
                'business_id' => $businessId,
                'evidence_required' => false,
            ]);
        }

        app(DiagnosticTemplateService::class)->publish($version, $userId);

        $command?->info(sprintf('SAVERPOS demo diagnostics: published %s version 1.', self::TEMPLATE_CODE));

        return true;
    }
}
