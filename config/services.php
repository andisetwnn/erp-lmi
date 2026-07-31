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

    'tesseract' => [
        // Path absolut ke binary tesseract. Kalau kosong, pakai default dari PATH OS.
        // Windows: "C:\Program Files\Tesseract-OCR\tesseract.exe"
        // Linux:   "/usr/bin/tesseract"
        'path' => env('TESSERACT_PATH'),
    ],

    'ocr' => [
        // 'gemini' atau 'tesseract'. Kalau gemini gagal, fallback ke tesseract.
        'provider' => env('OCR_PROVIDER', 'tesseract'),
    ],

    'gemini' => [
        // API key dari https://aistudio.google.com/apikey (gratis tier 1500/hari)
        'api_key' => env('GEMINI_API_KEY'),
        // Model — 'gemini-2.0-flash' paling cepat & murah, cocok untuk OCR
        'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
    ],

];
