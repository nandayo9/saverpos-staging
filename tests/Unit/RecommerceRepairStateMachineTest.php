<?php

namespace Tests\Unit;

use LogicException;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Support\RepairJobStateMachine;
use Tests\TestCase;

class RecommerceRepairStateMachineTest extends TestCase
{
    public function test_customer_job_requires_a_contact_and_starts_received()
    {
        $job = new RepairJob([
            'job_type' => RepairJobStateMachine::TYPE_CUSTOMER_REPAIR,
        ]);
        $job->state = RepairJobStateMachine::STATE_RECEIVED;

        $this->expectException(LogicException::class);
        RepairJobStateMachine::assertNewJob($job);
    }

    public function test_direct_close_is_forbidden()
    {
        $job = $this->job(RepairJobStateMachine::STATE_RECEIVED);

        $this->expectException(LogicException::class);
        RepairJobStateMachine::assertTransition($job, RepairJobStateMachine::STATE_CLOSED);
    }

    public function test_work_cannot_start_from_approval_without_approval_evidence()
    {
        $job = $this->job(RepairJobStateMachine::STATE_AWAITING_APPROVAL);

        $this->expectExceptionMessage('approval policy');
        RepairJobStateMachine::assertTransition($job, RepairJobStateMachine::STATE_IN_REPAIR);
    }

    public function test_qc_requires_pass_or_authorized_waiver()
    {
        $job = $this->job(RepairJobStateMachine::STATE_QC);

        $this->expectExceptionMessage('QC must pass');
        RepairJobStateMachine::assertTransition($job, RepairJobStateMachine::STATE_READY, [
            'resolution_code' => RepairJobStateMachine::RESOLUTION_COMPLETED,
        ]);
    }

    public function test_ready_requires_a_supported_resolution_code()
    {
        $job = $this->job(RepairJobStateMachine::STATE_DIAGNOSIS);

        $this->expectExceptionMessage('supported resolution code');
        RepairJobStateMachine::assertTransition($job, RepairJobStateMachine::STATE_READY, [
            'resolution_code' => 'UNKNOWN',
        ]);
    }

    public function test_qc_pass_and_all_close_prerequisites_are_accepted()
    {
        $job = $this->job(RepairJobStateMachine::STATE_QC);

        RepairJobStateMachine::assertTransition($job, RepairJobStateMachine::STATE_READY, [
            'resolution_code' => RepairJobStateMachine::RESOLUTION_COMPLETED,
            'qc_passed' => true,
        ]);

        $job->state = RepairJobStateMachine::STATE_READY;

        RepairJobStateMachine::assertTransition($job, RepairJobStateMachine::STATE_CLOSED, [
            'qc_satisfied' => true,
            'parts_resolved' => true,
            'financial_policy_satisfied' => true,
            'custody_resolved' => true,
        ]);

        $this->assertTrue(true);
    }

    private function job(string $state): RepairJob
    {
        $job = new RepairJob([
            'job_type' => RepairJobStateMachine::TYPE_INTERNAL_REFURBISHMENT,
            'contact_id' => null,
        ]);

        $job->state = $state;

        return $job;
    }
}
