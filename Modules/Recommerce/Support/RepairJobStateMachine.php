<?php

namespace Modules\Recommerce\Support;

use LogicException;
use Modules\Recommerce\Entities\RepairJob;

/**
 * Shared owned Repair state contract for internal and customer jobs.
 *
 * Controllers must call the transition service; state is not a free-edit
 * field. Quote, parts, QC, custody, and financial prerequisites are supplied
 * as evidence by the later bounded services.
 */
class RepairJobStateMachine
{
    public const TYPE_INTERNAL_REFURBISHMENT = 'INTERNAL_REFURBISHMENT';
    public const TYPE_CUSTOMER_REPAIR = 'CUSTOMER_REPAIR';

    public const STATE_RECEIVED = 'RECEIVED';
    public const STATE_DIAGNOSIS = 'DIAGNOSIS';
    public const STATE_AWAITING_APPROVAL = 'AWAITING_APPROVAL';
    public const STATE_WAITING_PARTS = 'WAITING_PARTS';
    public const STATE_IN_REPAIR = 'IN_REPAIR';
    public const STATE_QC = 'QC';
    public const STATE_READY = 'READY';
    public const STATE_CLOSED = 'CLOSED';

    public const RESOLUTION_COMPLETED = 'COMPLETED';
    public const RESOLUTION_CANCELLED = 'CANCELLED';
    public const RESOLUTION_DECLINED = 'DECLINED';
    public const RESOLUTION_UNREPAIRABLE = 'UNREPAIRABLE';

    private const TRANSITIONS = [
        self::STATE_RECEIVED => [self::STATE_DIAGNOSIS, self::STATE_READY],
        self::STATE_DIAGNOSIS => [
            self::STATE_AWAITING_APPROVAL,
            self::STATE_WAITING_PARTS,
            self::STATE_IN_REPAIR,
            self::STATE_READY,
        ],
        self::STATE_AWAITING_APPROVAL => [
            self::STATE_WAITING_PARTS,
            self::STATE_IN_REPAIR,
            self::STATE_READY,
        ],
        self::STATE_WAITING_PARTS => [
            self::STATE_IN_REPAIR,
            self::STATE_AWAITING_APPROVAL,
            self::STATE_READY,
        ],
        self::STATE_IN_REPAIR => [
            self::STATE_WAITING_PARTS,
            self::STATE_AWAITING_APPROVAL,
            self::STATE_QC,
            self::STATE_READY,
        ],
        self::STATE_QC => [self::STATE_IN_REPAIR, self::STATE_READY],
        self::STATE_READY => [self::STATE_CLOSED, self::STATE_IN_REPAIR],
        self::STATE_CLOSED => [],
    ];

    public static function types(): array
    {
        return [self::TYPE_INTERNAL_REFURBISHMENT, self::TYPE_CUSTOMER_REPAIR];
    }

    public static function states(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function allowedTransitions(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    public static function assertNewJob(RepairJob $job): void
    {
        if (! in_array($job->job_type, self::types(), true)) {
            throw new LogicException('Unsupported owned Repair job type.');
        }

        if ($job->state !== self::STATE_RECEIVED) {
            throw new LogicException('Owned Repair jobs must start in RECEIVED.');
        }

        if ($job->isCustomerRepair() && empty($job->contact_id)) {
            throw new LogicException('Customer Repair requires a contact.');
        }
    }

    public static function assertTransition(RepairJob $job, string $toState, array $evidence = []): void
    {
        if (! in_array($toState, self::states(), true)) {
            throw new LogicException('Unsupported owned Repair state.');
        }

        if (! in_array($toState, self::allowedTransitions((string) $job->state), true)) {
            throw new LogicException(sprintf('Transition %s -> %s is not allowed.', $job->state, $toState));
        }

        if ($toState === self::STATE_READY) {
            self::requireEvidence($evidence, 'resolution_code', 'READY requires an explicit resolution code.');
            if (! in_array($evidence['resolution_code'], [
                self::RESOLUTION_COMPLETED,
                self::RESOLUTION_CANCELLED,
                self::RESOLUTION_DECLINED,
                self::RESOLUTION_UNREPAIRABLE,
            ], true)) {
                throw new LogicException('READY requires a supported resolution code.');
            }
        }

        if (in_array($toState, [self::STATE_WAITING_PARTS, self::STATE_IN_REPAIR], true)
            && $job->state === self::STATE_AWAITING_APPROVAL
        ) {
            self::requireEvidence($evidence, 'approval_satisfied', 'Work cannot begin before approval policy is satisfied.');
        }

        if ($toState === self::STATE_QC) {
            self::requireEvidence($evidence, 'work_submitted', 'Repair work must be submitted before QC.');
        }

        if ($job->state === self::STATE_QC && $toState === self::STATE_IN_REPAIR) {
            self::requireEvidence($evidence, 'qc_failure_reason', 'QC rework requires a failure reason.');
        }

        if ($job->state === self::STATE_QC && $toState === self::STATE_READY) {
            if (($evidence['qc_passed'] ?? false) !== true && ($evidence['qc_waived'] ?? false) !== true) {
                throw new LogicException('QC must pass or have an authorized waiver before READY.');
            }
        }

        if ($job->state === self::STATE_READY && $toState === self::STATE_IN_REPAIR) {
            self::requireEvidence($evidence, 'reopen_reason', 'Reopening a READY job requires a reason.');
        }

        if ($toState === self::STATE_CLOSED) {
            foreach (['qc_satisfied', 'parts_resolved', 'financial_policy_satisfied', 'custody_resolved'] as $key) {
                self::requireEvidence($evidence, $key, 'CLOSED requires all closure prerequisites.');
            }
        }
    }

    private static function requireEvidence(array $evidence, string $key, string $message): void
    {
        if ($key === 'resolution_code' || $key === 'qc_failure_reason' || $key === 'reopen_reason') {
            if (trim((string) ($evidence[$key] ?? '')) === '') {
                throw new LogicException($message);
            }

            return;
        }

        if (($evidence[$key] ?? false) !== true) {
            throw new LogicException($message);
        }
    }
}
