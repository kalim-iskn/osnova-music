<?php

namespace App\Support;

final class ArtworkUrl
{
    public static function usable(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        if ($url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $filename = strtolower(rawurldecode(basename(is_string($path) ? $path : $url)));

        if (preg_match('/^(?:default(?:[_-].*)?|placeholder(?:[_-].*)?|no[_-]?image(?:[_-].*)?)(?:\.(?:png|jpe?g|webp|gif|svg))?$/i', $filename)) {
            return null;
        }

        return $url;
    }

    public static function prefer(mixed $primary, mixed $fallback = null): ?string
    {
        return self::usable($primary) ?? self::usable($fallback);
    }

    public static function firstUsable(mixed ...$urls): ?string
    {
        foreach ($urls as $url) {
            $usable = self::usable($url);

            if ($usable !== null) {
                return $usable;
            }
        }

        return null;
    }
}
