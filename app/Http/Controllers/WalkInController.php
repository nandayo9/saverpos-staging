<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Services\WalkInService;
use App\WalkIn;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class WalkInController extends Controller
{
    public function index(Request $request, WalkInService $walkInService)
    {
        $user = $request->user();
        if (! $user->can('walkin.view') && ! $user->can('walkin.view_all')) {
            abort(403, 'Unauthorized action.');
        }

        $locations = BusinessLocation::forDropdown($user->business_id, false)->toArray();
        $canViewAll = $user->can('walkin.view_all');
        $locationId = $request->input('location_id');
        if (! $canViewAll) {
            $locationId = $locationId ?: array_key_first($locations);
            if (! $locationId || ! in_array((int) $locationId, array_map('intval', array_keys($locations)), true)) {
                throw new AuthorizationException();
            }
        }

        [$start, $end] = $this->dateRange($request);
        $datePresets = $this->datePresets();
        $summary = $walkInService->summary($user->business_id, $locationId, $start, $end);
        $base = WalkIn::query()->where('business_id', $user->business_id)
            ->whereBetween('arrived_at', [$start, $end]);
        if ($locationId !== null && $locationId !== '') {
            $base->where('location_id', $locationId);
        }

        $reasonRows = (clone $base)->where('status', WalkIn::STATUS_NO_SALE)
            ->selectRaw('no_sale_reason, COUNT(*) as total')
            ->groupBy('no_sale_reason')->orderByDesc('total')->get();
        $classifiedNoSales = (int) $reasonRows->sum('total');
        $reasons = $reasonRows->map(function ($row) use ($classifiedNoSales) {
            $definition = config('walkin.reasons.'.$row->no_sale_reason, []);
            return [
                'code' => $row->no_sale_reason,
                'label' => $definition['label'] ?? $row->no_sale_reason,
                'kind' => $definition['kind'] ?? 'OTHER',
                'total' => (int) $row->total,
                'percentage' => $classifiedNoSales === 0 ? 0 : round(((int) $row->total / $classifiedNoSales) * 100, 1),
            ];
        });
        $walkIns = (clone $base)->with(['location', 'recorder', 'transaction'])
            ->orderByDesc('arrived_at')->limit(100)->get();

        return view('walk_ins.index', compact(
            'locations', 'canViewAll', 'locationId', 'start', 'end', 'datePresets', 'summary',
            'reasons', 'walkIns'
        ));
    }

    public function store(Request $request, WalkInService $walkInService)
    {
        $data = $request->validate(['location_id' => ['required', 'integer']]);
        $walkIn = $walkInService->capture($request->user(), (int) $data['location_id']);

        return response()->json([
            'success' => true,
            'walk_in' => ['id' => $walkIn->id, 'label' => 'Walk-In #'.$walkIn->id],
            'message' => 'Walk-in recorded.',
        ]);
    }

    public function close(Request $request, WalkIn $walkIn, WalkInService $walkInService)
    {
        $data = $request->validate(['no_sale_reason' => ['required', 'string', 'max:64']]);
        $walkInService->closeAsNoSale($request->user(), $walkIn->id, $data['no_sale_reason']);

        return redirect()->route('walk-ins.index', $request->only(['location_id', 'start', 'end']))
            ->with('status', ['success' => 1, 'msg' => 'Walk-in closed as no sale.']);
    }

    public function open(Request $request)
    {
        $user = $request->user();
        if (! $user->can('walkin.assign')) {
            abort(403, 'Unauthorized action.');
        }
        $data = $request->validate(['location_id' => ['required', 'integer']]);
        if (! \App\User::can_access_this_location((int) $data['location_id'], $user->business_id)) {
            throw new AuthorizationException();
        }

        return WalkIn::query()->where('business_id', $user->business_id)
            ->where('location_id', $data['location_id'])->where('status', WalkIn::STATUS_OPEN)
            ->whereDate('arrived_at', Carbon::today())->latest('arrived_at')->limit(30)
            ->get(['id', 'arrived_at'])->map(fn ($walkIn) => [
                'id' => $walkIn->id,
                'label' => 'Walk-In #'.$walkIn->id.' · '.$walkIn->arrived_at->format('H:i'),
            ]);
    }

    private function dateRange(Request $request): array
    {
        $start = $request->filled('start') ? Carbon::parse($request->input('start'))->startOfDay() : Carbon::today();
        $end = $request->filled('end') ? Carbon::parse($request->input('end'))->endOfDay() : Carbon::now()->endOfDay();
        abort_if($end->lt($start), 422, 'The end date must not precede the start date.');

        return [$start, $end];
    }

    private function datePresets(): array
    {
        $today = Carbon::today();

        return [
            ['label' => 'Today', 'start' => $today->toDateString(), 'end' => $today->toDateString()],
            ['label' => 'Yesterday', 'start' => $today->copy()->subDay()->toDateString(), 'end' => $today->copy()->subDay()->toDateString()],
            ['label' => 'Last 7 Days', 'start' => $today->copy()->subDays(6)->toDateString(), 'end' => $today->toDateString()],
            ['label' => 'This Month', 'start' => $today->copy()->startOfMonth()->toDateString(), 'end' => $today->toDateString()],
        ];
    }
}
