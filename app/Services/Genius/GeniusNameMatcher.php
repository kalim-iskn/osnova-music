<?php

namespace App\Services\Genius;

use Illuminate\Support\Str;

class GeniusNameMatcher
{
    public static function forceUtf8(?string $value): string
    {
        $value = (string) ($value ?? '');

        if ($value === '') {
            return '';
        }

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        foreach (['Windows-1251', 'CP1251', 'ISO-8859-1'] as $encoding) {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $value);

            if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                if (self::looksReadable($converted)) {
                    return $converted;
                }
            }

            $converted = @mb_convert_encoding($value, 'UTF-8', $encoding);

            if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                if (self::looksReadable($converted)) {
                    return $converted;
                }
            }
        }

        $cleaned = self::stripInvalidUtf8Sequences($value);

        if ($cleaned !== '') {
            return $cleaned;
        }

        $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

        if (is_string($cleaned) && $cleaned !== '' && mb_check_encoding($cleaned, 'UTF-8')) {
            return $cleaned;
        }

        return '';
    }

    public static function sanitizeForDatabase(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::sanitizeForDatabase($item);
            }

            return $value;
        }

        if (is_string($value)) {
            return self::forceUtf8($value);
        }

        return $value;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tags
     * @return string[]
     */
    public static function extractGenres(array $tags): array
    {
        return collect($tags)
            ->filter(fn ($tag) => is_array($tag))
            ->map(function (array $tag): array {
                return [
                    'raw' => (string) ($tag['name'] ?? ''),
                    'clean' => self::storageValue((string) ($tag['name'] ?? '')),
                ];
            })
            ->filter(fn (array $tag) => $tag['clean'] !== '')
            ->reject(function (array $tag): bool {
                $normalized = self::normalizeLoose($tag['clean']);

                return in_array($normalized, [
                        'russkii',
                        'russia',
                        'rossiia',
                        'russian',
                        'english',
                        'angliiskii',
                        'ukraine',
                        'ukraina',
                        'kazakhstan',
                    ], true) || str_contains(self::normalizeLoose($tag['raw']), 'in russian')
                    || str_contains(self::normalizeLoose($tag['raw']), 'in english');
            })
            ->map(fn (array $tag) => $tag['clean'])
            ->unique()
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $tags
     */
    public static function resolveLanguageCode(array $tags, ?string $fallback = null): ?string
    {
        foreach ($tags as $tag) {
            if (! is_array($tag)) {
                continue;
            }

            $name = self::normalizeLoose((string) ($tag['name'] ?? ''));

            if (str_contains($name, 'in russian') || str_contains($name, 'русский')) {
                return 'ru';
            }

            if (str_contains($name, 'in english') || str_contains($name, 'англий')) {
                return 'en';
            }
        }

        if ($fallback === null || trim($fallback) === '') {
            return null;
        }

        return Str::lower(trim(self::forceUtf8($fallback)));
    }

    public static function storageValue(string $value): string
    {
        $value = self::forceUtf8($value);
        $value = self::cleanWhitespace($value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace_callback('/\s*[\(\[\{]([^\)\]\}]*)[\)\]\}]/u', function (array $matches): string {
            $inner = self::cleanWhitespace((string) ($matches[1] ?? ''));

            if ($inner === '') {
                return '';
            }

            if (preg_match('/[\p{Cyrillic}\d]/u', $inner) === 1) {
                return ' (' . $inner . ')';
            }

            if (preg_match('/^[\p{Latin}\s\-\'"`.,&:!?\/#+]+$/u', $inner) === 1) {
                return '';
            }

            return ' (' . $inner . ')';
        }, $value) ?? $value;

        $value = preg_replace('/\s{2,}/u', ' ', $value) ?? $value;

        return trim($value, " \t\n\r\0\x0B-–—");
    }

    /**
     * @param  string[]  $candidates
     */
    public static function bestArtistScore(string $needle, array $candidates): float
    {
        $scores = collect($candidates)
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $candidate) => self::score($needle, $candidate))
            ->values()
            ->all();

        return $scores === [] ? 0.0 : max($scores);
    }

    public static function score(string $left, string $right): float
    {
        $variantsLeft = self::variants($left);
        $variantsRight = self::variants($right);
        $best = 0.0;

        foreach ($variantsLeft as $variantLeft) {
            foreach ($variantsRight as $variantRight) {
                if ($variantLeft === '' || $variantRight === '') {
                    continue;
                }

                if ($variantLeft === $variantRight) {
                    return 1.0;
                }

                similar_text($variantLeft, $variantRight, $percent);
                $best = max($best, $percent / 100);

                if (str_contains($variantLeft, $variantRight) || str_contains($variantRight, $variantLeft)) {
                    $best = max($best, 0.92);
                }
            }
        }

        return round($best, 4);
    }

    public static function canonicalTrack(string $value): string
    {
        $value = self::storageValue($value);
        $value = preg_replace('/\b(snippet|romanized|romanization|translation|translated|lyrics)\b/iu', '', $value) ?? $value;
        $value = preg_replace('/\s*[\(\[\{].*?[\)\]\}]/u', '', $value) ?? $value;
        $value = self::cleanWhitespace($value);
        $value = Str::lower($value);
        $value = str_replace(['ё'], ['е'], $value);
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s{2,}/u', ' ', $value) ?? $value;

        return trim($value);
    }

    public static function normalizeLoose(string $value): string
    {
        $value = self::cleanWhitespace($value);
        $value = Str::lower($value);
        $value = str_replace(['ё'], ['е'], $value);
        $value = Str::ascii($value);
        $value = Str::lower($value);
        $value = str_replace(['ph', 'th', 'w', 'ya', 'yu', 'yo'], ['f', 't', 'v', 'ia', 'iu', 'e'], $value);
        $value = preg_replace('/[^a-z0-9\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s{2,}/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return string[]
     */
    private static function variants(string $value): array
    {
        $storage = self::storageValue($value);
        $canonical = self::canonicalTrack($value);
        $loose = self::normalizeLoose($storage);

        return collect([$storage, $canonical, $loose])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private static function cleanWhitespace(string $value): string
    {
        $value = self::forceUtf8($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = self::forceUtf8($value);
        $value = str_replace(['“', '”', '„', '’', '`'], '"', $value);
        $value = str_replace(['–', '—'], '-', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private static function looksReadable(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $letters = preg_match_all('/[\p{L}]/u', $value);
        $readable = preg_match_all('/[\p{Latin}\p{Cyrillic}\d\s\-\'"`.,&:!?\/#+()\[\]{}]/u', $value);

        if (! is_int($letters) || $letters === 0) {
            return true;
        }

        return is_int($readable) && ($readable / $letters) >= 0.7;
    }

    private static function stripInvalidUtf8Sequences(string $value): string
    {
        $result = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $byte1 = ord($value[$i]);

            if ($byte1 <= 0x7F) {
                $result .= $value[$i];
                continue;
            }

            if ($byte1 >= 0xC2 && $byte1 <= 0xDF) {
                if ($i + 1 < $length) {
                    $byte2 = ord($value[$i + 1]);

                    if ($byte2 >= 0x80 && $byte2 <= 0xBF) {
                        $result .= $value[$i] . $value[$i + 1];
                        $i += 1;
                    }
                }

                continue;
            }

            if ($byte1 === 0xE0) {
                if ($i + 2 < $length) {
                    $byte2 = ord($value[$i + 1]);
                    $byte3 = ord($value[$i + 2]);

                    if ($byte2 >= 0xA0 && $byte2 <= 0xBF && $byte3 >= 0x80 && $byte3 <= 0xBF) {
                        $result .= $value[$i] . $value[$i + 1] . $value[$i + 2];
                        $i += 2;
                    }
                }

                continue;
            }

            if (($byte1 >= 0xE1 && $byte1 <= 0xEC) || $byte1 === 0xEE || $byte1 === 0xEF) {
                if ($i + 2 < $length) {
                    $byte2 = ord($value[$i + 1]);
                    $byte3 = ord($value[$i + 2]);

                    if ($byte2 >= 0x80 && $byte2 <= 0xBF && $byte3 >= 0x80 && $byte3 <= 0xBF) {
                        $result .= $value[$i] . $value[$i + 1] . $value[$i + 2];
                        $i += 2;
                    }
                }

                continue;
            }

            if ($byte1 == 0xED) {
                if ($i + 2 < $length) {
                    $byte2 = ord($value[$i + 1]);
                    $byte3 = ord($value[$i + 2]);

                    if ($byte2 >= 0x80 && $byte2 <= 0x9F && $byte3 >= 0x80 && $byte3 <= 0xBF) {
                        $result .= $value[$i] . $value[$i + 1] . $value[$i + 2];
                        $i += 2;
                    }
                }

                continue;
            }

            if ($byte1 === 0xF0) {
                if ($i + 3 < $length) {
                    $byte2 = ord($value[$i + 1]);
                    $byte3 = ord($value[$i + 2]);
                    $byte4 = ord($value[$i + 3]);

                    if ($byte2 >= 0x90 && $byte2 <= 0xBF && $byte3 >= 0x80 && $byte3 <= 0xBF && $byte4 >= 0x80 && $byte4 <= 0xBF) {
                        $result .= $value[$i] . $value[$i + 1] . $value[$i + 2] . $value[$i + 3];
                        $i += 3;
                    }
                }

                continue;
            }

            if ($byte1 >= 0xF1 && $byte1 <= 0xF3) {
                if ($i + 3 < $length) {
                    $byte2 = ord($value[$i + 1]);
                    $byte3 = ord($value[$i + 2]);
                    $byte4 = ord($value[$i + 3]);

                    if ($byte2 >= 0x80 && $byte2 <= 0xBF && $byte3 >= 0x80 && $byte3 <= 0xBF && $byte4 >= 0x80 && $byte4 <= 0xBF) {
                        $result .= $value[$i] . $value[$i + 1] . $value[$i + 2] . $value[$i + 3];
                        $i += 3;
                    }
                }

                continue;
            }

            if ($byte1 === 0xF4) {
                if ($i + 3 < $length) {
                    $byte2 = ord($value[$i + 1]);
                    $byte3 = ord($value[$i + 2]);
                    $byte4 = ord($value[$i + 3]);

                    if ($byte2 >= 0x80 && $byte2 <= 0x8F && $byte3 >= 0x80 && $byte3 <= 0xBF && $byte4 >= 0x80 && $byte4 <= 0xBF) {
                        $result .= $value[$i] . $value[$i + 1] . $value[$i + 2] . $value[$i + 3];
                        $i += 3;
                    }
                }
            }
        }

        return $result;
    }
}
