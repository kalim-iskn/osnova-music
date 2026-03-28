<?php

namespace App\Services\TrackParsing;

use App\Services\Genius\GeniusNameMatcher;
use App\Services\TrackParsing\DTO\ParsedArtistPage;
use App\Services\TrackParsing\DTO\ParsedTrack;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MuzofondTrackParser
{
    private const BASE_URL = 'https://muzofond.fm';

    /**
     * @var string[]
     */
    private array $knownGenres = [
        'Русский рэп',
        'Зарубежный рэп',
        'Хип-хоп',
        'Trap',
        'Инди поп',
        'Инди рок',
        'Русский поп',
        'Зарубежный поп',
        'Русский рок',
        'Зарубежный рок',
        'Регги',
        'Шансон',
        'Поп',
        'Рэп',
        'Рок',
        'Инди',
    ];

    /**
     * @return ParsedArtistPage[]
     */
    public function parse(string $url, int $artistLimit = 0, int $pageLimit = 0): array
    {
        if ($this->isArtistsListingUrl($url)) {
            return $this->parseArtistsListing($url, $artistLimit, $pageLimit);
        }

        return [$this->parseArtistPage($url, $pageLimit)];
    }

    /**
     * @return ParsedArtistPage[]
     */
    private function parseArtistsListing(string $url, int $artistLimit = 0, int $pageLimit = 0): array
    {
        $artistUrls = [];
        $maxArtists = $artistLimit > 0 ? $artistLimit : PHP_INT_MAX;
        $page = 1;

        while (count($artistUrls) < $maxArtists) {
            $pageUrl = $this->listingPageUrl($url, $page);
            $html = $this->fetchHtml($pageUrl);

            $pageArtistUrls = $this->extractArtistLinksFromListing($html);

            if ($pageArtistUrls === []) {
                break;
            }

            $beforeCount = count($artistUrls);

            foreach ($pageArtistUrls as $artistUrl) {
                if (! in_array($artistUrl, $artistUrls, true)) {
                    $artistUrls[] = $artistUrl;
                }

                if (count($artistUrls) >= $maxArtists) {
                    break 2;
                }
            }

            if (count($artistUrls) === $beforeCount) {
                break;
            }

            $page++;
        }

        return collect($artistUrls)
            ->map(fn (string $artistUrl) => $this->parseArtistPage($artistUrl, $pageLimit))
            ->all();
    }

    private function parseArtistPage(string $url, int $pageLimit = 0): ParsedArtistPage
    {
        $slug = $this->artistSlugFromUrl($url);
        $pagesHtml = [];
        $page = 1;

        while (true) {
            if ($pageLimit > 0 && $page > $pageLimit) {
                break;
            }

            $pageUrl = $this->artistPageUrl($url, $page);

            try {
                $html = $this->fetchHtml($pageUrl);
            } catch (Throwable $exception) {
                if ($page === 1) {
                    throw $exception;
                }

                break;
            }

            $pagesHtml[] = $html;

            if (! $this->hasNextArtistPage($html, $page + 1)) {
                break;
            }

            $page++;
        }

        if ($pagesHtml === []) {
            throw new RuntimeException('Не удалось получить HTML страницы артиста.');
        }

        $xpath = $this->makeXPath($pagesHtml[0]);

        $artistName = $this->firstNonEmptyText($xpath, [
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' tracksHeader ')]//h1[1]",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' tracksHeader ')]//*[contains(concat(' ', normalize-space(@class), ' '), ' item ') and contains(concat(' ', normalize-space(@class), ' '), ' main ')]//h1[1]",
            '//h1[1]',
        ]);

        $artistName = $artistName !== '' ? $artistName : Str::headline(str_replace('-', ' ', $slug));
        $imageUrl = $this->extractLargestArtistImage($xpath);

        $tracks = collect($pagesHtml)
            ->flatMap(fn (string $html) => $this->extractTracksFromArtistPage($html))
            ->unique(fn (ParsedTrack $track) => Str::lower($track->title . '|' . $track->audioUrl))
            ->values()
            ->all();

        return new ParsedArtistPage(
            artistName: $artistName,
            artistSlug: Str::slug($artistName) ?: $slug,
            imageUrl: $imageUrl,
            tracks: $tracks,
        );
    }

    private function isArtistsListingUrl(string $url): bool
    {
        $segments = $this->pathSegments($url);

        return count($segments) >= 2
            && $segments[0] === 'collections'
            && $segments[1] === 'artists'
            && (! isset($segments[2]) || ctype_digit((string) $segments[2]));
    }

    private function artistSlugFromUrl(string $url): string
    {
        $segments = $this->pathSegments($url);

        if (count($segments) < 3) {
            throw new RuntimeException('Не удалось определить slug артиста из URL.');
        }

        $slug = rawurldecode((string) $segments[2]);

        if ($slug === '' || ctype_digit($slug)) {
            throw new RuntimeException('В URL отсутствует корректный slug артиста.');
        }

        return $slug;
    }

    private function listingPageUrl(string $baseUrl, int $page): string
    {
        $parts = parse_url($baseUrl);

        $path = $parts['path'] ?? '/collections/artists';
        $path = rtrim((string) preg_replace('~/\d+$~', '', rtrim($path, '/')), '/');

        if ($page > 1) {
            $path .= '/' . $page;
        }

        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return self::BASE_URL . $path . $query;
    }

    private function artistPageUrl(string $baseUrl, int $page): string
    {
        $parts = parse_url($baseUrl);

        $path = $parts['path'] ?? '';
        $path = rtrim((string) preg_replace('~/\d+$~', '', rtrim($path, '/')), '/');

        if ($page > 1) {
            $path .= '/' . $page;
        }

        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return self::BASE_URL . $path . $query;
    }

    /**
     * @return string[]
     */
    private function extractArtistLinksFromListing(string $html): array
    {
        $xpath = $this->makeXPath($html);
        $links = [];

        $nodes = $xpath->query(
            "//ul[contains(concat(' ', normalize-space(@class), ' '), ' plateItems ') and contains(concat(' ', normalize-space(@class), ' '), ' ajaxContent ')]" .
            "/div[contains(concat(' ', normalize-space(@class), ' '), ' item ')]" .
            "/a[@href]"
        );

        foreach ($nodes ?: [] as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $normalized = $this->normalizeArtistListingHref((string) $node->getAttribute('href'));

            if ($normalized !== null && ! in_array($normalized, $links, true)) {
                $links[] = $normalized;
            }
        }

        if ($links !== []) {
            return $links;
        }

        preg_match_all(
            '~href=["\'](?P<href>https?://muzofond\.fm/collections/artists/[^"\']+|/collections/artists/[^"\']+)["\']~iu',
            $html,
            $matches
        );

        foreach ($matches['href'] ?? [] as $href) {
            $normalized = $this->normalizeArtistListingHref($href);

            if ($normalized !== null && ! in_array($normalized, $links, true)) {
                $links[] = $normalized;
            }
        }

        return $links;
    }

    /**
     * @return ParsedTrack[]
     */
    private function extractTracksFromArtistPage(string $html): array
    {
        $xpath = $this->makeXPath($html);
        $tracks = [];

        $items = $xpath->query(
            "//li[contains(concat(' ', normalize-space(@class), ' '), ' item ')][@data-id][@data-duration]"
        );

        foreach ($items ?: [] as $item) {
            if (! $item instanceof \DOMElement) {
                continue;
            }

            if (! $this->isMainArtistTrackItem($xpath, $item)) {
                continue;
            }

            $playNode = $this->firstNodeFromContext($xpath, $item, [
                ".//li[contains(concat(' ', normalize-space(@class), ' '), ' play ')][@data-url]",
            ]);

            if (! $playNode instanceof \DOMElement) {
                continue;
            }

            $audioUrl = trim((string) $playNode->getAttribute('data-url'));

            if ($audioUrl === '') {
                continue;
            }

            $rowArtists = $this->firstNonEmptyTextFromContext($xpath, $item, [
                ".//span[contains(concat(' ', normalize-space(@class), ' '), ' artist ')][1]",
            ]);

            $rawTrackTitle = $this->firstNonEmptyTextFromContext($xpath, $item, [
                ".//span[contains(concat(' ', normalize-space(@class), ' '), ' track ')][1]",
            ]);

            if ($rowArtists === '' || $rawTrackTitle === '') {
                continue;
            }

            $durationSeconds = (int) $item->getAttribute('data-duration');

            if ($durationSeconds <= 0) {
                $durationText = $this->firstNonEmptyTextFromContext($xpath, $item, [
                    ".//div[contains(concat(' ', normalize-space(@class), ' '), ' duration ') and contains(concat(' ', normalize-space(@class), ' '), ' enemy ')][1]",
                ]);

                $durationSeconds = $this->durationToSeconds($durationText);
            }

            if ($durationSeconds <= 0) {
                continue;
            }

            $genres = [];

            $genreNodes = $xpath->query(
                ".//div[contains(concat(' ', normalize-space(@class), ' '), ' description ')]//a",
                $item
            );

            foreach ($genreNodes ?: [] as $genreNode) {
                $genre = $this->normalizeText($genreNode->textContent ?? '');

                if ($genre !== '') {
                    $genres[] = $genre;
                }
            }

            $genres = array_values(array_unique($genres));

            $meta = $this->extractTrackMetadata($rawTrackTitle, $genres);
            $artistNames = $this->extractArtistNames($rowArtists);

            if ($meta['title'] === '' || $artistNames === []) {
                continue;
            }

            $tracks[] = new ParsedTrack(
                title: $meta['title'],
                durationSeconds: $durationSeconds,
                audioUrl: $this->absoluteUrl($audioUrl),
                albumTitle: $meta['album'],
                trackNumber: count($tracks) + 1,
                artistNames: $artistNames,
                releaseYear: $meta['year'],
                genres: $genres,
            );
        }

        return collect($tracks)
            ->unique(fn (ParsedTrack $track) => Str::lower($track->title . '|' . $track->audioUrl))
            ->values()
            ->all();
    }

    private function normalizeArtistListingHref(string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($href === '') {
            return null;
        }

        $url = $this->absoluteUrl($href);
        $segments = $this->pathSegments($url);

        if (count($segments) < 3) {
            return null;
        }

        if ($segments[0] !== 'collections' || $segments[1] !== 'artists') {
            return null;
        }

        $slug = rawurldecode((string) $segments[2]);

        if ($slug === '' || ctype_digit($slug)) {
            return null;
        }

        return self::BASE_URL . '/collections/artists/' . rawurlencode($slug);
    }

    private function extractLargestArtistImage(\DOMXPath $xpath): ?string
    {
        $src = $this->firstNonEmptyAttribute($xpath, [
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' tracksHeader ')]//img/@data-src",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' tracksHeader ')]//img/@src",
            "//meta[@property='og:image']/@content",
        ]);

        if (! $src) {
            return null;
        }

        $absolute = $this->absoluteUrl($src);

        $candidates = [
            preg_replace('/_small(\.[a-z0-9]+)$/i', '_large$1', $absolute),
            preg_replace('/_small(\.[a-z0-9]+)$/i', '_big$1', $absolute),
            preg_replace('/_small(\.[a-z0-9]+)$/i', '_medium$1', $absolute),
            $absolute,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            if ($this->remoteFileExists($candidate)) {
                return $candidate;
            }
        }

        return $absolute;
    }

    private function remoteFileExists(string $url): bool
    {
        try {
            $response = Http::timeout(20)
                ->retry(2, 700)
                ->withHeaders($this->defaultHeaders())
                ->withOptions([
                    'allow_redirects' => true,
                    'http_errors' => false,
                    'verify' => false,
                ])
                ->head($url);

            if ($response->successful()) {
                return true;
            }

            $fallback = Http::timeout(20)
                ->retry(2, 700)
                ->withHeaders(array_merge($this->defaultHeaders(), ['Range' => 'bytes=0-0']))
                ->withOptions([
                    'allow_redirects' => true,
                    'http_errors' => false,
                    'verify' => false,
                ])
                ->get($url);

            return $fallback->successful() || $fallback->status() === 206;
        } catch (Throwable) {
            return false;
        }
    }

    private function hasNextArtistPage(string $html, int $nextPage): bool
    {
        $xpath = $this->makeXPath($html);
        $nodes = $xpath->query(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' pagination ')]//a[@href]"
        );

        foreach ($nodes ?: [] as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $href = trim((string) $node->getAttribute('href'));
            $text = $this->normalizeText($node->textContent ?? '');

            if ($text === (string) $nextPage) {
                return true;
            }

            if (preg_match('~/' . preg_quote((string) $nextPage, '~') . '(?:\?|$)~', $href) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string[]  $genresFromDescription
     * @return array{title:string, album:?string, year:?int}
     */
    private function extractTrackMetadata(string $rawTrackTitle, array $genresFromDescription): array
    {
        $title = $this->normalizeText($rawTrackTitle);
        $title = preg_replace('/\b(новинки|new|новое)\b/iu', '', $title) ?? $title;
        $title = $this->normalizeText($title);
        $album = null;
        $year = null;

        $metadata = $this->splitTrailingMetadataBlock($title);

        if (is_array($metadata)) {
            $baseTitle = $this->normalizeText((string) ($metadata['title'] ?? ''));
            $meta = $this->normalizeText((string) ($metadata['meta'] ?? ''));

            if ($baseTitle !== '' && $meta !== '') {
                $parsed = $this->parseMetaBlock($meta, $genresFromDescription);

                if ($parsed['recognized']) {
                    $title = $baseTitle;
                    $album = $parsed['album'];
                    $year = $parsed['year'];
                }
            }
        }

        return [
            'title' => $title,
            'album' => $album,
            'year' => $year,
        ];
    }

    /**
     * @return array{title:string, meta:string}|null
     */
    private function splitTrailingMetadataBlock(string $title): ?array
    {
        $title = $this->normalizeText($title);

        if ($title === '' || ! str_ends_with($title, ')')) {
            return null;
        }

        $length = mb_strlen($title);
        $depth = 0;

        for ($position = $length - 1; $position >= 0; $position--) {
            $char = mb_substr($title, $position, 1);

            if ($char === ')') {
                $depth++;

                continue;
            }

            if ($char !== '(') {
                continue;
            }

            $depth--;

            if ($depth !== 0) {
                continue;
            }

            $baseTitle = $this->normalizeText(mb_substr($title, 0, $position));
            $meta = $this->normalizeText(mb_substr($title, $position + 1, $length - $position - 2));

            if ($baseTitle === '' || $meta === '') {
                return null;
            }

            return [
                'title' => $baseTitle,
                'meta' => $meta,
            ];
        }

        return null;
    }

    /**
     * @param  string[]  $genresFromDescription
     * @return array{recognized:bool, album:?string, year:?int}
     */
    private function parseMetaBlock(string $meta, array $genresFromDescription): array
    {
        $meta = $this->normalizeText($meta);

        if ($meta === '') {
            return [
                'recognized' => false,
                'album' => null,
                'year' => null,
            ];
        }

        $year = null;

        if (preg_match('/\b(19|20)\d{2}\b/u', $meta, $yearMatch) === 1) {
            $year = (int) $yearMatch[0];
            $meta = trim((string) preg_replace('/\b(19|20)\d{2}\b/u', '', $meta));
        }

        $meta = trim((string) preg_replace('/\s{2,}/u', ' ', $meta));

        $genresUniverse = collect(array_merge($this->knownGenres, $genresFromDescription))
            ->map(fn ($genre) => $this->normalizeText($genre))
            ->filter()
            ->unique()
            ->values()
            ->all();

        foreach ($genresUniverse as $genre) {
            if ($genre === '') {
                continue;
            }

            if (mb_strtolower($meta) === mb_strtolower($genre)) {
                $meta = '';

                break;
            }
        }

        $normalizedMeta = Str::lower($meta);

        if (in_array($normalizedMeta, ['новинки', 'new', 'новое', 'news'], true)) {
            $meta = '';
        }

        $album = $meta !== '' ? $meta : null;

        return [
            'recognized' => $year !== null || $album !== null,
            'album' => $album,
            'year' => $year,
        ];
    }

    /**
     * @return string[]
     */
    private function extractArtistNames(string $rawArtists): array
    {
        $value = $this->normalizeText($rawArtists);

        if ($value === '') {
            return [];
        }

        $value = preg_replace('/\b(feat(?:uring)?|ft|feature|with)\b\.?/iu', ',', $value) ?? $value;
        $value = preg_replace('/\s+(?:x|×)\s+/u', ',', $value) ?? $value;
        $value = str_replace(['&', ';', '/'], ',', $value);

        return collect(explode(',', $value))
            ->map(fn ($artist) => $this->normalizeText($artist))
            ->filter()
            ->unique(fn ($artist) => mb_strtolower($artist))
            ->values()
            ->all();
    }

    private function durationToSeconds(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches) === 1) {
            return ((int) $matches[1] * 60) + (int) $matches[2];
        }

        if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{2})$/', $value, $matches) === 1) {
            $hours = isset($matches[1]) ? (int) $matches[1] : 0;
            $minutes = (int) $matches[2];
            $seconds = (int) $matches[3];

            return ($hours * 3600) + ($minutes * 60) + $seconds;
        }

        return 0;
    }

    private function normalizeText(string $value): string
    {
        $value = GeniusNameMatcher::storageValue($value);
        $value = preg_replace('/\x{FEFF}/u', '', $value) ?? $value;
        $value = preg_replace('/[[:cntrl:]]+/u', ' ', $value) ?? $value;
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return trim($value);
    }

    private function forceUtf8(string $value): string
    {
        return GeniusNameMatcher::forceUtf8($value);
    }

    /**
     * @return string[]
     */
    private function pathSegments(string $url): array
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        if ($path === '') {
            return [];
        }

        return array_values(array_filter(explode('/', $path), fn ($segment) => $segment !== ''));
    }

    private function absoluteUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return $url;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        return rtrim(self::BASE_URL, '/') . '/' . ltrim($url, '/');
    }

    private function fetchHtml(string $url): string
    {
        $response = Http::timeout(45)
            ->retry(2, 900)
            ->withHeaders($this->defaultHeaders())
            ->withOptions([
                'allow_redirects' => true,
                'http_errors' => false,
                'verify' => false,
            ])
            ->get($url);

        $this->ensureSuccessfulHtmlResponse($response, $url);

        return $this->forceUtf8((string) $response->body());
    }

    private function ensureSuccessfulHtmlResponse(Response $response, string $url): void
    {
        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Удалённый сервер вернул %d для %s',
                $response->status(),
                $url,
            ));
        }

        $contentType = Str::lower((string) $response->header('Content-Type'));

        if ($contentType !== '' && ! str_contains($contentType, 'text/html')) {
            throw new RuntimeException(sprintf(
                'Ожидался HTML-ответ для %s, получено: %s',
                $url,
                $contentType,
            ));
        }
    }

    private function makeXPath(string $html): \DOMXPath
    {
        $html = $this->forceUtf8($html);

        libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);

        libxml_clear_errors();

        return new \DOMXPath($dom);
    }

    private function firstNonEmptyText(\DOMXPath $xpath, array $queries): string
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);

            if (! $nodes || $nodes->length === 0) {
                continue;
            }

            foreach ($nodes as $node) {
                $text = $this->normalizeText($node->textContent ?? '');

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    private function firstNonEmptyTextFromContext(\DOMXPath $xpath, \DOMElement $context, array $queries): string
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query, $context);

            if (! $nodes || $nodes->length === 0) {
                continue;
            }

            foreach ($nodes as $node) {
                $text = $this->normalizeText($node->textContent ?? '');

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    private function firstNonEmptyAttribute(\DOMXPath $xpath, array $queries): ?string
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);

            if (! $nodes || $nodes->length === 0) {
                continue;
            }

            foreach ($nodes as $node) {
                $value = $this->normalizeText((string) $node->nodeValue);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function firstNodeFromContext(\DOMXPath $xpath, \DOMElement $context, array $queries): ?\DOMNode
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query, $context);

            if ($nodes && $nodes->length > 0) {
                return $nodes->item(0);
            }
        }

        return null;
    }


    private function isMainArtistTrackItem(\DOMXPath $xpath, \DOMElement $item): bool
    {
        $classProbe = mb_strtolower($this->ancestorClassStack($item));

        if (preg_match('/\b(sidebar|recommend|related|promo|popular|novink|new)\b/u', $classProbe) === 1) {
            return false;
        }

        $heading = mb_strtolower($this->nearestSectionHeading($xpath, $item));

        return ! str_contains($heading, 'новинки');
    }

    private function ancestorClassStack(\DOMNode $node): string
    {
        $chunks = [];
        $current = $node;

        while ($current) {
            if ($current instanceof \DOMElement && $current->hasAttribute('class')) {
                $chunks[] = (string) $current->getAttribute('class');
            }

            $current = $current->parentNode;
        }

        return implode(' ', $chunks);
    }

    private function nearestSectionHeading(\DOMXPath $xpath, \DOMElement $item): string
    {
        $node = $item;

        while ($node) {
            if ($node instanceof \DOMElement) {
                $headingNode = $xpath->query(".//*[self::h1 or self::h2 or self::h3 or self::h4]", $node)?->item(0);

                if ($headingNode) {
                    $heading = $this->normalizeText($headingNode->textContent ?? '');

                    if ($heading !== '') {
                        return $heading;
                    }
                }
            }

            $node = $node->parentNode;
        }

        return '';
    }

    private function defaultHeaders(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'ru,en;q=0.9',
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
            'Referer' => self::BASE_URL . '/',
            'Upgrade-Insecure-Requests' => '1',
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0 Safari/537.36',
        ];
    }
}
