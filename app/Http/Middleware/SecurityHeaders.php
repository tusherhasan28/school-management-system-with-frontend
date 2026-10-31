<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers for every web response: CSP with a per-request script
 * nonce, clickjacking and MIME-sniffing protection, referrer and
 * permissions policies, and HSTS on HTTPS.
 *
 * A controller may allow one extra form target for its own response (the
 * payment checkout hand-off) through the "csp.form-action" request attribute.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        if ($request->isSecure()) {
            // Browsers ignore COOP on plain HTTP (and warn), so only send it with TLS.
            $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
            $headers->set('Strict-Transport-Security', 'max-age='.config('security.hsts_max_age').'; includeSubDomains');
        }

        if (config('security.csp.enabled') && ! Vite::isRunningHot() && ! $headers->has('Content-Security-Policy')) {
            $formAction = ["'self'", ...config('security.csp.form_action', []), ...(array) $request->attributes->get('csp.form-action', [])];
            $policy = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "font-src 'self' data:",
                "connect-src 'self'",
                'frame-src '.implode(' ', ["'self'", ...config('security.csp.frame_src', [])]),
                "frame-ancestors 'self'",
                'form-action '.implode(' ', array_unique($formAction)),
                "base-uri 'self'",
                "object-src 'none'",
            ]);
            $headers->set(config('security.csp.report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', $policy);
        }

        return $response;
    }
}
