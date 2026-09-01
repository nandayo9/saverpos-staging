<?php

namespace Modules\Recommerce\Http\Controllers;

use App\BusinessLocation;
use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use LogicException;
use Modules\Recommerce\Entities\StockCountSession;
use Modules\Recommerce\Services\StockCountService;
use Modules\Recommerce\Support\AuthorizationGate;

class StockCountController extends Controller
{
    public function index(Request $request, AuthorizationGate $gate, StockCountService $counts)
    {
        $user = auth()->user(); $businessId = (int) $user->business_id;
        $locationId = (int) $request->query('location_id', config('recommerce.cohort.location_id'));
        if (! User::can_access_this_location($locationId, $businessId) || ! $gate->allowsRead($user, 'recommerce.stockcount.view', $businessId, $locationId)) abort(404);
        $locations = BusinessLocation::query()->where('business_id', $businessId)->whereIn('id', (array) config('recommerce.cohort.location_ids', []))->pluck('name', 'id');
        $sessions = StockCountSession::query()->where('business_id', $businessId)->where('location_id', $locationId)->latest('id')->limit(30)->get();
        $summaries = $sessions->mapWithKeys(fn ($session) => [$session->id => $counts->summary($session)]);
        return view('recommerce::stock-count.index', compact('locationId', 'locations', 'sessions', 'summaries'));
    }

    public function create(Request $request, AuthorizationGate $gate)
    {
        $user = auth()->user(); $businessId = (int) $user->business_id; $locationId = (int) $request->query('location_id', config('recommerce.cohort.location_id'));
        if (! User::can_access_this_location($locationId, $businessId) || ! $gate->allowsWriteLocation($user, 'recommerce.stockcount.create', $businessId, $locationId)) abort(404);
        $variations = \App\Variation::query()->with('product')->whereIn('id', (array) config('recommerce.cohort.variation_ids', []))->get();
        return view('recommerce::stock-count.create', compact('locationId', 'variations'));
    }

    public function store(Request $request, StockCountService $counts)
    {
        $data = Validator::make($request->all(), ['location_id' => ['required', 'integer', 'min:1'], 'count_type' => ['required', 'in:FULL_BRANCH,CYCLE_COUNT'], 'variation_ids' => ['array'], 'variation_ids.*' => ['integer', 'min:1'], 'blind_count' => ['nullable', 'boolean']])->validate();
        try { $session = $counts->create(auth()->user(), (int) $data['location_id'], $data['count_type'], $data['variation_ids'] ?? [], (bool) ($data['blind_count'] ?? false)); }
        catch (AuthorizationException $e) { abort(404); } catch (LogicException $e) { return back()->withInput()->with('status', ['success' => 0, 'msg' => $e->getMessage()]); }
        return redirect()->route('recommerce.stock-counts.show', $session->id)->with('status', ['success' => 1, 'msg' => 'Stock count draft created.']);
    }

    public function show(int $sessionId, AuthorizationGate $gate, StockCountService $counts)
    {
        $session = StockCountSession::query()->findOrFail($sessionId); $user = auth()->user();
        if (! User::can_access_this_location($session->location_id, $user->business_id) || ! $gate->allowsRead($user, 'recommerce.stockcount.view', $session->business_id, $session->location_id)) abort(404);
        $session->load(['items.device.product', 'exceptions', 'audits']);
        $summary = $counts->summary($session); $remaining = $counts->remaining($session); $movements = $counts->postSnapshotMovements($session);
        return view('recommerce::stock-count.show', compact('session', 'summary', 'remaining', 'movements'));
    }

    public function start(int $sessionId, StockCountService $counts) { return $this->redirectAction(fn () => $counts->start(auth()->user(), $sessionId), 'Stock-count snapshot created.'); }
    public function review(int $sessionId, StockCountService $counts) { return $this->redirectAction(fn () => $counts->review(auth()->user(), $sessionId), 'Count submitted for review.'); }
    public function submit(int $sessionId, StockCountService $counts) { return $this->redirectAction(fn () => $counts->submitForApproval(auth()->user(), $sessionId), 'Count submitted for approval.'); }
    public function approve(int $sessionId, StockCountService $counts) { return $this->redirectAction(fn () => $counts->approve(auth()->user(), $sessionId), 'Stock count approved.'); }
    public function reconcile(int $sessionId, StockCountService $counts) { return $this->redirectAction(fn () => $counts->reconcile(auth()->user(), $sessionId), 'Stock count reconciled through native UltimatePOS adjustments where applicable.'); }
    public function close(int $sessionId, StockCountService $counts) { return $this->redirectAction(fn () => $counts->close(auth()->user(), $sessionId), 'Stock count closed and made read-only.'); }

    public function scan(Request $request, int $sessionId, StockCountService $counts)
    {
        $data = Validator::make($request->all(), ['value' => ['required', 'string', 'max:512']])->validate();
        try { return response()->json($counts->scan(auth()->user(), $sessionId, $data['value'])); }
        catch (AuthorizationException $e) { abort(404); } catch (LogicException $e) { return response()->json(['message' => $e->getMessage()], 422); }
    }

    public function quantity(Request $request, int $sessionId, int $itemId, StockCountService $counts)
    {
        $data = Validator::make($request->all(), ['quantity' => ['required', 'numeric', 'min:0']])->validate();
        try { $counts->recordQuantity(auth()->user(), $sessionId, $itemId, (float) $data['quantity']); }
        catch (AuthorizationException $e) { abort(404); } catch (LogicException $e) { return back()->with('status', ['success' => 0, 'msg' => $e->getMessage()]); }
        return back()->with('status', ['success' => 1, 'msg' => 'Physical quantity recorded.']);
    }

    public function resolve(Request $request, int $sessionId, int $exceptionId, StockCountService $counts)
    {
        $data = Validator::make($request->all(), ['resolution_code' => ['required', 'string', 'max:48'], 'resolution_note' => ['required', 'string', 'max:4000']])->validate();
        try { $counts->resolve(auth()->user(), $sessionId, $exceptionId, $data['resolution_code'], $data['resolution_note']); }
        catch (AuthorizationException $e) { abort(404); } catch (LogicException $e) { return back()->with('status', ['success' => 0, 'msg' => $e->getMessage()]); }
        return back()->with('status', ['success' => 1, 'msg' => 'Exception resolution recorded. No Device state or custody was changed.']);
    }

    private function redirectAction(callable $action, string $message)
    {
        try { $session = $action(); }
        catch (AuthorizationException $e) { abort(404); } catch (LogicException $e) { return back()->with('status', ['success' => 0, 'msg' => $e->getMessage()]); }
        return redirect()->route('recommerce.stock-counts.show', $session->id)->with('status', ['success' => 1, 'msg' => $message]);
    }
}
