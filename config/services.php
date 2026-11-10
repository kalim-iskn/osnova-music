<?php

return [
    'genius' => [
        'base_url' => env('GENIUS_API_BASE_URL', 'https://genius.com/api'),
        'access_token' => env('GENIUS_ACCESS_TOKEN'),
        'cookie' => env('GENIUS_COOKIE'),
        'timeout' => (int) env('GENIUS_TIMEOUT', 20),
        'cache_ttl' => (int) env('GENIUS_CACHE_TTL', 3600),
        'cache_version' => env('GENIUS_CACHE_VERSION', '20260330-7'),
        'detail_concurrency' => max(1, min(16, (int) env('GENIUS_DETAIL_CONCURRENCY', 14))),
        'album_track_concurrency' => max(1, min(8, (int) env('GENIUS_ALBUM_TRACK_CONCURRENCY', 6))),
        'detail_timeout' => max(5, (int) env('GENIUS_DETAIL_TIMEOUT', 10)),
        'debug_matching' => (bool) env('GENIUS_DEBUG_MATCHING', false),
        'log_channel' => env('GENIUS_LOG_CHANNEL', 'genius_matching'),
        'retry_times' => (int) env('GENIUS_RETRY_TIMES', 4),
        'retry_sleep_ms' => (int) env('GENIUS_RETRY_SLEEP_MS', 1500),
        'songs_per_page' => (int) env('GENIUS_SONGS_PER_PAGE', 50),
        'albums_per_page' => (int) env('GENIUS_ALBUMS_PER_PAGE', 50),
        'max_pages' => (int) env('GENIUS_MAX_PAGES', 100),
    ],
    'muzofond' => [
        'debug_parsing' => (bool) env('MUZOFOND_DEBUG_PARSING', false),
        'log_channel' => env('MUZOFOND_LOG_CHANNEL', 'muzofond'),
    ],
];
