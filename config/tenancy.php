<?php

return [

    /*
    | Root platform domain. Schools are served from {slug}.{domain} and the
    | platform administration from {admin_subdomain}.{domain}.
    */
    'domain' => env('APP_DOMAIN', 'schoolsaas.test'),

    'admin_subdomain' => env('TENANCY_ADMIN_SUBDOMAIN', 'admin'),

    /*
    | true on hosts without wildcard subdomains (e.g. Hostinger shared): every
    | new school's subdomain must be added in the hosting panel, pointing to
    | the app folder. The platform reminds you when a school is created.
    */
    'manual_subdomains' => (bool) env('TENANCY_MANUAL_SUBDOMAINS', false),

    // Interface language of the platform admin area.
    'platform_locale' => env('TENANCY_PLATFORM_LOCALE', 'en'),

    /*
    | Subdomains that can never be claimed as a school slug.
    */
    'reserved_slugs' => [
        'admin', 'www', 'api', 'app', 'mail', 'smtp', 'imap', 'pop', 'ftp',
        'static', 'assets', 'cdn', 'media', 'files', 'status', 'help',
        'support', 'docs', 'blog', 'billing', 'platform', 'dashboard',
        'login', 'register', 'auth', 'sso', 'horizon', 'test', 'demo-admin',
    ],

    /*
    | School statuses whose portals and websites are reachable.
    | Others get a generic "unavailable" page. Data is never deleted on suspension.
    */
    'accessible_statuses' => ['trial', 'active'],

    /*
    | Keys whose values are never written to audit logs or application logs.
    | Matching is case-insensitive and applies to nested arrays.
    */
    'redacted_keys' => [
        'password', 'password_confirmation', 'current_password', 'remember_token',
        'token', 'api_key', 'secret', 'two_factor_secret', 'two_factor_recovery_codes',
        'national_id', 'national_id_encrypted', 'nid', 'birth_registration_no',
        'birth_registration_no_encrypted', 'credentials', 'credentials_encrypted',
        'store_password', 'app_secret', 'signature',
    ],

];
