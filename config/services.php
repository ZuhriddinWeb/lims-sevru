<?php

return [
    'postmark' => ['token' => env('POSTMARK_TOKEN')],
    'ses' => ['key' => env('AWS_ACCESS_KEY_ID'), 'secret' => env('AWS_SECRET_ACCESS_KEY'), 'region' => env('AWS_DEFAULT_REGION', 'us-east-1')],
    'resend' => ['key' => env('RESEND_KEY')],
    'slack' => ['notifications' => ['bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'), 'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL')]],
    // AI yordamchi uchun (suv/havo monitoring tahlili)
    'ai' => [
        'provider' => env('AI_PROVIDER', 'local'),
        'api_key'  => env('AI_API_KEY'),
        'base_url' => env('AI_BASE_URL'),
        'model'    => env('AI_MODEL', 'claude-sonnet-4-6'),
    ],
];
