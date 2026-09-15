<!-- <?php
defined('DF_ENTRY') || exit(header('HTTP/1.1 404 Not Found'));

/**
 * DoctorFizz Rank Checker live configuration.
 * SerpApi-only build. Keep this file private.
 */
return [
    'site_url' => 'http://localhost:8000',

    'app_key' => 'zfFTp+gR4t/+WS47PD1j+Vlhqw9MPRiy7yMbuG6UrpU=',
    'ip_pepper' => '470c6d6c464ab899d0efa04b6c73c960812bbc3dec8ac7fbf902fe1e652ac5fd',

    'admin_bootstrap' => [
        'username'  => 'admin',
        'pass_hash' => '',
    ],

    'providers' => [
        'serpapi' => [
            'api_key' => 'b2c774871805dd11145b2b5b8b0a9a0f908482fee8832979a52fad1275a39ad7',
        ],
    ],

    'db_path' => null,
    'debug' => false,
]; -->
<?php

defined('DF_ENTRY') || exit(
    header('HTTP/1.1 404 Not Found')
);

$host = getenv('VERCEL_PROJECT_PRODUCTION_URL')
    ?: getenv('VERCEL_URL')
    ?: 'localhost:8000';

return [

    'site_url' => str_starts_with($host, 'localhost')
        ? 'http://' . $host
        : 'https://' . $host,

    'app_key' => (string) getenv('APP_KEY'),

    'ip_pepper' => (string) getenv('IP_PEPPER'),

    'admin_bootstrap' => [
        'username'  => 'admin',
        'pass_hash' => '',
    ],

    'providers' => [
        'serpapi' => [
            'api_key' => (string) getenv('SERPAPI_KEY'),
        ],
    ],

    // Writable location on Vercel
    'db_path' => getenv('VERCEL')
        ? '/tmp/doctorfizz-rank-checker.sqlite'
        : null,

    'debug' => false,
];