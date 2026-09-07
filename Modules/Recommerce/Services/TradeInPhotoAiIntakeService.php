<?php

namespace Modules\Recommerce\Services;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Recommerce\Entities\TradeInIntake;
use Modules\Recommerce\Entities\TradeInPhotoAiAnalysis;
use Modules\Recommerce\Entities\TradeInPhotoAiReview;
use Modules\Recommerce\Support\AuthorizationGate;

/**
 * Imports website AI observations as non-authoritative evidence. Native
 * catalogue and Device lookups are delegated to their existing resolvers;
 * this service never creates identity, price, stock, purchase, or approval.
 */
final class TradeInPhotoAiIntakeService
{
    public function __construct(
        private TradeInCatalogueService $catalogue,
        private DeviceIdentityResolver $identities,
        private AuthorizationGate $authorizationGate
    ) {}

    /** @param array<string,mixed> $payload */
    public function attach(TradeInIntake $intake, array $payload): TradeInPhotoAiAnalysis
    {
        if ($intake->category_code !== 'LAPTOP') throw new LogicException('Photo AI V1 accepts laptop observations only.');
        $submittedEvidence = collect((array) $intake->evidence_references_json)->pluck('evidence_id')->map(fn ($id): string => (string) $id)->all();
        $analysisEvidence = array_values(array_unique(array_map('strval', (array) ($payload['evidence_ids'] ?? []))));
        if (array_diff($analysisEvidence, $submittedEvidence) !== []) throw new LogicException('Photo AI references evidence outside this immutable intake.');
        $result = (array) ($payload['result'] ?? []);
        foreach ((array) ($result['device'] ?? []) as $fact) $this->assertEvidenceScope((array) ($fact['evidence_ids'] ?? []), $analysisEvidence);
        foreach ((array) ($result['identifiers'] ?? []) as $identifier) $this->assertEvidenceScope((array) ($identifier['evidence_ids'] ?? []), $analysisEvidence);
        $this->assertEvidenceScope((array) data_get($result, 'cosmetic_grade.evidence_ids', []), $analysisEvidence);
        foreach ((array) ($result['observations'] ?? []) as $observation) $this->assertEvidenceScope((array) ($observation['evidence_ids'] ?? []), $analysisEvidence);
        $specifications = $this->specifications((array) ($result['device'] ?? []));
        $catalogue = $this->catalogueMatch((int) $intake->business_id, $specifications);
        $identity = $this->identityResolution((int) $intake->business_id, (array) ($result['identifiers'] ?? []));

        return TradeInPhotoAiAnalysis::create([
            'analysis_uuid' => $payload['analysis_id'], 'intake_id' => $intake->id,
            'analysis_version' => $payload['analysis_version'], 'provider' => $payload['provider'],
            'model_version' => $payload['model_version'], 'schema_version' => $payload['schema_version'],
            'status' => $payload['status'], 'evidence_ids_json' => $payload['evidence_ids'],
            'result_json' => $result, 'customer_confirmation_json' => $payload['customer_confirmation'],
            'confirmed_condition_json' => $payload['confirmed_condition_input'],
            'catalogue_match_status' => $catalogue['status'], 'matched_product_id' => $catalogue['product_id'],
            'matched_variation_id' => $catalogue['variation_id'], 'catalogue_candidates_json' => $catalogue['candidates'],
            'identity_resolution_status' => $identity['status'], 'resolved_device_id' => $identity['device_id'],
            'identity_resolution_json' => $identity['evidence'], 'overall_confidence' => $result['overall_confidence'] ?? null,
        ]);
    }

    private function assertEvidenceScope(array $references, array $analysisEvidence): void
    {
        if (array_diff(array_map('strval', $references), $analysisEvidence) !== []) throw new LogicException('Photo AI observation references evidence outside its analysis version.');
    }

    /** @param list<array<string,mixed>> $findings */
    public function review(User $user, TradeInPhotoAiAnalysis $analysis, array $findings): TradeInPhotoAiReview
    {
        $analysis->loadMissing('intake');
        $intake = $analysis->intake;
        $locationId = (int) ($intake->location_id ?: config('recommerce.cohort.location_id'));
        if (! $this->authorizationGate->allowsWriteLocation($user, TradeInService::PERMISSION_MANAGE, (int) $intake->business_id, $locationId)) {
            throw new AuthorizationException('Photo AI technician review denied.');
        }
        if ($analysis->review()->exists()) throw new LogicException('This Photo AI analysis already has an immutable technician review.');
        $aiByKey = collect((array) data_get($analysis->result_json, 'observations', []))->keyBy('observation');
        $clean = [];
        $disagreements = [];
        foreach ($findings as $finding) {
            $key = (string) ($finding['observation'] ?? '');
            $ai = $aiByKey->get($key);
            if (! is_array($ai)) throw new LogicException('Technician finding does not match an AI observation.');
            $outcome = (string) ($finding['finding'] ?? '');
            if (! in_array($outcome, ['PRESENT','ABSENT','PARTIAL','NOT_ASSESSABLE'], true)) throw new LogicException('Choose a supported technician finding.');
            $severity = (string) ($finding['severity'] ?? 'UNKNOWN');
            if (! in_array($severity, ['NONE','MINOR','MODERATE','SEVERE','UNKNOWN'], true)) throw new LogicException('Choose a supported technician severity.');
            $clean[] = ['observation' => $key, 'finding' => $outcome, 'severity' => $severity, 'notes' => mb_substr(trim((string) ($finding['notes'] ?? '')), 0, 500)];
            $disagreements[] = ['observation' => $key, 'outcome' => $this->disagreement((string) $ai['result'], $outcome)];
        }
        if ($clean === []) throw new LogicException('Record at least one technician finding.');
        return DB::transaction(fn () => TradeInPhotoAiReview::create([
            'analysis_id' => $analysis->id, 'reviewed_by' => $user->id,
            'technician_findings_json' => $clean, 'disagreements_json' => $disagreements, 'reviewed_at' => now(),
        ]));
    }

