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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'frontend' => [
        'url' => env('FRONTEND_URL'),
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

    'ai' => [
        'provider' => env('AI_PROVIDER'),
        'base_url' => env('AI_BASE_URL'),
        'allowed_hosts' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('AI_ALLOWED_HOSTS', '')),
        ))),
        'chat_endpoint' => env('AI_CHAT_ENDPOINT'),
        'model' => env('AI_MODEL'),
        'api_key' => env('AI_API_KEY'),
        'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 2048),
        'max_response_bytes' => (int) env('AI_MAX_RESPONSE_BYTES', 1048576),
        'max_generated_text_chars' => (int) env('AI_MAX_GENERATED_TEXT_CHARS', 20000),
    ],

];
