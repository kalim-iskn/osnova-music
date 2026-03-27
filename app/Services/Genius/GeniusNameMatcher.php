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
            $value = self::stripInvalidUtf8Sequences($value) ?: $value;

            return self::repairMojibake($value);
        }

        foreach (['Windows-1251', 'CP1251', 'ISO-8859-1'] as $encoding) {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $value);

            if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                $converted = self::repairMojibake($converted);

                if (self::looksReadable($converted)) {
                    return $converted;
                }
            }

            $converted = @mb_convert_encoding($value, 'UTF-8', $encoding);

            if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                $converted = self::repairMojibake($converted);

                if (self::looksReadable($converted)) {
                    return $converted;
                }
            }
        }

        $cleaned = self::stripInvalidUtf8Sequences($value);

        if ($cleaned !== '') {
            return self::repairMojibake($cleaned);
        }

        $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

        if (is_string($cleaned) && $cleaned !== '' && mb_check_encoding($cleaned, 'UTF-8')) {
            return self::repairMojibake($cleaned);
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
            return self::repairMojibake(self::forceUtf8($value));
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
        $value = self::repairMojibake(self::forceUtf8($value));
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\x{FEFF}/u', '', $value) ?? $value;
        $value = self::cleanWhitespace($value);

        if ($value === '') {
            return '';
        }

        $value = strtr($value, [
            '[' => '(',
            ']' => ')',
            '{' => '(',
            '}' => ')',
        ]);
        $value = preg_replace('/\s{2,}/u', ' ', $value) ?? $value;

        return trim($value, " \t\n\r\0\x0B-–—");
    }

    public static function cleanDescriptionPreview(?string $value): ?string
    {
        $value = self::repairMojibake(self::forceUtf8((string) ($value ?? '')));
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);
        $value = self::cleanWhitespace($value);

        if ($value === '') {
            return null;
        }

        $value = preg_replace('/\b(?:Read More|More on Genius|Подробнее)\b.*$/iu', '', $value) ?? $value;
        $value = self::trimSocialTail($value);
        $value = preg_replace('/\s{2,}/u', ' ', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B-–—,.!?:;");

        return $value !== '' ? $value : null;
    }

    /**
     * @param  string[]  $candidates
     */
    public static function bestArtistScore(string $needle, array $candidates): float
    {
        $scores = collect($candidates)
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $candidate) => self::artistScore($needle, $candidate))
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
        $value = self::repairMojibake(self::cleanWhitespace($value));
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


    /**
     * @return string[]
     */
    public static function artistSearchQueries(string $value): array
    {
        $storage = self::storageValue($value);
        $variants = [$storage];

        if (preg_match('/^(?P<base>.*?)\s*[\(\[\{].*?[\)\]\}]\s*$/u', $storage, $matches) === 1) {
            $base = self::cleanWhitespace((string) ($matches['base'] ?? ''));

            if ($base !== '') {
                $variants[] = $base;
            }
        }

        if (preg_match_all('/[\(\[\{]([^\)\]\}]*)[\)\]\}]/u', $storage, $matches) === 1 || !empty($matches[1])) {
            foreach ($matches[1] as $inner) {
                $clean = self::cleanWhitespace((string) $inner);

                if ($clean !== '') {
                    $variants[] = $clean;
                }
            }
        }

        foreach (array_values($variants) as $variant) {
            $transliterated = self::transliterateCyrillic($variant);

            if ($transliterated !== '') {
                $variants[] = $transliterated;
            }

            $reverseTransliterated = self::transliterateLatinToCyrillic($variant);

            if ($reverseTransliterated !== '') {
                $variants[] = $reverseTransliterated;
            }
        }

        return collect($variants)
            ->map(fn (string $item) => self::cleanWhitespace($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private static function transliterateCyrillic(string $value): string
    {
        $value = self::cleanWhitespace($value);

        if ($value === '') {
            return '';
        }

        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
            'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
            'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];

        $normalized = Str::lower($value);
        $normalized = strtr($normalized, $map);
        $normalized = Str::ascii($normalized);
        $normalized = preg_replace('/[^a-z0-9\s-]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s{2,}/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private static function transliterateLatinToCyrillic(string $value): string
    {
        $value = self::cleanWhitespace($value);

        if ($value === '' || preg_match('/\p{Cyrillic}/u', $value) === 1) {
            return '';
        }

        $normalized = Str::lower(Str::ascii($value));
        $normalized = preg_replace('/[^a-z0-9\s-]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s{2,}/u', ' ', $normalized) ?? $normalized;

        $map = [
            'sch' => 'щ',
            'sh' => 'ш',
            'ch' => 'ч',
            'ts' => 'ц',
            'zh' => 'ж',
            'yu' => 'ю',
            'ya' => 'я',
            'yo' => 'ё',
            'ye' => 'е',
            'kh' => 'х',
            'a' => 'а',
            'b' => 'б',
            'c' => 'к',
            'd' => 'д',
            'e' => 'е',
            'f' => 'ф',
            'g' => 'г',
            'h' => 'х',
            'i' => 'и',
            'j' => 'дж',
            'k' => 'к',
            'l' => 'л',
            'm' => 'м',
            'n' => 'н',
            'o' => 'о',
            'p' => 'п',
            'q' => 'к',
            'r' => 'р',
            's' => 'с',
            't' => 'т',
            'u' => 'у',
            'v' => 'в',
            'w' => 'в',
            'x' => 'кс',
            'y' => 'й',
            'z' => 'з',
        ];

        uksort($map, fn (string $left, string $right) => strlen($right) <=> strlen($left));

        return trim(strtr($normalized, $map));
    }

    private static function repairMojibake(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $value = self::normalizeCommonBrokenSymbols($value);

        if (! self::looksLikeMojibake($value)) {
            return $value;
        }

        $best = $value;
        $bestScore = self::readabilityScore($value);
        $candidates = [$value];

        foreach (['Windows-1251', 'CP1251', 'Windows-1252', 'ISO-8859-1'] as $encoding) {
            $step = @iconv('UTF-8', $encoding . '//IGNORE', $value);

            if (is_string($step) && $step !== '') {
                $fixed = @iconv($encoding, 'UTF-8//IGNORE', $step);

                if (is_string($fixed) && $fixed !== '') {
                    $candidates[] = $fixed;
                }
            }
        }

        $current = $value;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $step = @iconv('UTF-8', 'Windows-1251//IGNORE', $current);

            if (! is_string($step) || $step === '') {
                break;
            }

            $fixed = @iconv('Windows-1251', 'UTF-8//IGNORE', $step);

            if (! is_string($fixed) || $fixed === '') {
                break;
            }

            $candidates[] = $fixed;
            $current = $fixed;
        }

        foreach ($candidates as $candidate) {
            $candidate = self::normalizeCommonBrokenSymbols((string) $candidate);
            $candidate = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $candidate = str_replace(['“', '”', '„', '’', '`'], '"', $candidate);
            $candidate = str_replace(['–', '—'], '-', $candidate);
            $candidate = preg_replace('/\s+/u', ' ', $candidate) ?? $candidate;
            $candidate = trim($candidate);
            $score = self::readabilityScore($candidate);

            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return self::looksReadable($best) ? $best : $value;
    }

    private static function artistVariants(string $value): array
    {
        $variants = array_merge(
            [self::storageValue($value)],
            self::artistSearchQueries($value),
        );

        return collect($variants)
            ->map(fn (string $variant) => self::normalizeLoose($variant))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private static function artistScore(string $left, string $right): float
    {
        $leftVariants = self::artistVariants($left);
        $rightVariants = self::artistVariants($right);
        $best = 0.0;

        foreach ($leftVariants as $leftVariant) {
            foreach ($rightVariants as $rightVariant) {
                if ($leftVariant === '' || $rightVariant === '') {
                    continue;
                }

                if ($leftVariant === $rightVariant) {
                    return 1.0;
                }

                if (preg_match('/(^|\s)' . preg_quote($leftVariant, '/') . '(\s|$)/u', $rightVariant) === 1) {
                    $best = max($best, 0.96);
                }

                if (preg_match('/(^|\s)' . preg_quote($rightVariant, '/') . '(\s|$)/u', $leftVariant) === 1) {
                    $best = max($best, 0.94);
                }

                similar_text($leftVariant, $rightVariant, $percent);
                $similarity = $percent / 100;

                $lengthDelta = abs(strlen($leftVariant) - strlen($rightVariant));

                if ($lengthDelta >= 2 && (str_starts_with($leftVariant, $rightVariant) || str_starts_with($rightVariant, $leftVariant))) {
                    $similarity = min($similarity, 0.79);
                }

                $best = max($best, $similarity);
            }
        }

        return round($best, 4);
    }

    private static function cleanWhitespace(string $value): string
    {
        $value = self::forceUtf8($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = self::forceUtf8($value);
        $value = self::normalizeCommonBrokenSymbols($value);
        $value = str_replace(['“', '”', '„', '’', '`'], '"', $value);
        $value = str_replace(['–', '—'], '-', $value);
        $value = preg_replace('/\x{FEFF}/u', '', $value) ?? $value;
        $value = preg_replace('/[[:cntrl:]]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }


    private static function normalizeCommonBrokenSymbols(string $value): string
    {
        return strtr($value, [
            'âˆš' => '√',
            '€љ' => '√',
            'â€“' => '-',
            'â€”' => '-',
            'â€˜' => "'",
            'â€™' => "'",
            'â€œ' => '"',
            'â€' => '"',
            'Â ' => ' ',
            'Â«' => '«',
            'Â»' => '»',
            'Â°' => '°',
        ]);
    }

    private static function trimSocialTail(string $value): string
    {
        $tokens = [
            'Дискография',
            'Сообщество ВКонтакте',
            'Страница ВКонтакте',
            'ВКонтакте',
            'Телеграм-канал',
            'Telegram-канал',
            'YouTube',
            'SoundCloud',
            'TikTok',
            'Instagram',
            'Twitter',
            'Facebook',
            'Spotify',
            'Apple Music',
            'Official Website',
            'Официальный сайт',
        ];

        $pattern = '/(?:' . implode('|', array_map(fn (string $token) => preg_quote($token, '/'), $tokens)) . ')/iu';
        preg_match_all($pattern, $value, $matches, PREG_OFFSET_CAPTURE);

        $tokenMatches = $matches[0] ?? [];

        if (count($tokenMatches) < 2) {
            return $value;
        }

        $firstMatch = $tokenMatches[0] ?? null;

        if (! is_array($firstMatch) || ! isset($firstMatch[1])) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, (int) $firstMatch[1]));
    }

    private static function looksLikeMojibake(string $value): bool
    {
        return preg_match('/(?:Р[А-Яа-яЁёA-Za-z]|С[А-Яа-яЁёA-Za-z]|Ð.|Ñ.|Ã.|Â.|â.|€.){2,}/u', $value) === 1;
    }

    private static function readabilityScore(string $value): float
    {
        if ($value === '') {
            return 0.0;
        }

        $letters = preg_match_all('/[\p{L}]/u', $value);
        $readable = preg_match_all('/[\p{Latin}\p{Cyrillic}\d\s\-\'"`.,&:!?\/#+()\[\]{}]/u', $value);
        $cyrillic = preg_match_all('/\p{Cyrillic}/u', $value);

        if (! is_int($letters) || $letters === 0) {
            return 0.0;
        }

        return ((is_int($readable) ? $readable : 0) / $letters) + ((is_int($cyrillic) ? $cyrillic : 0) / max(1, $letters));
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
