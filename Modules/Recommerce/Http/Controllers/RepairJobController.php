<?php

namespace Modules\Recommerce\Http\Controllers;

use App\User;
use App\Contact;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Entities\WarrantyClaim;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Services\RepairJobIntakeService;
use Modules\Recommerce\Services\RepairJobTransitionService;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\RepairJobStateMachine;
use Modules\Recommerce\Services\RepairPublicLookupService;
use Modules\Recommerce\Services\RepairCollectionService;
use Modules\Recommerce\Services\WarrantyClaimService;
use Modules\Recommerce\Support\Identity\StrongIdentifierHasher;

class RepairJobController extends Controller
{
    public function createPage(AuthorizationGate $authorizationGate)
    {
        $user = auth()->user();
        $businessId = (int) $user->business_id;
        $locationId = (int) config('recommerce.cohort.location_id');

        if (! User::can_access_this_location($locationId, $businessId)
            || ! $authorizationGate->allowsWriteLocation($user, 'recommerce.repair.intake', $businessId, $locationId)) {
            abort(404);
        }

        $customers = Contact::query()
            ->where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'contact_id', 'mobile']);

        $technicians = User::forDropdown($businessId, true, false, false, true);

        return response()->view('recommerce::repair.new', [
            'locationId' => $locationId,
            'customers' => $customers,
            'technicians' => $technicians,
            'checklist' => config('recommerce.repair_intake_checklist', []),
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Intake for a business-owned unit already in the approved cohort.
     * Customer-owned devices remain on the separate counter-intake surface.
     */
    public function createInternalPage(AuthorizationGate $authorizationGate)
    {
        $user = auth()->user();
        $businessId = (int) $user->business_id;
        $locationId = (int) config('recommerce.cohort.location_id');

        if (! User::can_access_this_location($locationId, $businessId)
            || ! $authorizationGate->allowsWriteLocation($user, 'recommerce.repair.intake', $businessId, $locationId)) {
            abort(404);
        }

        $devices = Device::query()
            ->with('product')
            ->where('business_id', $businessId)
            ->where('current_location_id', $locationId)
            ->where('ownership_kind', 'BUSINESS')
            ->whereIn('stock_participation', ['ON_HAND', 'RESERVED'])
            ->orderBy('device_code')
            ->limit(200)
            ->get()
            ->filter(fn (Device $device) => $device->variation_id
                && $authorizationGate->allowsWrite(
                    $user,
                    'recommerce.repair.intake',
                    $businessId,
                    $locationId,
                    (int) $device->variation_id
                ))
            ->values();

        return response()->view('recommerce::repair.internal-new', [
            'locationId' => $locationId,
            'devices' => $devices,
            'technicians' => User::forDropdown($businessId, true, false, false, true),
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function customers(AuthorizationGate $authorizationGate, Request $request)
    {
        $user = auth()->user();
        $businessId = (int) $user->business_id;
        $locationId = (int) config('recommerce.cohort.location_id');
        if (! User::can_access_this_location($locationId, $businessId)
            || ! $authorizationGate->allowsWriteLocation($user, 'recommerce.repair.intake', $businessId, $locationId)) {
            abort(404);
        }

        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []])->header('Cache-Control', 'no-store');
        }

        $customers = Contact::query()
            ->where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])
            ->whereNull('deleted_at')
            ->where(function ($query) use ($term) {
                $query->where('name', 'like', '%'.$term.'%')
                    ->orWhere('mobile', 'like', '%'.$term.'%')
                    ->orWhere('contact_id', 'like', '%'.$term.'%');
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'contact_id', 'mobile'])
            ->map(fn (Contact $contact): array => [
                'id' => (int) $contact->id,
                'name' => $contact->name,
                'reference' => $contact->contact_id,
                'mobile' => $contact->mobile,
            ]);

        return response()->json(['data' => $customers])->header('Cache-Control', 'no-store');
    }

    public function devices(AuthorizationGate $authorizationGate, Request $request)
    {
        $user = auth()->user();
        $businessId = (int) $user->business_id;
        $locationId = (int) config('recommerce.cohort.location_id');
        if (! User::can_access_this_location($locationId, $businessId)
            || ! $authorizationGate->allowsWriteLocation($user, 'recommerce.repair.intake', $businessId, $locationId)) {
            abort(404);
        }

        $contactId = (int) $request->query('contact_id');
        $term = trim((string) $request->query('q', ''));
        if ($contactId < 1 || mb_strlen($term) < 2) {
            return response()->json(['data' => []])->header('Cache-Control', 'no-store');
        }

        $type = strtoupper((string) $request->query('identifier_type', 'SERIAL'));
        if ($type === 'DEVICE_CODE') {
            $devices = Device::query()
                ->where('business_id', $businessId)
                ->where('current_owner_contact_id', $contactId)
                ->where('ownership_kind', 'CUSTOMER')
                ->where('current_location_id', $locationId)
                ->where('device_code', strtoupper($term))
                ->limit(10)
                ->get();
        } else {
            try {
                $hash = StrongIdentifierHasher::hash(StrongIdentifierHasher::normalize($term));
            } catch (\Throwable $exception) {
                return response()->json(['data' => []])->header('Cache-Control', 'no-store');
            }

            $devices = Device::query()
                ->where('business_id', $businessId)
                ->where('current_owner_contact_id', $contactId)
                ->where('ownership_kind', 'CUSTOMER')
                ->where('current_location_id', $locationId)
                ->whereHas('identifiers', function ($query) use ($businessId, $type, $hash) {
                    $query->where('business_id', $businessId)
                        ->where('identifier_type', $type)
                        ->where('normalized_hash', $hash);
                })
                ->limit(10)
                ->get();
        }

        return response()->json([
            'data' => $devices->map(fn (Device $device): array => [
                'id' => (int) $device->id,
                'device_code' => $device->device_code,
                'category_code' => $device->category_code,
                'brand' => $device->specifications_json['brand'] ?? null,
                'model' => $device->specifications_json['model'] ?? null,
            ])->values(),
        ])->header('Cache-Control', 'no-store');
    }

    public function index(AuthorizationGate $authorizationGate)
    {
        return $this->workbench($authorizationGate, false);
    }

    /**
     * A counter-facing workspace that keeps customer-owned repair work
     * separate from business-owned refurbishment operations.
     */
    public function customerIndex(AuthorizationGate $authorizationGate)
    {
        return $this->workbench($authorizationGate, true);
    }

    private function workbench(AuthorizationGate $authorizationGate, bool $customerWorkspace)
    {
        $user = auth()->user();
        $businessId = (int) $user->business_id;
        $locationId = (int) config('recommerce.cohort.location_id');

        $canView = $authorizationGate->allowsRead($user, 'recommerce.repair.view', $businessId, $locationId);
        $canIntake = $authorizationGate->allowsWriteLocation($user, 'recommerce.repair.intake', $businessId, $locationId);
        if (! User::can_access_this_location($locationId, $businessId) || (! $canView && ! $canIntake)) {
            abort(404);
        }

        $jobs = RepairJob::query()
            ->with(['device', 'contact'])
            ->where('business_id', $businessId)
            ->where('location_id', $locationId)
            ->when($customerWorkspace,
                fn ($query) => $query->where('job_type', 'CUSTOMER_REPAIR'),
                function ($query) {
                    $cohortVariationIds = array_values(array_filter(array_map('intval', (array) config('recommerce.cohort.variation_ids', []))));
                    $query->where(function ($workbench) use ($cohortVariationIds) {
                        $workbench->where('job_type', 'CUSTOMER_REPAIR')
                            ->orWhere(function ($internal) use ($cohortVariationIds) {
                                $internal->where('job_type', 'INTERNAL_REFURBISHMENT')
                                    ->whereHas('device', fn ($device) => $device->whereIn('variation_id', $cohortVariationIds));
                            });
                    });
                }
            )
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $devices = \Modules\Recommerce\Entities\Device::query()
            ->with('product')
            ->where('business_id', $businessId)
            ->where('current_location_id', $locationId)
            ->whereIn('variation_id', array_values(array_filter(array_map('intval', (array) config('recommerce.cohort.variation_ids', [])))))
            ->orderBy('device_code')
            ->limit(100)
            ->get();

        return response()->view('recommerce::repair.index', [
            'jobs' => $jobs,
            'devices' => $devices,
            'locationId' => $locationId,
            'intakeEnabled' => $canIntake,
            'customerWorkspace' => $customerWorkspace,
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function show(string $jobCode, AuthorizationGate $authorizationGate)
    {
        $user = auth()->user();
        $businessId = (int) $user->business_id;
        $job = RepairJob::query()
            ->with([
                'device', 'contact', 'diagnosticSessions.templateVersion.template',
                'diagnosticSessions.templateVersion.checks', 'diagnosticSessions.observations',
                'checklistItems', 'stateTransitions', 'partReservations.usage', 'partUsages',
                'quotes.lines',
            ])
            ->where('business_id', $businessId)
            ->where('job_code', strtoupper(trim($jobCode)))
            ->first();

        if (! $job
            || ! User::can_access_this_location($job->location_id, $businessId)
            || ! $authorizationGate->allowsRead($user, 'recommerce.repair.view', $businessId, $job->location_id)
            || ($job->isInternalRefurbishment()
                && ($job->device->variation_id === null || ! $authorizationGate->allowsRead(
                    $user,
                    'recommerce.repair.view',
                    $businessId,
                    $job->location_id,
                    $job->device->variation_id
                )))
        ) {
            abort(404);
        }

        $financialEvidence = ['sale' => null, 'payment_count' => 0, 'payment_total' => null];
        $financialSourceId = $job->source_id;
        if (! $financialSourceId && $job->isCustomerRepair()) {
            $financialSourceId = $job->partUsages()
                ->where('consumption_path', 'CUSTOMER')
                ->whereNotNull('source_transaction_id')
                ->orderByDesc('id')
                ->value('source_transaction_id');
        }
        if ($financialSourceId && $authorizationGate->allowsRead($user, 'recommerce.repair.view_cost', $businessId, $job->location_id)) {
            $sale = DB::table('transactions')
                ->where('business_id', $businessId)
                ->where('id', $financialSourceId)
                ->where('type', 'sell')
                ->first(['id', 'ref_no', 'invoice_no', 'status', 'final_total']);
            if ($sale) {
                $financialEvidence['sale'] = $sale;
                $financialEvidence['payment_count'] = (int) DB::table('transaction_payments')->where('transaction_id', $sale->id)->count();
                $financialEvidence['payment_total'] = DB::table('transaction_payments')->where('transaction_id', $sale->id)->sum('amount');
            }
        }

        return response()->view('recommerce::repair.show', [
            'job' => $job,
            'allowedTransitions' => RepairJobStateMachine::allowedTransitions($job->state),
            'diagnosticViewEnabled' => $job->isCustomerRepair()
                ? $authorizationGate->allowsRead($user, 'recommerce.diagnostic.view', $businessId, $job->location_id)
                : ($job->device->variation_id !== null && $authorizationGate->allowsRead(
                    $user,
                    'recommerce.diagnostic.view',
                    $businessId,
                    $job->location_id,
                    $job->device->variation_id
                )),
            'transitionEnabled' => $job->isCustomerRepair()
                ? $authorizationGate->allowsWriteLocation($user, 'recommerce.repair.transition', $businessId, $job->location_id)
                : ($job->device->variation_id !== null && $authorizationGate->allowsWrite(
                    $user,
                    'recommerce.repair.transition',
                    $businessId,
                    $job->location_id,
                    $job->device->variation_id
                )),
            'costVisible' => $authorizationGate->allowsRead(
                $user,
                'recommerce.repair.view_cost',
                $businessId,
                $job->location_id
            ),
            'financialEvidence' => $financialEvidence,
            'collectionSummary' => $this->collectionSummary($job, $user, $authorizationGate),
            'warrantyClaims' => $this->warrantyClaims($job),
            'canClaimWarranty' => $this->canClaimWarranty($job, $user, $authorizationGate),
            'canCollect' => $job->isCustomerRepair()
                && $job->state === \Modules\Recommerce\Support\RepairJobStateMachine::STATE_READY
                && $authorizationGate->allowsWriteLocation($user, 'recommerce.repair.collection', $businessId, $job->location_id),
            'canStartRepeat' => $this->canStartRepeat($job, $user, $authorizationGate),
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function intake(Request $request, RepairJobIntakeService $intakeService)
    {
        try {
            $validated = $request->validate([
                'command_uuid' => ['required', 'uuid'],
                'location_id' => ['required', 'integer', 'min:1'],
                'device_id' => ['nullable', 'integer', 'min:1', 'required_if:job_type,INTERNAL_REFURBISHMENT'],
                'job_type' => ['required', 'in:INTERNAL_REFURBISHMENT,CUSTOMER_REPAIR'],
                'contact_id' => ['nullable', 'integer', 'min:1', 'required_if:job_type,CUSTOMER_REPAIR'],
                'priority' => ['nullable', 'in:LOW,NORMAL,HIGH,URGENT'],
                'intake_snapshot_json' => ['nullable', 'array'],
                'reported_fault' => ['nullable', 'string', 'max:10000', 'required_if:job_type,CUSTOMER_REPAIR'],
                'cosmetic_condition' => ['nullable', 'string', 'max:10000', 'required_if:job_type,CUSTOMER_REPAIR'],
                'due_at' => ['nullable', 'date_format:Y-m-d'],
                'estimated_quote_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.9999'],
                'warranty_json' => ['nullable', 'array'],
                'access_status' => ['nullable', 'in:NO_LOCK,CUSTOMER_WILL_UNLOCK,EXTERNAL_ACCESS_SUPPLIED', 'required_if:job_type,CUSTOMER_REPAIR'],
                'customer_facing_update' => ['nullable', 'string', 'max:1000'],
                'assigned_to' => ['nullable', 'integer', 'min:1'],
                'identifier_type' => ['nullable', 'in:SERIAL,IMEI,DEVICE_CODE', 'required_with:identifier_value'],
                'identifier_value' => ['nullable', 'string', 'max:255'],
                'category_code' => ['nullable', 'string', 'max:32', 'required_if:job_type,CUSTOMER_REPAIR'],
                'brand' => ['nullable', 'string', 'max:120', 'required_if:job_type,CUSTOMER_REPAIR'],
                'model' => ['nullable', 'string', 'max:160', 'required_if:job_type,CUSTOMER_REPAIR'],
                'checklist' => ['nullable', 'array', 'min:1', 'required_if:job_type,CUSTOMER_REPAIR'],
                'checklist.*.check_key' => ['required', 'string', 'max:64'],
                'checklist.*.label' => ['required', 'string', 'max:160'],
                'checklist.*.outcome' => ['required', 'in:PASS,FAIL,NOT_APPLICABLE'],
                'checklist.*.notes' => ['nullable', 'string', 'max:1000'],
            ]);
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'Please correct the highlighted intake fields.', 'errors' => $exception->errors()], 422)
                ->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        }

        try {
            $job = $intakeService->create(auth()->user(), $validated);
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (LogicException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422)
                ->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        }

        return response()->json([
            'status' => 'REPAIR_JOB_OPENED',
            'job' => [
                'job_code' => $job->job_code,
                'job_type' => $job->job_type,
                'state' => $job->state,
                'lookup_url' => isset($job->lookup_raw_token)
                    ? app(RepairPublicLookupService::class)->url($job, $job->lookup_raw_token)
                    : null,
            ],
        ], 201)->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function publicStatus(string $jobCode, string $token, RepairPublicLookupService $lookupService)
    {
        $job = $lookupService->resolve($jobCode, $token);
        if (! $job || ! $job->isCustomerRepair()) {
            abort(404);
        }

        $job->load('device');
        $specifications = is_array($job->device->specifications_json) ? $job->device->specifications_json : [];
        $publicJob = (object) [
            'job_code' => $job->job_code,
            'state' => $job->state,
            'due_date' => $job->due_at?->format('d M Y'),
            'customer_facing_update' => $job->customer_facing_update,
        ];

        return response()->view('recommerce::repair.public-status', [
            'publicJob' => $publicJob,
            'deviceSummary' => array_filter([
                'category' => $job->device->category_code,
                'brand' => $specifications['brand'] ?? null,
                'model' => $specifications['model'] ?? null,
            ]),
        ])->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function transition(
        Request $request,
        string $jobCode,
        AuthorizationGate $authorizationGate,
        RepairJobTransitionService $transitionService
    ) {
        $user = auth()->user();
        $businessId = (int) $user->business_id;
        $job = RepairJob::query()
            ->where('business_id', $businessId)
            ->where('job_code', strtoupper(trim($jobCode)))
            ->with('device')
            ->first();

        if (! $job
            || ! $job->device
            || ! User::can_access_this_location($job->location_id, $businessId)
            || ! ($job->isCustomerRepair()
                ? $authorizationGate->allowsWriteLocation(
                    $user,
                    'recommerce.repair.transition',
                    $businessId,
                    $job->location_id
                )
                : $authorizationGate->allowsWrite(
                    $user,
                    'recommerce.repair.transition',
                    $businessId,
                    $job->location_id,
                    $job->device->variation_id
                ))
        ) {
            abort(404);
        }

        try {
            $validated = $request->validate([
                'to_state' => ['required', 'string', 'max:32'],
                'expected_lock_version' => ['required', 'integer', 'min:1'],
                'evidence' => ['nullable', 'array'],
            ]);
            $updated = $transitionService->transition(
                $job,
                $validated['to_state'],
                $validated['evidence'] ?? [],
                (int) $validated['expected_lock_version'],
                (int) $user->getAuthIdentifier()
            );
        } catch (ValidationException|LogicException $exception) {
            return response()->json(['message' => 'Repair transition was rejected.'], 422)
                ->header('Cache-Control', 'no-store')
                ->header('Referrer-Policy', 'no-referrer');
        }

        return response()->json([
            'status' => 'REPAIR_JOB_UPDATED',
            'job_code' => $updated->job_code,
            'state' => $updated->state,
            'lock_version' => $updated->lock_version,
        ])->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * A repeat visit reopens a *closed* customer repair, and `startRepeat`
     * authorizes it with the intake permission rather than the collection one.
     * The button used to live inside the collection block, which only renders
     * while the job is READY -- so it could never be shown in the one state it
     * works in.
     */
    private function canStartRepeat(RepairJob $job, User $user, AuthorizationGate $authorizationGate): bool
    {
        return $job->isCustomerRepair()
            && $job->state === RepairJobStateMachine::STATE_CLOSED
            && $authorizationGate->allowsWriteLocation(
                $user,
                'recommerce.repair.intake',
                (int) $job->business_id,
                $job->location_id
            );
    }

    /**
     * Only a customer repair can carry a warranty claim, and only a writer at
     * that job's location may raise one. Internal refurbishment has no customer
     * policy to claim against.
     */
    private function canClaimWarranty(RepairJob $job, User $user, AuthorizationGate $authorizationGate): bool
    {
        return $job->isCustomerRepair()
            && $authorizationGate->allowsWriteLocation(
                $user,
                WarrantyClaimService::PERMISSION_MANAGE,
                (int) $job->business_id,
                $job->location_id
            );
    }

    /**
     * Claims raised from this job, plus any claim that produced it, so a repeat
     * job shows the decision it came from rather than looking unexplained.
     */
    private function warrantyClaims(RepairJob $job)
    {
        return WarrantyClaim::query()
            ->with('lines')
            ->where('business_id', $job->business_id)
            ->where(function ($query) use ($job): void {
                $query->where('source_repair_job_id', $job->id)
                    ->orWhere('repair_job_id', $job->id);
            })
            ->orderByDesc('id')
            ->get();
    }

    private function collectionSummary(RepairJob $job, User $user, AuthorizationGate $authorizationGate): ?array
    {
        if (! $job->isCustomerRepair()
            || $job->state === \Modules\Recommerce\Support\RepairJobStateMachine::STATE_CLOSED) {
            return null;
        }

        try {
            return app(RepairCollectionService::class)->summary($user, $job);
        } catch (AuthorizationException|LogicException $exception) {
            return null;
        }
    }
}
