<?php

namespace App\Http\Controllers\Ops;

use App\Support\Ops\HealthChecks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET https://admin.APP_DOMAIN/ops/health — for an external uptime monitor.
 * Requires OPS_HEALTH_TOKEN (Authorization: Bearer … or ?token=…); returns 503
 * when any check fails so a plain HTTP monitor alerts. This also catches a
 * dead scheduler, which cannot alert about itself.
 */
class HealthController
{
    public function __invoke(Request $request, HealthChecks $checks): JsonResponse
    {
        $expected = (string) config('ops.health_token');
        $given = (string) ($request->bearerToken() ?? $request->query('token', ''));

        abort_if($expected === '' || ! hash_equals($expected, $given), 404);

        $results = $checks->run();
        $ok = collect($results)->every(fn ($r) => $r['ok']);

        return response()->json(['ok' => $ok, 'checks' => $results], $ok ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
