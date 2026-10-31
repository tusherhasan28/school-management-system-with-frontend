<?php

return [
    // Renewal invoices are issued this many days before the current period ends.
    'invoice_lead_days' => (int) env('BILLING_INVOICE_LEAD_DAYS', 7),

    // Shown on platform invoices and the school's subscription page (bank account,
    // bKash merchant number, ...). Set per deployment; nothing is hard-coded.
    'payment_instructions' => env('BILLING_PAYMENT_INSTRUCTIONS'),

    // Platform support access to a school's data: longest grant a school may approve.
    'support_access_max_hours' => (int) env('BILLING_SUPPORT_ACCESS_MAX_HOURS', 24),
];
