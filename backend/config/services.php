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

    // 내부 AI 서비스(FastAPI). 브라우저에는 노출하지 않고 Laravel에서만 호출한다.
    'ai' => [
        'url' => env('AI_SERVICE_URL', 'http://ai:8001'),
        'token' => env('AI_INTERNAL_TOKEN'),
        'timeout' => (int) env('AI_TIMEOUT', 30),
        // 카드뉴스 렌더는 AI 배경 삽화 생성(최대 45초)을 기다릴 수 있어 따로 둔다.
        'image_timeout' => (int) env('AI_IMAGE_TIMEOUT', 90),
    ],

];
