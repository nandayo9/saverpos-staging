<?php

namespace Modules\Recommerce\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Recommerce\Services\CustomerProjectionAccess;

final class CustomerProjectionToken
{
    public function handle(Request $request, Closure $next)
    {
        $access = app(CustomerProjectionAccess::class);
        if (! $access->enabled()) {
            return $this->unavailable(404);
        }

        if (! $access->accepts($request->header('Authorization'))) {
            return $this->unavailable(401);
        }

        return $next($request);
    }

    private function unavailable(int $status)
    {
        return response()->json(['message' => 'Customer projection unavailable.'], $status)
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
