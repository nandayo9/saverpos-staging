<?php

namespace Modules\Recommerce\Http\Controllers;

use App\BusinessLocation;
use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Recommerce\Entities\DiagnosticTemplate;
use Modules\Recommerce\Entities\DiagnosticTemplateVersion;
use Modules\Recommerce\Services\DiagnosticTemplateService;
use Modules\Recommerce\Support\AuthorizationGate;

class DiagnosticTemplateController extends Controller
{
    public function index(AuthorizationGate $gate)
    {
        $user = auth()->user();
        $templates = DiagnosticTemplate::query()
            ->with(['versions' => fn ($query) => $query->latest('version_number')])
            ->where('business_id', $user->business_id)
            ->get()
            ->filter(fn (DiagnosticTemplate $template): bool => $this->canManage($user, $gate, $template->location_id));

        return response()->view('recommerce::diagnostics/templates/index', compact('templates'))
            ->header('Cache-Control', 'no-store');
    }

    public function create(AuthorizationGate $gate)
    {
        return response()->view('recommerce::diagnostics/templates/form', [
            'template' => null,
            'version' => null,
            'locations' => $this->locations(auth()->user(), $gate),
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, DiagnosticTemplateService $service, AuthorizationGate $gate)
    {
        try {
            $validated = $this->validated($request, true);
            $user = auth()->user();
            $this->assertManage($user, $gate, (int) $validated['location_id']);
            $version = $service->createDraft($user, (int) $validated['location_id'], $validated, $this->checks($validated['checks']));
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (LogicException $exception) {
            return back()->withErrors(['template' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('recommerce.diagnostic.templates.edit', [$version->template_id, $version->id])
            ->with('status', 'Draft diagnostic template created.');
    }

    public function edit(int $templateId, int $versionId, AuthorizationGate $gate)
    {
        $version = $this->version($templateId, $versionId, $gate);

        return response()->view('recommerce::diagnostics/templates/form', [
            'template' => $version->template,
            'version' => $version,
            'locations' => $this->locations(auth()->user(), $gate),
        ])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, int $templateId, int $versionId, DiagnosticTemplateService $service, AuthorizationGate $gate)
    {
        try {
            $validated = $this->validated($request, true);
            $version = $this->version($templateId, $versionId, $gate);
            $this->assertManage(auth()->user(), $gate, (int) $version->template->location_id);
            $service->updateDraft(auth()->user(), $version, $validated, $this->checks($validated['checks']));
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (LogicException $exception) {
            return back()->withErrors(['template' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Draft diagnostic template saved.');
    }

    public function publish(int $templateId, int $versionId, DiagnosticTemplateService $service, AuthorizationGate $gate)
    {
        $version = $this->version($templateId, $versionId, $gate);
        $this->assertManage(auth()->user(), $gate, (int) $version->template->location_id);
        try {
            $service->publish($version, (int) auth()->id());
        } catch (LogicException $exception) {
            return back()->withErrors(['template' => $exception->getMessage()]);
        }

        return back()->with('status', 'Diagnostic template version published.');
    }

    public function retire(int $templateId, int $versionId, DiagnosticTemplateService $service, AuthorizationGate $gate)
    {
        $version = $this->version($templateId, $versionId, $gate);
        $this->assertManage(auth()->user(), $gate, (int) $version->template->location_id);
        try {
            $service->retire($version);
        } catch (LogicException $exception) {
            return back()->withErrors(['template' => $exception->getMessage()]);
        }

        return back()->with('status', 'Diagnostic template version retired.');
    }

    public function revision(int $templateId, DiagnosticTemplateService $service, AuthorizationGate $gate)
    {
        $template = DiagnosticTemplate::query()->where('business_id', auth()->user()->business_id)->findOrFail($templateId);
        $this->assertManage(auth()->user(), $gate, (int) $template->location_id);
        try {
            $version = $service->createRevision(auth()->user(), $template);
        } catch (LogicException $exception) {
            return back()->withErrors(['template' => $exception->getMessage()]);
        }

        return redirect()->route('recommerce.diagnostic.templates.edit', [$template->id, $version->id]);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, bool $includeLocation): array
    {
        $rules = [
            'template_code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:160'],
            'category_code' => ['nullable', 'string', 'max:32'],
            'job_type' => ['nullable', 'in:CUSTOMER_REPAIR,INTERNAL_REFURBISHMENT'],
            'rubric' => ['nullable', 'array'],
            'checks' => ['required', 'array', 'min:1'],
            'checks.*.check_key' => ['required', 'string', 'max:64'],
            'checks.*.label' => ['required', 'string', 'max:160'],
            'checks.*.outcome_type' => ['required', 'in:STATUS,TEXT,NUMERIC'],
            'checks.*.unit' => ['nullable', 'string', 'max:24'],
            'checks.*.minimum_value' => ['nullable', 'numeric'],
            'checks.*.maximum_value' => ['nullable', 'numeric'],
            'checks.*.allowed_outcomes' => ['nullable', 'string', 'max:500'],
            'checks.*.is_required' => ['nullable', 'boolean'],
            'checks.*.evidence_required' => ['nullable', 'boolean'],
        ];
        if ($includeLocation) {
            $rules['location_id'] = ['required', 'integer', 'min:1'];
        }

        return $request->validate($rules);
    }

    /** @param array<int, array<string, mixed>> $checks */
    protected function checks(array $checks): array
    {
        return array_map(function (array $check): array {
            $check['allowed_outcomes'] = array_values(array_filter(array_map('trim', explode(',', (string) ($check['allowed_outcomes'] ?? 'PASS,FAIL,NOT_APPLICABLE')))));
            $check['is_required'] = ! empty($check['is_required']);
            $check['evidence_required'] = ! empty($check['evidence_required']);

            return $check;
        }, $checks);
    }

    protected function version(int $templateId, int $versionId, AuthorizationGate $gate): DiagnosticTemplateVersion
    {
        $version = DiagnosticTemplateVersion::query()->with(['template', 'checks'])
            ->where('business_id', auth()->user()->business_id)
            ->whereKey($versionId)
            ->where('template_id', $templateId)
            ->firstOrFail();
        $this->assertManage(auth()->user(), $gate, (int) $version->template->location_id);

        return $version;
    }

    protected function assertManage(User $user, AuthorizationGate $gate, int $locationId): void
    {
        if (! $this->canManage($user, $gate, $locationId)) {
            throw new AuthorizationException();
        }
    }

    protected function canManage(User $user, AuthorizationGate $gate, ?int $locationId): bool
    {
        return $locationId !== null
            && User::can_access_this_location($locationId, $user->business_id)
            && $gate->allowsWriteLocation($user, 'recommerce.diagnostic.manage', $user->business_id, $locationId);
    }

    protected function locations(User $user, AuthorizationGate $gate)
    {
        return BusinessLocation::query()->where('business_id', $user->business_id)->where('is_active', 1)->get()
            ->filter(fn (BusinessLocation $location): bool => $this->canManage($user, $gate, (int) $location->id));
    }
}
