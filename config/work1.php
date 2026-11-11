<?php

return [
    'highhelp_enabled' => (bool) env('HIGHHELP_ENABLED', false),
    'highhelp_token' => env('HIGHHELP_TOKEN'),
    'highhelp_method' => env('HIGHHELP_METHOD', 'highhelp'),
    'highhelp_merchant_label' => env('HIGHHELP_MERCHANT_LABEL', 'HighHelp'),
    'qrmaster_live_enabled' => (bool) env('QRMASTER_LIVE_ENABLED', false),
    'qrmaster_live_token' => env('QRMASTER_LIVE_TOKEN'),
    'qrmaster_test_enabled' => (bool) env('QRMASTER_TEST_ENABLED', false),
    'qrmaster_test_token' => env('QRMASTER_TEST_TOKEN'),
    'base_url' => rtrim((string) env('WORK1_BASE_URL', 'https://work1.pipay.su'), '/'),
    'username' => env('WORK1_USERNAME', 'cursor'),
    'password' => env('WORK1_PASSWORD', ''),
    'totp_secret' => env('WORK1_TOTP_SECRET', ''),
    'api_token' => env('WORK1_API_TOKEN', ''),
    'cookie_jar' => storage_path('app/work1-cookies.json'),
    'admin_id' => (int) env('WORK1_ADMIN_ID', 1),
    'trader_user_id' => (int) env('WORK1_TRADER_USER_ID', 1),
    'trader_email' => env('WORK1_TRADER_EMAIL', 'tester@test.com'),
    'trader_password' => env('WORK1_TRADER_PASSWORD', ''),
    'trader_cookie_jar' => env('WORK1_TRADER_COOKIE_JAR', storage_path('app/work1-trader-cookies.json')),
];
