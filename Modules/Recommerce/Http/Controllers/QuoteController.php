<?php

namespace Modules\Recommerce\Http\Controllers;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Entities\RepairQuote;
use Modules\Recommerce\Entities\RepairQuoteLine;
use Modules\Recommerce\Services\RepairQuoteService;
use Modules\Recommerce\Support\AuthorizationGate;

/**
 * Customer-repair quote versions. The service owns all lifecycle rules;
 * controllers only validate transport payloads and expose scoped actions.
 */
class QuoteController extends Controller
{
    public function store(Request $request, string $jobCode, AuthorizationGate $authorizationGate, RepairQuoteService $quoteService)
    {
        try {
            $validated = $request->validate([
                'command_uuid' => ['required', 'uuid'],
                'summary' => ['nullable', 'string', 'max:320'],
                'tax_assumptions' => ['nullable', 'array'],
                'terms' => ['nullable', 'array'],
                'currency' => ['nullable', 'string', 'max:12'],
                'expires_at' => ['nullable', 'date'],
                'lines' => ['required', 'array', 'min:1'],
                'lines.*.line_type' => ['required', 'string', 'max:24'],
                'lines.*.description' => ['required', 'string', 'max:255'],
                'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
                'lines.*.unit_amount' => ['required', 'numeric', 'min:0'],
                'lines.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
            ]);

            $job = $this->scopedJob($jobCode, $authorizationGate);
            $quote = $quoteService->createDraft(
                auth()->user(),
                $job,
                $validated['command_uuid'],
                $validated['lines'],
                $validated['summary'] ?? null,
                $validated['tax_assumptions'] ?? null,
                $validated['terms'] ?? null,
                $validated['currency'] ?? null,
                $validated['expires_at'] ?? null
            );
        } catch (ValidationException|LogicException $exception) {
            return $this->rejected('Quote draft was rejected.');
        } catch (AuthorizationException $exception) {
            abort(404);
        }

        return $this->quoteResponse($quote, 'QUOTE_DRAFT_CREATED', 201);
    }

    public function send(Request $request, string $jobCode, int $quoteId, AuthorizationGate $authorizationGate, RepairQuoteService $quoteService)
    {
        try {
            $validated = $request->validate(['channel' => ['required', 'string', 'max:40']]);
            $job = $this->scopedJob($jobCode, $authorizationGate);
            $quote = $this->scopedQuote($job, $quoteId);
            $quote = $quoteService->send(auth()->user(), $quote, (string) $validated['channel']);
        } catch (ValidationException|LogicException $exception) {
            return $this->rejected('Quote send was rejected.');
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            abort(404);
        }

        return $this->quoteResponse($quote, 'QUOTE_SENT');
    }

    public function decide(Request $request, string $jobCode, int $quoteId, AuthorizationGate $authorizationGate, RepairQuoteService $quoteService)
    {
        try {
            $validated = $request->validate([
                'decision' => ['required', 'in:APPROVED,DECLINED'],
                'evidence' => ['nullable', 'array'],
                'note' => ['nullable', 'string', 'max:1000'],
            ]);
            $job = $this->scopedJob($jobCode, $authorizationGate);
            $quote = $this->scopedQuote($job, $quoteId);
            $quote = $quoteService->decide(
                auth()->user(),
                $quote,
                $validated['decision'],
                (array) ($validated['evidence'] ?? []),
                $validated['note'] ?? null
            );
        } catch (ValidationException|LogicException $exception) {
            return $this->rejected('Quote decision was rejected.');
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            abort(404);
        }

        return $this->quoteResponse($quote, 'QUOTE_DECIDED');
    }

    protected function quoteResponse(RepairQuote $quote, string $status, int $httpCode = 200)
    {
        return response()->json([
            'status' => $status,
            'quote_uuid' => $quote->quote_uuid,
            'version_number' => $quote->version_number,
            'quote_status' => $quote->status,
            'subtotal_amount' => (float) $quote->subtotal_amount,
            'tax_amount' => (float) $quote->tax_amount,
            'total_amount' => (float) $quote->total_amount,
            'expires_at' => optional($quote->expires_at)->toAtomString(),
        ], $httpCode)->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    protected function scopedJob(string $jobCode, AuthorizationGate $authorizationGate): RepairJob
    {
        $user = auth()->user();
        $job = RepairJob::query()
            ->with('device')
            ->where('business_id', (int) $user->business_id)
            ->where('job_code', strtoupper(trim($jobCode)))
            ->first();

        $internalScopeAllowed = $job && ! $job->isCustomerRepair()
            ? ($job->device && $job->device->variation_id !== null && $authorizationGate->allowsRead(
                $user,
                'recommerce.repair.view',
                $user->business_id,
                $job->location_id,
                $job->device->variation_id ?? null
            ))
            : true;

        if (! $job || ! $job->device || ! User::can_access_this_location($job->location_id, $user->business_id)
            || ! $authorizationGate->allowsRead($user, 'recommerce.repair.view', $user->business_id, $job->location_id)
            || ! $internalScopeAllowed) {
            throw new AuthorizationException();
        }

        return $job;
    }

    protected function scopedQuote(RepairJob $job, int $quoteId): RepairQuote
    {
        return RepairQuote::query()
            ->where('business_id', $job->business_id)
            ->where('repair_job_id', $job->getKey())
            ->whereKey($quoteId)
            ->with('lines')
            ->firstOrFail();
    }

    protected function rejected(string $message)
    {
        return response()->json(['message' => $message], 422)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
