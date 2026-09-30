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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // IA generativa (integracao-ia.md). A chave só existe no ambiente (RN44); sem driver, usa a falsa.
    'ai' => [
        'driver' => env('AI_DRIVER', 'fake'),
        'base_url' => env('AI_BASE_URL', 'https://api.aimlapi.com/v1'),
        'key' => env('AI_API_KEY'),
        'model_plan' => env('AI_MODEL_PLAN', 'gpt-4o-mini'),
        'model_chat' => env('AI_MODEL_CHAT', 'gpt-4o-mini'),
        'timeout' => (int) env('AI_TIMEOUT', 60),
        // Só E2E: e-mails cuja primeira geração de plano falha (E2E-07).
        'fake_fail_plan_for' => array_values(array_filter(explode(',', (string) env('AI_FAKE_FAIL_PLAN_FOR', '')))),
    ],
];
