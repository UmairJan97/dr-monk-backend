<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Dr. Monk EMR runtime config (env-driven)
    |--------------------------------------------------------------------------
    */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    'timezone' => env('CLINIC_TIMEZONE', 'America/New_York'),

    'currency' => env('CLINIC_CURRENCY', 'USD'),

    'phi_signed_url_minutes' => (int) env('PHI_SIGNED_URL_MINUTES', 5),

    /*
     | Comma-separated US state codes allowed for NP/Doctor e-prescribe.
     | Use * to allow all states. Empty = allow all (dev default).
     */
    'prescribe_allowed_states' => env('PRESCRIBE_ALLOWED_STATES', '*'),

    'default_license_state' => env('DEFAULT_LICENSE_STATE', 'NY'),

    'sms' => [
        'mode' => env('SMS_MODE', 'sandbox'),
        'from' => env('SMS_FROM', ''),
        'provider' => env('SMS_PROVIDER', 'log'),
    ],

    'surescripts' => [
        'mode' => env('SURESCRIPTS_MODE', 'sandbox'),
        'api_key' => env('SURESCRIPTS_API_KEY'),
        'endpoint' => env('SURESCRIPTS_ENDPOINT', ''),
    ],

    'clearinghouse' => [
        'mode' => env('CLEARINGHOUSE_MODE', 'sandbox'),
        'api_key' => env('CLEARINGHOUSE_API_KEY'),
        'endpoint' => env('CLEARINGHOUSE_ENDPOINT', ''),
        'submitter_id' => env('CLEARINGHOUSE_SUBMITTER_ID', 'DRMNK'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'mode' => env('STRIPE_MODE', 'sandbox'),
    ],

    'ai' => [
        'external_enabled' => (bool) env('AI_EXTERNAL_ENABLED', false),
        'openai_key' => env('OPENAI_API_KEY'),
        'openai_model' => env('OPENAI_MODEL', 'whisper-1'),
        'anthropic_key' => env('ANTHROPIC_API_KEY'),
        'anthropic_model' => env('ANTHROPIC_MODEL', 'claude-3-5-sonnet-latest'),
        'elevenlabs_key' => env('ELEVENLABS_API_KEY'),
        'elevenlabs_voice' => env('ELEVENLABS_VOICE_ID'),
    ],
];
