<?php
defined('DF_ENTRY') || exit(header('HTTP/1.1 404 Not Found'));

/**
 * DoctorFizz Rank Checker configuration, SerpApi-only build.
 *
 * Copy to config.php and fill in the values. Keep this file server-side.
 */
return [
    'site_url' => 'https://itzfizz.com/keyword-rank-checker',

    // Generate with:
    // php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
    'app_key' => 'REPLACE_WITH_BASE64_32_BYTES',

    // Generate with:
    // php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    'ip_pepper' => 'REPLACE_WITH_A_LONG_RANDOM_STRING',

    // Optional admin bootstrap. Leave pass_hash blank if you do not need admin access yet.
    // php -r "echo password_hash('your-password', PASSWORD_ARGON2ID), PHP_EOL;"
    'admin_bootstrap' => [
        'username'  => 'admin',
        'pass_hash' => '',
    ],

    // The only search provider used by this build.
    'providers' => [
        'serpapi' => [
            'api_key' => 'YOUR_SERPAPI_API_KEY',
        ],
    ],

    'db_path' => null,
    'debug' => false,
];
