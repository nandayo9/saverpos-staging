<?php

namespace Modules\Recommerce\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Modules\Recommerce\Entities\TradeInIntake;
use Modules\Recommerce\Services\TradeInAcquisitionCommandAccess;
use Modules\Recommerce\Services\TradeInWebsiteCaseService;
use Modules\Recommerce\Services\SaverValueService;
use Throwable;

final class TradeInWebsiteApiController
{
    public function __construct(private TradeInWebsiteCaseService $cases, private TradeInAcquisitionCommandAccess $access, private SaverValueService $saverValue) {}

    public function indicative(Request $request): JsonResponse
    {
        $input = $request->validate([
            'contract_version' => ['required', 'in:trade-in-pos-authority.v2'],
            'category' => ['required', 'string', 'max:32'],
            'model_id' => ['required', 'string', 'max:100'],
            'model_label' => ['nullable', 'string', 'max:255'],
            'configuration' => ['present', 'array'],
            'condition' => ['present', 'array'],
        ]);
        try {
            return $this->response(['data' => ['contract_version' => 'trade-in-pos-authority.v2'] + $this->saverValue->indicative($input)], 200);
        } catch (LogicException $error) {
            return $this->response(['code' => 'manual_valuation_required', 'message' => $error->getMessage()], 422);
        }
    }

