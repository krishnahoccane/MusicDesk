<?php

return [
    // Requests per minute for one token (all endpoints together).
    'rate_limit' => 120,

    // Failed authentication attempts per minute from one IP address before it is slowed down.
    'auth_failures_per_minute' => 20,

    // Page size limits for list endpoints.
    'per_page' => 25,
    'max_per_page' => 100,

    // Expiry choices offered when a token is created (days; null = no expiry).
    'expiry_options' => [30, 90, 180, 365, null],
    'default_expiry' => 90,

    // At most this many active tokens per user.
    'max_tokens_per_user' => 25,
];
