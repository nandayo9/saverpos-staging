<?php

namespace Tests\Unit;

use Tests\TestCase;

class RecommerceRepairTransitionFormContractTest extends TestCase
{
    public function test_transition_form_uses_structured_controls_without_raw_json(): void
    {
        $view = file_get_contents(base_path('Modules/Recommerce/Resources/views/repair/show.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('Completion outcome', $view);
        $this->assertStringContainsString('repair-transition-grid', $view);
        $this->assertStringContainsString('repair-transition-form--with-context', $view);
        $this->assertStringContainsString('name="to_state"', $view);
        $this->assertStringContainsString('id="repair-resolution-code"', $view);
        $this->assertStringContainsString('QC passed', $view);
        $this->assertStringContainsString('Approved work is confirmed', $view);
        $this->assertStringContainsString('Repair work is ready for quality control', $view);
        $this->assertStringContainsString('QC rework reason', $view);
        $this->assertStringContainsString('Reason for reopening', $view);
        $this->assertStringContainsString("array_diff(\$allowedTransitions, ['CLOSED'])", $view);
        $this->assertStringContainsString('evidence.resolution_code=resolutionCode.value;', $view);
        $this->assertStringContainsString('evidence.approval_satisfied=true;', $view);
        $this->assertStringContainsString('evidence.work_submitted=true;', $view);
        $this->assertStringContainsString('evidence.qc_failure_reason=qcFailureReason.value.trim();', $view);
        $this->assertStringContainsString('evidence.reopen_reason=reopenReason.value.trim();', $view);
        $this->assertStringContainsString('Confirm that QC passed before moving to Ready.', $view);
        $this->assertStringNotContainsString('repair-evidence', $view);
        $this->assertStringNotContainsString('optional JSON object', $view);
        $this->assertStringNotContainsString('JSON.parse(raw)', $view);
    }
}
