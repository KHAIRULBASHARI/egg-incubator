<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'device' => [
        'key' => env('DEVICE_KEY'),
    ],

    'candling' => [
        'api_url' => env('CANDLING_API_URL', 'http://127.0.0.1:5000/api/predict'),
        'llm_url' => env('LLM_ROUTER_URL', 'https://router.walker880.my.id/v1/chat/completions'),
        'llm_key' => env('LLM_ROUTER_KEY', 'sk-87fb2556b4e913ec-035612-de0e95de'),
        'llm_model' => env('LLM_ROUTER_MODEL', 'antigravity/gemini-3.8-flash-tiered'),
    ],

];
