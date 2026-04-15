<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supported Locale
    |--------------------------------------------------------------------------
    |
    | This array holds the list of supported locale for Sendportal.
    |
    */
    'locale' => [
        'supported' => [
            'en' => ['name' => 'English', 'native' => 'English'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth Settings
    |--------------------------------------------------------------------------
    |
    | Configure the Sendportal authentication functionality.
    |
    */
    'auth' => [
        'register' => env('SENDPORTAL_REGISTER', false),
        'password_reset' => env('SENDPORTAL_PASSWORD_RESET', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Throttle Settings
    |--------------------------------------------------------------------------
    |
    | Configure the Sendportal API throttling.
    | For more information see https://laravel.com/docs/master/routing#rate-limiting
    |
    */
    'throttle_middleware' => 'throttle:'.env('SENDPORTAL_THROTTLE_MIDDLEWARE', '60,1'),

    /*
    |--------------------------------------------------------------------------
    | Sending Rate Limit
    |--------------------------------------------------------------------------
    |
    | Maximum number of emails to send per hour across all campaigns.
    | Set to 0 (or leave unset) to send at full speed.
    |
    | Recommended values when warming up a new sending domain/IP:
    |   Day 1–2  : 25
    |   Day 3–5  : 50
    |   Day 6–10 : 100
    |   After    : 0 (unlimited)
    |
    */
    'send_rate_per_hour' => (int) env('SEND_RATE_PER_HOUR', 0),
];
