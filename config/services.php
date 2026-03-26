<?php

return [
    'genius' => [
        'base_url' => env('GENIUS_API_BASE_URL', 'https://genius.com/api'),
        'access_token' => env('GENIUS_ACCESS_TOKEN'),
        'timeout' => (int) env('GENIUS_TIMEOUT', 20),
        'cache_ttl' => (int) env('GENIUS_CACHE_TTL', 86400),
        'songs_per_page' => (int) env('GENIUS_SONGS_PER_PAGE', 20),
        'albums_per_page' => (int) env('GENIUS_ALBUMS_PER_PAGE', 20),
        'max_pages' => (int) env('GENIUS_MAX_PAGES', 25),
    ],
];
