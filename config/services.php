<?php

return [
    'genius' => [
        'base_url' => env('GENIUS_API_BASE_URL', 'https://genius.com/api'),
        'access_token' => env('GENIUS_ACCESS_TOKEN'),
        'timeout' => (int) env('GENIUS_TIMEOUT', 20),
        'cache_ttl' => (int) env('GENIUS_CACHE_TTL', 86400),
        'cache_version' => env('GENIUS_CACHE_VERSION', '20260330-1'),
        'debug_matching' => (bool) env('GENIUS_DEBUG_MATCHING', false),
        'songs_per_page' => (int) env('GENIUS_SONGS_PER_PAGE', 50),
        'albums_per_page' => (int) env('GENIUS_ALBUMS_PER_PAGE', 50),
        'max_pages' => (int) env('GENIUS_MAX_PAGES', 100),
    ],
    'muzofond' => [
        'debug_parsing' => (bool) env('MUZOFOND_DEBUG_PARSING', false),
    ],
];
