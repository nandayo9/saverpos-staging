<?php

namespace Modules\Recommerce\Http\Controllers;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
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
            $validated = $this->draftPayload($request);

            $job = $this->scopedJob($jobCode, $authorizationGate);
            $quote = $quoteService->createDraft(
                auth()->user(),
                $job,
                $validated['command_uuid'],
                $this->scopedLines($validated['lines'], $job),
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

    public function update(Request $request, string $jobCode, int $quoteId, AuthorizationGate $authorizationGate, RepairQuoteService $quoteService)
    {
        try {
            $validated = $this->draftPayload($request, false);
            $job = $this->scopedJob($jobCode, $authorizationGate);
            $quote = $this->scopedQuote($job, $quoteId);
            $quote = $quoteService->updateDraft(
                auth()->user(),
                $quote,
                $this->scopedLines($validated['lines'], $job),
                $validated['summary'] ?? null,
                $validated['tax_assumptions'] ?? null,
                $validated['terms'] ?? null,
                $validated['expires_at'] ?? null
            );
        } catch (ValidationException|LogicException $exception) {
            return $this->rejected('Quote draft update was rejected.');
        } catch (AuthorizationException $exception) {
            abort(404);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            abort(404);
        }

        return $this->quoteResponse($quote, 'QUOTE_DRAFT_UPDATED');
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

    /** @return array<string, mixed> */
    protected function draftPayload(Request $request, bool $requiresCommandUuid = true): array
    {
        $rules = [
            'summary' => ['nullable', 'string', 'max:320'],
            'tax_assumptions' => ['nullable', 'array'],
            'terms' => ['nullable', 'array'],
            'currency' => ['nullable', 'string', 'max:12'],
            'expires_at' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_type' => ['required', 'in:LABOUR,PART,SERVICE,OTHER'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_amount' => ['required', 'numeric', 'min:0'],
            'lines.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.variation_id' => ['nullable', 'integer', 'min:1'],
        ];
        if ($requiresCommandUuid) {
            $rules['command_uuid'] = ['required', 'uuid'];
        }

        return $request->validate($rules);
    }

    /**
     * A variation reference is optional, but if an operator supplies one it
     * must resolve to the same UltimatePOS business. This stores a link only;
     * it never creates a parallel product or billing catalogue.
     *
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array<string, mixed>>
     */
    protected function scopedLines(array $lines, RepairJob $job): array
    {
        foreach ($lines as $index => $line) {
            if (empty($line['variation_id'])) {
                continue;
            }
            $productId = DB::table('variations')
                ->join('products', 'products.id', '=', 'variations.product_id')
                ->where('variations.id', (int) $line['variation_id'])
                ->where('products.business_id', $job->business_id)
                ->whereNull('variations.deleted_at')
                ->value('products.id');
            if (! $productId) {
                throw new LogicException('Quote line product variation is not available for this business.');
            }
            $lines[$index]['source_type'] = 'POS_VARIATION';
            $lines[$index]['source_id'] = (int) $productId;
        }

        return $lines;
    }

    protected function rejected(string $message)
    {
        return response()->json(['message' => $message], 422)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
