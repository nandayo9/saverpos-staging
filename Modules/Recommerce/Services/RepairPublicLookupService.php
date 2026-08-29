<?php

namespace Modules\Recommerce\Services;

use LogicException;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Entities\RepairLookupToken;
use Modules\Recommerce\Support\Identity\OpaqueScanToken;

class RepairPublicLookupService
{
    public function __construct(protected OpaqueScanToken $tokenService)
    {
    }

    /**
     * Issue once per repair. The raw token is returned only to the caller and
     * is never persisted, logged, or placed in an internal event payload.
     */
    public function issue(RepairJob $job, int $actorId): array
    {
        $existing = $job->lookupTokens()->where('status', 'ACTIVE')->first();
        if ($existing) {
            return [$existing, null];
        }

        $rawToken = $this->tokenService->issue();
        $token = new RepairLookupToken([
            'business_id' => $job->business_id,
            'repair_job_id' => $job->getKey(),
            'token_hash' => $this->tokenService->hash($rawToken),
            'token_hint' => substr($rawToken, -8),
            'status' => 'ACTIVE',
            'issued_at' => now(),
            'issued_by' => $actorId,
        ]);
        $token->save();

        return [$token, $rawToken];
    }

    public function resolve(string $jobCode, string $rawToken): ?RepairJob
    {
        if (preg_match('/^[A-Fa-f0-9]{64}$/D', $rawToken) !== 1) {
            return null;
        }

        $tokenHash = $this->tokenService->hash($rawToken);

        return RepairJob::query()
            ->where('job_code', strtoupper(trim($jobCode)))
            ->whereHas('lookupTokens', function ($query) use ($tokenHash) {
                $query->where('token_hash', $tokenHash)->where('status', 'ACTIVE');
            })
            ->first();
    }

    public function url(RepairJob $job, string $rawToken): string
    {
        if (preg_match('/^[A-Fa-f0-9]{64}$/D', $rawToken) !== 1) {
            throw new LogicException('Invalid public lookup token.');
        }

        return route('recommerce.repair.public_status', [
            'jobCode' => $job->job_code,
            'token' => $rawToken,
        ], true);
    }
}
