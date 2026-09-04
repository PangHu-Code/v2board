<?php

return [
    'risk_policy_secret' => env('SUBMESH_RISK_POLICY_SECRET', ''),
    'risk_policy_path' => env('SUBMESH_RISK_POLICY_PATH')
        ?: storage_path('app/submesh/risk-policy.json'),
    'risk_policy_max_bytes' => 1024 * 1024,
    'risk_policy_max_emails' => 10000,
    'risk_policy_clock_skew' => 300,
];
