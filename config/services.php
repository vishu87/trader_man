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

    'zeapl' => [
        'url'         => env('ZEAPL_API_URL', 'https://api.zeapl.com/api/v1/messages'),
        'token'       => env('ZEAPL_TOKEN'),
        'waba_number' => env('ZEAPL_WABA_NUMBER'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Zerodha Kite Connect. The daily access token is NOT stored here: it is fetched from token_url (hourly by `kite:refresh-token`,
    // and by the Refresh Token / Sync from Kite buttons) and kept in this app's own `app_settings` table (see App\Services\KiteService).
    'kite' => [
        'api_key' => env('KITE_API_KEY'),
        // returns today's access token as plain text
        'token_url' => env('KITE_TOKEN_URL', 'https://hedge.avyaytech.in/get-token'),
    ],

    // ipoalerts.in — IPO list API (key goes in the x-api-key header). Free plan: max page size 1, 6 requests/minute.
    'ipoalerts' => [
        'api_key' => env('IPO_API_KEY'),
        'limit'   => env('IPO_API_LIMIT', 1),   // IPOs per request; raise only if your plan allows more
    ],

    // ChartInk alerts → live trading. The webhook URL is <site>/api/chartink/<secret>; without a secret the webhook refuses everything.
    'chartink' => [
        'webhook_secret' => env('CHARTINK_WEBHOOK_SECRET'),
    ],

    // python/candle_storage.py — defaults to the project's own virtualenv
    'candles' => [
        'python' => env('PYTHON_BIN'),
    ],

    // Master switch for AI trade signals + paper trading. false = the Analyse button, the chart popup's Trade panel, the
    // Trade Signals page and its menu item are all off, and nothing can create a signal or a paper trade.
    'trade_signals' => [
        'enabled' => (bool) env('TRADE_SIGNALS_ENABLED', true),
    ],

    // The trade-signal analysis (App\Services\Trading\SignalAnalyst) can use OpenAI or Claude.
    // AI_PROVIDER = openai | anthropic; left empty it uses whichever key is set (OpenAI first).
    'ai' => [
        'provider'    => env('AI_PROVIDER'),
        // spending guard: analyses are refused once this many have been made today
        'max_per_day' => (int) env('AI_MAX_ANALYSES_PER_DAY', env('ANTHROPIC_MAX_ANALYSES_PER_DAY', 60)),
    ],

    'openai' => [
        'api_key'          => env('OPENAI_API_KEY'),
        'model'            => env('OPENAI_MODEL', 'gpt-5'),
        // reasoning models only (gpt-5, o-series): low | medium | high. Leave empty for a non-reasoning model such as gpt-4o.
        'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'medium'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model'   => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
    ],

];