    public function intake(Request $request): JsonResponse
    {
        if (is_array($request->input('photo_ai'))) $this->assertPhotoAiContract((array) $request->input('photo_ai'));
        $command = $request->validate([
            'contract_version' => ['required', 'in:trade-in-pos-authority.v2'],
            'source_system' => ['required', 'in:SAVERBRO_WEBSITE'],
            'external_case_reference' => ['required', 'regex:/^SB-TI-[0-9]{8}-[0-9]{5}$/'],
            'submission_id' => ['required', 'string', 'max:80'],
            'submission_version' => ['required', 'integer', 'min:1'],
            'category' => ['required', 'string', 'max:32'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:255'],
            'specifications' => ['present', 'array'],
            'declared_condition' => ['present', 'array'],
            'indicative_snapshot' => ['nullable', 'array'],
            'evidence_references' => ['present', 'array'],
            'evidence_references.*.evidence_id' => ['required', 'uuid'],
            'evidence_references.*.evidence_type' => ['required', 'string', 'max:32'],
            'evidence_references.*.source' => ['required', 'in:CUSTOMER'],
            'evidence_references.*.mime_type' => ['required', 'in:image/jpeg,image/png,image/webp'],
            'photo_ai' => ['nullable', 'array'],
            'photo_ai.analysis_id' => ['required_with:photo_ai', 'uuid'],
            'photo_ai.analysis_version' => ['required_with:photo_ai', 'integer', 'min:1'],
            'photo_ai.provider' => ['required_with:photo_ai', 'string', 'max:80'],
            'photo_ai.model_version' => ['required_with:photo_ai', 'string', 'max:100'],
            'photo_ai.schema_version' => ['required_with:photo_ai', 'in:1'],
            'photo_ai.status' => ['required_with:photo_ai', 'in:COMPLETED,PARTIAL'],
            'photo_ai.evidence_ids' => ['required_with:photo_ai', 'array', 'min:3', 'max:6'],
            'photo_ai.evidence_ids.*' => ['uuid'],
            'photo_ai.result' => ['required_with:photo_ai', 'array'],
            'photo_ai.result.schema_version' => ['required_with:photo_ai', 'in:1'],
            'photo_ai.result.device' => ['required_with:photo_ai', 'array'],
            'photo_ai.result.device.*' => ['array'],
            'photo_ai.result.device.*.value' => ['nullable'],
            'photo_ai.result.device.*.confidence' => ['required', 'numeric', 'between:0,1'],
            'photo_ai.result.device.*.evidence_ids' => ['present', 'array'],
            'photo_ai.result.device.*.evidence_ids.*' => ['uuid'],
            'photo_ai.result.device.*.source_type' => ['required', 'in:AI_EXTRACTED'],
            'photo_ai.result.identifiers' => ['present', 'array'],
            'photo_ai.result.identifiers.*.type' => ['required', 'in:SERIAL_NUMBER,IMEI,SERVICE_TAG,MACHINE_TYPE,MANUFACTURER_IDENTIFIER'],
            'photo_ai.result.identifiers.*.value' => ['required', 'string', 'max:100'],
            'photo_ai.result.identifiers.*.confidence' => ['required', 'numeric', 'between:0,1'],
            'photo_ai.result.identifiers.*.evidence_ids' => ['required', 'array'],
            'photo_ai.result.identifiers.*.evidence_ids.*' => ['uuid'],
            'photo_ai.result.identifiers.*.source_type' => ['required', 'in:AI_EXTRACTED'],
            'photo_ai.result.identifiers.*.verification_status' => ['required', 'in:UNVERIFIED'],
            'photo_ai.result.cosmetic_grade' => ['required_with:photo_ai', 'array'],
            'photo_ai.result.cosmetic_grade.result' => ['required', 'in:LIKE_NEW,GOOD,FAIR,DAMAGED,UNKNOWN'],
            'photo_ai.result.cosmetic_grade.confidence' => ['required', 'numeric', 'between:0,1'],
            'photo_ai.result.cosmetic_grade.evidence_ids' => ['required', 'array'],
            'photo_ai.result.observations' => ['present', 'array', 'max:30'],
            'photo_ai.result.observations.*.observation' => ['required', 'in:top_cover_scratches,palmrest_wear,corner_dent,case_crack,hinge_damage_visible,missing_key,keyboard_wear,screen_crack,screen_lines_visible,screen_pressure_mark,missing_screw,damaged_port_visible,broken_case_piece,possible_liquid_damage,identity_inconsistency,screen_display_condition,keyboard_completeness'],
            'photo_ai.result.observations.*.result' => ['required', 'in:DETECTED,NOT_DETECTED,UNCERTAIN'],
            'photo_ai.result.observations.*.severity' => ['required', 'in:NONE,MINOR,MODERATE,SEVERE,UNKNOWN'],
            'photo_ai.result.observations.*.confidence' => ['required', 'numeric', 'between:0,1'],
            'photo_ai.result.observations.*.evidence_ids' => ['required', 'array'],
            'photo_ai.result.observations.*.reason' => ['nullable', 'string', 'max:500'],
            'photo_ai.result.warnings' => ['present', 'array', 'max:20'],
            'photo_ai.result.warnings.*' => ['string', 'max:500'],
            'photo_ai.result.insufficient_evidence' => ['present', 'array', 'max:20'],
            'photo_ai.result.insufficient_evidence.*.area' => ['required', 'string', 'max:80'],
            'photo_ai.result.insufficient_evidence.*.reason' => ['required', 'string', 'max:500'],
            'photo_ai.result.overall_confidence' => ['nullable', 'numeric', 'between:0,1'],
            'photo_ai.customer_confirmation' => ['required_with:photo_ai', 'array'],
            'photo_ai.customer_confirmation.analysis_id' => ['required', 'same:photo_ai.analysis_id'],
            'photo_ai.customer_confirmation.analysis_version' => ['required', 'integer', 'same:photo_ai.analysis_version'],
            'photo_ai.customer_confirmation.action' => ['required', 'in:LOOKS_RIGHT,CORRECTED'],
            'photo_ai.customer_confirmation.corrections' => ['present', 'array'],
            'photo_ai.customer_confirmation.confirmed_at' => ['required', 'date'],
            'photo_ai.confirmed_condition_input' => ['required_with:photo_ai', 'array'],
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:255'],
            'customer.email' => ['required', 'email', 'max:255'],
            'customer.phone' => ['required', 'string', 'max:80'],
            'preferred_branch' => ['nullable', 'string', 'max:160'],
            'submitted_at' => ['required', 'date'],
        ]);
        try {
            $result = $this->cases->receive($command, $this->access);
            return $this->response(['data' => $this->cases->projection($result['intake']) + ['replayed' => $result['replayed']]], $result['replayed'] ? 200 : 201);
        } catch (LogicException $error) {
            return $this->response(['code' => 'idempotency_conflict', 'message' => $error->getMessage()], 409);
        } catch (Throwable $error) {
            $context = ['error_class' => get_class($error)];
            if ($error instanceof QueryException) {
                $context += [
                    'sql_state' => $error->errorInfo[0] ?? null,
                    'driver_code' => $error->errorInfo[1] ?? null,
                    'driver_message' => $error->errorInfo[2] ?? null,
                ];
            }
            Log::error('Website Trade-In intake failed.', $context);
            return $this->response(['message' => 'SAVERPOS could not record the Trade-In intake.'], 500);
        }
    }

    /** @param array<string,mixed> $payload */
    private function assertPhotoAiContract(array $payload): void
    {
        $allowed = ['analysis_id','analysis_version','provider','model_version','schema_version','status','evidence_ids','result','customer_confirmation','confirmed_condition_input'];
        if (array_diff(array_keys($payload), $allowed) !== []) throw \Illuminate\Validation\ValidationException::withMessages(['photo_ai' => 'Photo AI contract contains an unknown field.']);
        $walk = function (array $value) use (&$walk): void {
            $forbidden = ['price','amount','amount_minor','offer','product_id','variation_id','device_id','acquisition_id','approval'];
            foreach ($value as $key => $item) {
                if (is_string($key) && in_array(strtolower($key), $forbidden, true)) throw \Illuminate\Validation\ValidationException::withMessages(['photo_ai' => 'Photo AI cannot carry monetary or native authority fields.']);
                if (is_array($item)) $walk($item);
            }
        };
        $walk($payload);
    }

    public function projection(string $externalCaseReference): JsonResponse
    {
        $intake = $this->intakeForCase($externalCaseReference);
        return $this->response(['data' => $this->cases->projection($intake)], 200);
    }

    public function decide(string $externalCaseReference, Request $request): JsonResponse
    {
        $command = $request->validate([
            'contract_version' => ['required', 'in:trade-in-pos-authority.v2'],
            'offer_id' => ['required', 'uuid'],
            'offer_version' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'in:ACCEPTED,DECLINED'],
            'idempotency_key' => ['required', 'uuid'],
            'native_mapping' => ['required_if:decision,ACCEPTED', 'array'],
            'native_mapping.location_id' => ['required_if:decision,ACCEPTED', 'integer', 'min:1'],
            'native_mapping.product_id' => ['required_if:decision,ACCEPTED', 'integer', 'min:1'],
            'native_mapping.variation_id' => ['required_if:decision,ACCEPTED', 'integer', 'min:1'],
            'native_mapping.device_id' => ['required_if:decision,ACCEPTED', 'integer', 'min:1'],
        ]);
        $headerKey = (string) $request->header('Idempotency-Key', '');
        if ($headerKey === '' || ! hash_equals((string) $command['idempotency_key'], $headerKey)) {
            return $this->response(['code' => 'contract_validation', 'message' => 'Idempotency header and decision must match.'], 422);
        }
        $intake = $this->intakeForCase($externalCaseReference);
        $business = DB::table('business')->where('id', $this->access->businessId())->first(['date_format', 'time_format']);
        if (! $business) return $this->response(['message' => 'Trade-In business settings are unavailable.'], 422);
        $request->attributes->set('recommerce.business_date_format', $business->date_format);
        $request->attributes->set('recommerce.business_time_format', (int) $business->time_format);
        try {
            $result = $this->cases->decide($intake, $command, $this->access);
            return $this->response(['data' => $this->cases->projection($intake->fresh()) + [
                'decision_id' => (string) $result['decision']->decision_uuid,
                'idempotency_key' => (string) $result['decision']->idempotency_key,
                'replayed' => $result['replayed'],
            ]], $result['replayed'] ? 200 : 201);
        } catch (AuthorizationException $error) {
            return $this->response(['message' => 'Customer decision completion is not authorized.'], 403);
        } catch (LogicException $error) {
            $mapping = $error->getMessage() === 'native_mapping_mismatch';
            return $this->response(['code' => $mapping ? 'native_mapping_mismatch' : 'decision_conflict', 'message' => $mapping ? 'SAVERPOS rejected the native Trade-In mapping.' : $error->getMessage()], $mapping ? 422 : 409);
        } catch (Throwable $error) {
            Log::error('Website Trade-In customer decision failed.', ['error_class' => get_class($error), 'trade_in_case_id' => $externalCaseReference]);
            return $this->response(['message' => 'SAVERPOS could not record the customer decision.'], 500);
        }
    }

    private function intakeForCase(string $case): TradeInIntake
    {
        return TradeInIntake::query()->where('business_id', $this->access->businessId())
            ->where('source_system', 'SAVERBRO_WEBSITE')->where('external_case_reference', $case)->firstOrFail();
    }

    /** @param array<string, mixed> $payload */
    private function response(array $payload, int $status): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
