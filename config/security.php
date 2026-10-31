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
];
