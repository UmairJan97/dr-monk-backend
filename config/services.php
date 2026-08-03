<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ai' => [
        'external_enabled' => env('AI_EXTERNAL_ENABLED', false),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'whisper-1'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-3-5-sonnet-latest'),
    ],

    'elevenlabs' => [
        'key' => env('ELEVENLABS_API_KEY'),
        'voice' => env('ELEVENLABS_VOICE_ID'),
    ],

    'surescripts' => [
        'mode' => env('SURESCRIPTS_MODE', 'sandbox'),
        'api_key' => env('SURESCRIPTS_API_KEY'),
        'endpoint' => env('SURESCRIPTS_ENDPOINT'),
    ],

    'clearinghouse' => [
        'mode' => env('CLEARINGHOUSE_MODE', 'sandbox'),
        'api_key' => env('CLEARINGHOUSE_API_KEY'),
        'endpoint' => env('CLEARINGHOUSE_ENDPOINT'),
        'submitter_id' => env('CLEARINGHOUSE_SUBMITTER_ID', 'DRMNK'),
    ],

    'sms' => [
        'mode' => env('SMS_MODE', 'sandbox'),
        'from' => env('SMS_FROM'),
        'provider' => env('SMS_PROVIDER', 'log'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'mode' => env('STRIPE_MODE', 'sandbox'),
    ],

];