    /** @param array<string,mixed> $device */
    private function specifications(array $device): array
    {
        $value = static fn (string $key) => data_get($device, $key.'.value');
        return array_filter([
            'brand' => $value('brand'), 'model' => $value('model'), 'cpu' => $value('cpu'),
            'ram' => is_numeric($value('ram_gb')) ? ((int) $value('ram_gb')).'GB' : $value('ram_gb'),
            'storage' => is_numeric($value('storage_gb')) ? ((int) $value('storage_gb')).'GB' : $value('storage_gb'),
            'gpu' => $value('gpu'), 'display_size' => $value('screen_size'),
        ], fn ($item) => $item !== null && trim((string) $item) !== '');
    }

    private function catalogueMatch(int $businessId, array $specifications): array
    {
        if (empty($specifications['brand']) || empty($specifications['model'])) return ['status' => 'NO_MATCH', 'product_id' => null, 'variation_id' => null, 'candidates' => []];
        try { $match = $this->catalogue->match($businessId, $specifications); }
        catch (LogicException) { return ['status' => 'NO_MATCH', 'product_id' => null, 'variation_id' => null, 'candidates' => []]; }
        if ($match['exact']) return ['status' => 'EXACT', 'product_id' => (int) $match['exact']->product_id, 'variation_id' => (int) $match['exact']->id, 'candidates' => []];
        $candidates = $match['similar']->map(fn ($variation) => ['product_id' => (int) $variation->product_id, 'variation_id' => (int) $variation->id, 'label' => trim(optional($variation->product)->name.' '.$variation->name)])->values()->all();
        return ['status' => count($candidates) === 1 ? 'PROBABLE' : (count($candidates) > 1 ? 'AMBIGUOUS' : 'NO_MATCH'), 'product_id' => null, 'variation_id' => null, 'candidates' => $candidates];
    }

    /** @param list<array<string,mixed>> $identifiers */
    private function identityResolution(int $businessId, array $identifiers): array
    {
        $evidence = []; $deviceIds = [];
        foreach ($identifiers as $identifier) {
            $device = $this->identities->resolve($businessId, (string) ($identifier['value'] ?? ''));
            $resolvedId = $device ? (int) $device->id : null;
            if ($resolvedId) $deviceIds[] = $resolvedId;
            $evidence[] = ['type' => $identifier['type'] ?? 'MANUFACTURER_IDENTIFIER', 'value' => $identifier['value'] ?? '', 'confidence' => $identifier['confidence'] ?? null, 'verification_status' => 'UNVERIFIED', 'resolver_result' => $resolvedId ? 'EXISTING_DEVICE_CANDIDATE' : 'UNRESOLVED', 'resolved_device_id' => $resolvedId];
        }
        $deviceIds = array_values(array_unique($deviceIds));
        return ['status' => count($deviceIds) > 1 ? 'CONFLICTING_IDENTIFIERS' : (count($deviceIds) === 1 ? 'EXISTING_DEVICE_CANDIDATE' : ($identifiers === [] ? 'NOT_APPLICABLE' : 'UNRESOLVED')), 'device_id' => count($deviceIds) === 1 ? $deviceIds[0] : null, 'evidence' => $evidence];
    }

    private function disagreement(string $ai, string $technician): string
    {
        if ($technician === 'NOT_ASSESSABLE') return 'NOT_ASSESSABLE';
        if ($technician === 'PARTIAL' || $ai === 'UNCERTAIN') return 'AI_PARTIALLY_CORRECT';
        if ($ai === 'DETECTED') return $technician === 'PRESENT' ? 'AI_CONFIRMED' : 'AI_FALSE_POSITIVE';
        if ($ai === 'NOT_DETECTED') return $technician === 'ABSENT' ? 'AI_CONFIRMED' : 'AI_FALSE_NEGATIVE';
        return 'AI_PARTIALLY_CORRECT';
    }
}
