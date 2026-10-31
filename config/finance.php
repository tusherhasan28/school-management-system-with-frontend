<?php

return [
    /*
    | Installed payment gateway adapters (class names implementing
    | App\Modules\Finance\Gateways\PaymentGateway). Empty on purpose: bKash,
    | Nagad, Rocket and SSLCommerz adapters are added only once official
    | merchant credentials and current provider documentation are available,
    | and each one must pass sandbox tests before it is listed here.
    */
    'gateways' => [],

    // Pending online payments are checked with the gateway after this many minutes…
    'reconcile_after_minutes' => (int) env('FINANCE_RECONCILE_AFTER_MINUTES', 10),
    // …and marked cancelled if still unconfirmed after this many hours.
    'expire_pending_after_hours' => (int) env('FINANCE_EXPIRE_PENDING_AFTER_HOURS', 48),
];
