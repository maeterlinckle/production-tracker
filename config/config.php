<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * Central configuration. Values come from the environment (.env), never
 * hardcoded credentials in the repository.
 */

$root = dirname(__DIR__);

$storage = Env::get('STORAGE_PATH', 'storage');
if (!preg_match('#^([A-Za-z]:[\\\\/]|/)#', $storage)) {
    $storage = $root . DIRECTORY_SEPARATOR . $storage;
}

$trustedProxies = array_filter(array_map('trim', explode(',', (string) Env::get('TRUSTED_PROXIES', ''))));

return [
    'app' => [
        'name'            => Env::get('APP_NAME', 'Production Tracker'),
        'product'         => Env::get('APP_PRODUCT', 'Production Tracker'),
        'full_name'       => Env::get('APP_FULL_NAME', 'Production Tracker by Junction'),
        'tagline'         => Env::get('APP_TAGLINE', 'Job Shop Order Tracking'),
        'mark'            => Env::get('APP_MARK', 'PT'),
        'vendor'          => Env::get('APP_VENDOR', 'Junction Inc Ltd'),
        'vendor_url'      => Env::get('APP_VENDOR_URL', ''),

        'env'             => Env::get('APP_ENV', 'production'),
        'debug'           => Env::bool('APP_DEBUG', false),
        'url'             => rtrim((string) Env::get('APP_URL', ''), '/'),
        'timezone'        => Env::get('APP_TIMEZONE', 'Europe/London'),
        'currency'        => Env::get('APP_CURRENCY', 'GBP'),
        'currency_symbol' => Env::get('APP_CURRENCY_SYMBOL', '£'),
        'root'            => $root,
        'key'             => Env::get('APP_KEY', ''),
        'trusted_proxies' => $trustedProxies,
    ],

    'database' => [
        'host'     => Env::get('DB_HOST', '127.0.0.1'),
        'port'     => (int) Env::get('DB_PORT', 3306),
        'database' => Env::get('DB_DATABASE', 'production_tracker'),
        'username' => Env::get('DB_USERNAME', ''),
        'password' => Env::get('DB_PASSWORD', ''),
        'charset'  => Env::get('DB_CHARSET', 'utf8mb4'),
    ],

    'session' => [
        'name'          => Env::get('SESSION_NAME', 'pt_session'),
        'lifetime'      => (int) Env::get('SESSION_LIFETIME', 480),
        'secure_cookie' => Env::bool('SESSION_SECURE_COOKIE', true),
    ],

    'storage' => [
        'path'    => $storage,
        'uploads' => $storage . DIRECTORY_SEPARATOR . 'uploads',
        'logs'    => $storage . DIRECTORY_SEPARATOR . 'logs',
    ],

    'mail' => [
        'host'          => Env::get('MAIL_HOST', ''),
        'port'          => (int) Env::get('MAIL_PORT', 587),
        'encryption'    => Env::get('MAIL_ENCRYPTION', 'tls'),
        'username'      => Env::get('MAIL_USERNAME', ''),
        'password'      => Env::get('MAIL_PASSWORD', ''),
        'from_address'  => Env::get('MAIL_FROM_ADDRESS', ''),
        'from_name'     => Env::get('MAIL_FROM_NAME', 'Production Tracker'),
    ],

    // The API and OAuth endpoints are NOT configurable: they are properties of
    // Clear Books, published in their OpenAPI description, and live as constants
    // on App\Services\ClearBooksClient. Only the credentials belong here.
    'clearbooks' => [
        'client_id'    => Env::get('CLEARBOOKS_CLIENT_ID', ''),
        'client_secret' => Env::get('CLEARBOOKS_CLIENT_SECRET', ''),
        'redirect_uri' => Env::get('CLEARBOOKS_REDIRECT_URI', ''),
    ],

    'uploads' => [
        'drawing' => [
            'extensions' => ['pdf', 'dwg', 'dxf', 'step', 'stp', 'iges', 'igs', 'png', 'jpg', 'jpeg'],
            'max_bytes' => 25 * 1024 * 1024,
        ],
        'po' => [
            'extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'doc', 'docx'],
            'max_bytes' => 15 * 1024 * 1024,
        ],
        'photo' => [
            'extensions' => ['png', 'jpg', 'jpeg', 'webp'],
            'max_bytes' => 10 * 1024 * 1024,
        ],
        // Reference material kept against a part: machine settings, setup
        // sheets, inspection reports. Images as well as PDFs, because a
        // photographed settings screen is how most of them actually arrive.
        'part_document' => [
            'extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'txt'],
            'max_bytes' => 25 * 1024 * 1024,
        ],
        // CNC programs, tool lists and CAM files. A deliberately wide list:
        // every control writes its own extension, and the alternative to
        // accepting them is somebody renaming files to get them uploaded.
        'part_tooling' => [
            'extensions' => [
                'nc', 'tap', 'gcode', 'ngc', 'cnc', 'mpf', 'spf', 'eia', 'iso', 'ptp', 'anc', 'min',
                'txt', 'csv', 'zip', 'pdf', 'step', 'stp', 'iges', 'igs', 'dxf', 'dwg',
            ],
            'max_bytes' => 50 * 1024 * 1024,
        ],
        'logo' => [
            'extensions' => ['png', 'jpg', 'jpeg', 'webp'],
            'max_bytes' => 2 * 1024 * 1024,
        ],
    ],

    'login_throttle' => [
        'max_attempts'      => (int) Env::get('LOGIN_THROTTLE_MAX_ATTEMPTS', 5),
        'lockout_minutes'   => (int) Env::get('LOGIN_THROTTLE_LOCKOUT_MINUTES', 15),
    ],
];
