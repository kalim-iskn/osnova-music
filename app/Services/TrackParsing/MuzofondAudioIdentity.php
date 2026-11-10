<?php

namespace App\Services\TrackParsing;

use App\Models\Track;
use App\Services\TrackParsing\DTO\ParsedTrack;

final class MuzofondAudioIdentity
{
    /**
     * Return stable keys for the Muzofond media ID and the decoded media URL.
     * Metadata such as title, artist, album, and duration is deliberately excluded.
     *
     * @return string[]
     */
    public static function keys(string $audioUrl, ?string $audioFileId = null): array
    {
        $keys = [];
        $audioFileId = self::fileIdFromUrl($audioUrl, $audioFileId);

        if ($audioFileId !== null) {
            $keys[] = 'muzofond-id:' . $audioFileId;
        }

        $canonicalUrl = self::canonicalUrl($audioUrl);

        if ($canonicalUrl !== '') {
            $keys[] = 'muzofond-url:' . $canonicalUrl;
        }

        return array_values(array_unique($keys));
    }

    public static function checkpointKey(string $audioUrl, ?string $audioFileId = null, string $title = '', int $durationSeconds = 0): string
    {
        $identity = self::keys($audioUrl, $audioFileId)[0] ?? null;

        return hash('sha256', $identity ?? implode('|', [
            $audioUrl,
            $title,
            (string) $durationSeconds,
        ]));
    }

    /**
     * Recover the media ID from the player data-id or the encoded media filename.
     */
    public static function fileIdFromUrl(string $audioUrl, ?string $declaredId = null): ?string
    {
        $declaredId = trim((string) $declaredId);

        if ($declaredId !== '' && preg_match('/^\d{1,100}$/', $declaredId) === 1) {
            return $declaredId;
        }

        $mediaUrl = self::decodeWrappedUrl($audioUrl) ?? trim($audioUrl);
        $path = (string) (parse_url($mediaUrl, PHP_URL_PATH) ?? '');
        $filename = basename(rawurldecode($path));

        if (preg_match('/^(?<id>\d+)\.(?:mp3|m4a|aac|wav|flac|ogg|opus)$/i', $filename, $matches) === 1) {
            return $matches['id'];
        }

        return null;
    }

    /**
     * @return string[]
     */
    public static function keysForTrack(Track $track): array
    {
        $keys = [];
        $storedId = trim((string) ($track->muzofond_audio_id ?? ''));

        foreach ([$track->audio_url, $track->original_link] as $url) {
            $url = trim((string) $url);

            if ($url === '') {
                continue;
            }

            array_push($keys, ...self::keys($url, $storedId !== '' ? $storedId : null));
        }

        return array_values(array_unique($keys));
    }

    public static function matchesParsedTrack(Track $track, ParsedTrack $parsedTrack): bool
    {
        return array_intersect(
            self::keysForTrack($track),
            self::keys($parsedTrack->audioUrl, $parsedTrack->audioFileId),
        ) !== [];
    }

    public static function canonicalUrl(string $audioUrl): string
    {
        $audioUrl = trim(html_entity_decode($audioUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($audioUrl === '') {
            return '';
        }

        $audioUrl = self::decodeWrappedUrl($audioUrl) ?? $audioUrl;
        $parts = parse_url($audioUrl);

        if (! is_array($parts) || empty($parts['host'])) {
            return $audioUrl;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $authority = $host . ($port !== null ? ':' . $port : '');
        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $scheme . '://' . $authority . $path . $query;
    }

    private static function decodeWrappedUrl(string $audioUrl): ?string
    {
        $parts = parse_url(trim($audioUrl));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! is_array($parts)
            || ($host !== 'muzofond.fm' && ! str_ends_with($host, '.muzofond.fm'))) {
            return null;
        }

        $payload = rawurldecode(trim((string) ($parts['path'] ?? ''), '/'));

        if ($payload === '' || preg_match('#^[A-Za-z0-9+/_=-]+$#', $payload) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);

        if (! is_string($decoded)) {
            return null;
        }

        $decodedParts = parse_url(trim($decoded));
        $scheme = strtolower((string) ($decodedParts['scheme'] ?? ''));

        if (! is_array($decodedParts)
            || ! in_array($scheme, ['http', 'https'], true)
            || empty($decodedParts['host'])) {
            return null;
        }

        return trim($decoded);
    }
}
