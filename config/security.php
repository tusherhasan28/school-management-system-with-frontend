<?php

return [
    /*
    | Content Security Policy. Scripts need the per-request nonce (Vite adds it
    | to its tags; inline scripts use @nonce). Styles allow inline because
    | React style attributes and print layouts use them. Extra origins come
    | from configuration, never from request input.
    */
    'csp' => [
        'enabled' => env('SECURITY_CSP_ENABLED', true),
        'report_only' => env('SECURITY_CSP_REPORT_ONLY', false),
        // Google Maps embeds on school contact pages.
        'frame_src' => ['https://www.google.com'],
        // Payment provider checkout pages (added when a gateway adapter is installed).
        'form_action' => array_values(array_filter(explode(',', (string) env('SECURITY_CSP_FORM_ACTION', '')))),
    ],

    // Strict-Transport-Security on HTTPS responses (production should be HTTPS-only).
    'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),

    /*
    | Proxies whose X-Forwarded-* headers are trusted. Empty (default) = none.
    | "cloudflare" = Cloudflare's published ranges below; or a comma-separated
    | list of IPs/CIDRs; "*" = whoever connects (only when the server cannot be
    | reached except through the proxy).
    */
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    // https://www.cloudflare.com/ips/ (last changed 28 Sep 2023)
    'cloudflare_ips' => [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ],
];
