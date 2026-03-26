<?php

namespace App\Services\TrackParsing;

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
    private const GENRE_TOKENS = [
        'русский рэп', 'русский поп', 'русский рок', 'русский шансон', 'русская попса',
        'зарубежный рэп', 'зарубежная попса', 'зарубежный рок', 'рэп', 'hip-hop', 'hip hop',
        'trap', 'drill', 'phonk', 'r&b', 'rnb', 'soul', 'jazz', 'blues', 'funk', 'disco',
        'dance', 'dance & house', 'house', 'deep house', 'progressive house', 'electro-house',
        'electro house', 'techno', 'trance', 'edm', 'pop', 'rock', 'indie', 'alternative',
        'metal', 'punk', 'lo-fi', 'lofi', 'ambient', 'electronic', 'folk', 'country',
        'k-pop', 'j-pop', 'latin', 'reggaeton', 'reggae', 'dubstep', 'hardstyle', 'hard bass',
        'chillout', 'soundtrack', 'instrumental', 'club', 'шансон', 'поп', 'рок', 'метал',
        'электроника', 'электронная музыка', 'альтернатива', 'инди', 'хаус', 'техно', 'транс',
        'дэнс', 'поп-музыка', 'поп музыка', 'драм-н-бейс', 'dnb', 'drum & bass',
    ];

    /**
     * @return ParsedArtistPage[]
     * @throws Throwable
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

            $foundUrls = $this->extractArtistLinksFromListing($html);

            if ($foundUrls === []) {
                break;
            }

            foreach ($foundUrls as $artistUrl) {
                if (! in_array($artistUrl, $artistUrls, true)) {
                    $artistUrls[] = $artistUrl;
                }

                if (count($artistUrls) >= $maxArtists) {
                    break 2;
                }
            }

            if (! $this->hasNextPaginationPage($html, $page + 1)) {
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

            if (! $this->hasNextPaginationPage($html, $page + 1)) {
                break;
            }

            $page++;
        }

        if ($pagesHtml === []) {
            throw new RuntimeException('Не удалось получить HTML страницы артиста.');
        }

        $xpath = $this->makeXPath($pagesHtml[0]);

        $artistName = $this->firstNonEmptyText($xpath, [
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' tracksHeader ')]//*[self::h1 or self::h2][1]",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' tracksHeader ')]//*[contains(concat(' ', normalize-space(@class), ' '), ' title ')][1]",
            '//h1[1]',
        ]);

        $artistName = $artistName !== '' ? $artistName : Str::headline(str_replace('-', ' ', $slug));
        $imageUrl = $this->extractLargestArtistImage($xpath);

        $tracks = collect($pagesHtml)
            ->flatMap(fn (string $html) => $this->extractTracksFromArtistPage($html, $artistName))
            ->unique(fn (ParsedTrack $track) => Str::lower($track->title . '|' . $track->audioUrl))
            ->values()
            ->all();

        return new ParsedArtistPage(
            artistName: $artistName,
            artistSlug: $slug,
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

        $slug = $segments[2];

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
        $links = [];
        $xpath = $this->makeXPath($html);

        $queries = [
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' plateItems ')]//a[contains(concat(' ', normalize-space(@class), ' '), ' item ')][@href]",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' plateItems ')]//*[contains(concat(' ', normalize-space(@class), ' '), ' item ')]//a[@href]",
        ];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);

            foreach ($nodes ?: [] as $node) {
                $href = trim((string) $node->getAttribute('href'));
                $artistUrl = $this->normalizeArtistListingHref($href);

                if ($artistUrl !== null && ! in_array($artistUrl, $links, true)) {
                    $links[] = $artistUrl;
                }
            }
        }

        if ($links !== []) {
            return $links;
        }

        preg_match_all('~href=["\'](?P<href>/collections/artists/[^"\'#?]+(?:\?[^"\'#]*)?)["\']~iu', $html, $matches);

        foreach ($matches['href'] ?? [] as $href) {
            $artistUrl = $this->normalizeArtistListingHref($href);

            if ($artistUrl !== null && ! in_array($artistUrl, $links, true)) {
                $links[] = $artistUrl;
            }
        }

        return $links;
    }

    /**
     * @return ParsedTrack[]
     */
    private function extractTracksFromArtistPage(string $html, string $pageArtistName): array
    {
        $xpath = $this->makeXPath($html);

        $rowQuery = <<<XPATH
(
    //*[contains(concat(' ', normalize-space(@class), ' '), ' item ')]
        [.//*[contains(concat(' ', normalize-space(@class), ' '), ' play ')]]
        [.//*[contains(concat(' ', normalize-space(@class), ' '), ' duration ') and contains(concat(' ', normalize-space(@class), ' '), ' enemy ')]]
)
|
(
    //li
        [.//*[contains(concat(' ', normalize-space(@class), ' '), ' play ')]]
        [.//*[contains(concat(' ', normalize-space(@class), ' '), ' duration ') and contains(concat(' ', normalize-space(@class), ' '), ' enemy ')]]
)
XPATH;

        $rows = $xpath->query($rowQuery);
        $tracks = [];

        foreach ($rows ?: [] as $row) {
            if (! $row instanceof \DOMElement) {
                continue;
            }

            $playNode = $this->firstNodeFromContext($xpath, $row, [
                ".//a[contains(concat(' ', normalize-space(@class), ' '), ' play ')]",
                ".//*[contains(concat(' ', normalize-space(@class), ' '), ' play ')][@data-url]",
                ".//*[contains(concat(' ', normalize-space(@class), ' '), ' play ')][@data-src]",
            ]);

            if (! $playNode instanceof \DOMElement) {
                continue;
            }

            $audioUrl = trim((string) (
                $playNode->getAttribute('href')
                ?: $playNode->getAttribute('data-url')
                ?: $playNode->getAttribute('data-src')
            ));

            if ($audioUrl === '') {
                continue;
            }

            $durationText = $this->firstNonEmptyTextFromContext($xpath, $row, [
                ".//*[contains(concat(' ', normalize-space(@class), ' '), ' duration ') and contains(concat(' ', normalize-space(@class), ' '), ' enemy ')][1]",
                ".//*[contains(concat(' ', normalize-space(@class), ' '), ' duration ')][1]",
            ]);

            $durationSeconds = $this->durationToSeconds($durationText);

            if ($durationSeconds <= 0) {
                continue;
            }

            $rowArtists = $this->firstNonEmptyTextFromContext($xpath, $row, [
                ".//*[contains(concat(' ', normalize-space(@class), ' '), ' artist ')][1]",
                ".//*[contains(concat(' ', normalize-space(@class), ' '), ' author ')][1]",
                ".//*[contains(concat(' ', normalize-space(@class), ' '), ' performer ')][1]",
            ]);

            $rawTitle = $this->firstNonEmptyTextFromContext($xpath, $row, [
                ".//*[contains(concat(' ', normalize-space(@class), ' '), ' title ')][1]",
                ".//*[contains(concat(' ', normalize-space(@class), ' '), ' name ')][1]",
                ".//*[contains(concat(' ', normalize-space(@class), ' '), ' song ')][1]",
            ]);

            $fallbackText = $this->extractFallbackTitleFromRow($row, $durationText);
            $preparedTitle = $this->cleanupRawTitle($rawTitle !== '' ? $rawTitle : $fallbackText, $rowArtists, $pageArtistName);
            $metadata = $this->extractTitleMetadata($preparedTitle);

            if ($metadata['title'] === '' && $fallbackText !== '') {
                $metadata = $this->extractTitleMetadata($this->cleanupRawTitle($fallbackText, $rowArtists, $pageArtistName));
            }

            if ($metadata['title'] === '') {
                continue;
            }

            $artistNames = $this->extractArtistNames(
                $pageArtistName,
                $rowArtists,
                $rawTitle !== '' ? $rawTitle : $fallbackText,
                $metadata['title']
            );

            $tracks[] = new ParsedTrack(
                title: $metadata['title'],
                durationSeconds: $durationSeconds,
                audioUrl: $this->absoluteUrl($audioUrl),
                albumTitle: $metadata['album'],
                artistNames: $artistNames,
                releaseYear: $metadata['year'],
                genres: $metadata['genres'],
            );
        }

        return collect($tracks)
            ->filter(fn (ParsedTrack $track) => $track->title !== '' && $track->audioUrl !== '')
            ->unique(fn (ParsedTrack $track) => Str::lower($track->title . '|' . $track->audioUrl))
            ->values()
            ->all();
    }

    private function extractLargestArtistImage(\DOMXPath $xpath): ?string
    {
        $src = $this->firstNonEmptyAttribute($xpath, [
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' tracksHeader ')]//img/@data-src",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' tracksHeader ')]//img/@src",
            "//img[contains(@src, '/img/collections/')]/@src",
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
            $head = Http::timeout(20)
                ->retry(2, 700)
                ->withHeaders($this->defaultHeaders())
                ->withOptions([
                    'allow_redirects' => true,
                    'http_errors' => false,
                    'verify' => false,
                ])
                ->head($url);

            if ($head->successful()) {
                return true;
            }

            $range = Http::timeout(20)
                ->retry(2, 700)
                ->withHeaders(array_merge($this->defaultHeaders(), ['Range' => 'bytes=0-0']))
                ->withOptions([
                    'allow_redirects' => true,
                    'http_errors' => false,
                    'verify' => false,
                ])
                ->get($url);

            return $range->successful() || $range->status() === 206;
        } catch (Throwable) {
            return false;
        }
    }

    private function hasNextPaginationPage(string $html, int $nextPage): bool
    {
        $xpath = $this->makeXPath($html);
        $nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' pagination ')]//a[@href]");

        foreach ($nodes ?: [] as $node) {
            $href = trim((string) $node->getAttribute('href'));
            $text = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');

            if ($text === (string) $nextPage) {
                return true;
            }

            if (preg_match('~/' . preg_quote((string) $nextPage, '~') . '(?:\?|$)~', $href) === 1) {
                return true;
            }
        }

        return false;
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

        return (string) $response->body();
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
                $text = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');

                if ($text !== '') {
                    return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
                $text = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');

                if ($text !== '') {
                    return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
                $value = trim((string) $node->nodeValue);

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

    private function extractFallbackTitleFromRow(\DOMElement $row, string $durationText): string
    {
        $text = html_entity_decode(trim((string) $row->textContent), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = str_replace($durationText, '', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function cleanupRawTitle(string $rawTitle, string $rowArtists, string $pageArtistName): string
    {
        $title = html_entity_decode(trim($rawTitle), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;

        foreach (array_filter([$rowArtists, $pageArtistName]) as $artistLabel) {
            $quoted = preg_quote(trim($artistLabel), '/');
            $title = preg_replace('/^' . $quoted . '\s*[-,:—]*\s*/iu', '', $title) ?? $title;
            $title = preg_replace('/^' . $quoted . '\s*,\s*/iu', '', $title) ?? $title;
        }

        $title = preg_replace('/^\s*[\-–—,:]+\s*/u', '', $title) ?? $title;
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;

        return trim($title);
    }

    /**
     * @return array{title:string,album:?string,year:?int,genres:string[]}
     */
    private function extractTitleMetadata(string $value): array
    {
        $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        $groups = [];

        while (preg_match('/^(.*)\(([^()]*)\)\s*$/u', $value, $matches) === 1) {
            $inside = trim($matches[2]);

            if ($inside === '') {
                $value = trim($matches[1]);
                continue;
            }

            array_unshift($groups, $inside);
            $value = trim($matches[1]);
        }

        $albumParts = [];
        $genres = [];
        $year = null;

        foreach ($groups as $group) {
            $tokens = preg_split('/\s*(?:,|\||•|\/|;|—|–)\s*/u', $group) ?: [];

            foreach ($tokens as $token) {
                $token = trim($token);

                if ($token === '') {
                    continue;
                }

                if ($this->isYearToken($token)) {
                    $year = $year ?? (int) $token;
                    continue;
                }

                if ($this->isGenreToken($token)) {
                    $genres[] = $this->normalizeGenre($token);
                    continue;
                }

                $albumParts[] = $token;
            }
        }

        $title = trim($value);
        $album = $albumParts !== [] ? trim(implode(', ', $albumParts)) : null;
        $genres = collect($genres)
            ->filter(fn (string $genre) => $genre !== '')
            ->unique(fn (string $genre) => Str::lower($genre))
            ->values()
            ->all();

        return [
            'title' => $title,
            'album' => $album,
            'year' => $year,
            'genres' => $genres,
        ];
    }

    /**
     * @return string[]
     */
    private function extractArtistNames(
        string $pageArtistName,
        string $rowArtists,
        string $rawTitle,
        string $cleanTitle
    ): array {
        $names = [$pageArtistName];

        if ($rowArtists !== '') {
            $names = array_merge($names, $this->splitArtistString($rowArtists));
        }

        $artistPrefix = $this->extractArtistPrefixFromRawTitle($rawTitle, $cleanTitle);

        if ($artistPrefix !== null) {
            $names = array_merge($names, $this->splitArtistString($artistPrefix));
        }

        if (preg_match('/(?:feat\.?|ft\.?|feature|featuring|with)\s+(.+)$/iu', $rawTitle, $matches) === 1) {
            $names = array_merge($names, $this->splitArtistString($matches[1]));
        }

        return collect($names)
            ->map(fn (string $name) => trim(preg_replace('/\s+/u', ' ', $name) ?? $name))
            ->filter(fn (string $name) => $name !== '')
            ->unique(fn (string $name) => Str::lower($name))
            ->values()
            ->all();
    }

    private function extractArtistPrefixFromRawTitle(string $rawTitle, string $cleanTitle): ?string
    {
        $rawTitle = html_entity_decode(trim($rawTitle), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $rawTitle = preg_replace('/\s+/u', ' ', $rawTitle) ?? $rawTitle;

        if ($cleanTitle === '') {
            return null;
        }

        $position = mb_stripos($rawTitle, $cleanTitle);

        if ($position === false || $position === 0) {
            return null;
        }

        $prefix = trim(mb_substr($rawTitle, 0, $position));
        $prefix = preg_replace('/[\-–—,:]+$/u', '', $prefix) ?? $prefix;

        return trim($prefix) !== '' ? trim($prefix) : null;
    }

    /**
     * @return string[]
     */
    private function splitArtistString(string $value): array
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\(([^()]*)\)/u', '', $value) ?? $value;
        $value = str_ireplace([' feat. ', ' feat ', ' ft. ', ' ft ', ' feature ', ' featuring ', ' with '], ',', $value);
        $value = preg_replace('/\s+(?:and|&|x|и)\s+/iu', ',', $value) ?? $value;

        return collect(preg_split('/[,\/;]/u', $value) ?: [])
            ->map(fn ($part) => trim((string) $part))
            ->filter(fn ($part) => $part !== '')
            ->values()
            ->all();
    }

    private function durationToSeconds(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{2})$/', $value, $matches) === 1) {
            $hours = isset($matches[1]) ? (int) $matches[1] : 0;
            $minutes = (int) $matches[2];
            $seconds = (int) $matches[3];

            return ($hours * 3600) + ($minutes * 60) + $seconds;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches) === 1) {
            return ((int) $matches[1] * 60) + (int) $matches[2];
        }

        return 0;
    }

    private function absoluteUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        return rtrim(self::BASE_URL, '/') . '/' . ltrim($url, '/');
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

    /**
     * @return string[]
     */
    private function pathSegments(string $url): array
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        if ($path === '') {
            return [];
        }

        return array_values(array_filter(explode('/', $path)));
    }

    private function normalizeArtistListingHref(string $href): ?string
    {
        if ($href === '') {
            return null;
        }

        $absolute = $this->absoluteUrl($href);
        $segments = $this->pathSegments($absolute);

        if (count($segments) !== 3) {
            return null;
        }

        if ($segments[0] !== 'collections' || $segments[1] !== 'artists') {
            return null;
        }

        if ($segments[2] === '' || ctype_digit($segments[2])) {
            return null;
        }

        return self::BASE_URL . '/collections/artists/' . $segments[2];
    }

    private function isYearToken(string $token): bool
    {
        return preg_match('/^(19|20)\d{2}$/', $token) === 1;
    }

    private function isGenreToken(string $token): bool
    {
        $normalized = Str::lower(trim($token));

        foreach (self::GENRE_TOKENS as $genre) {
            if ($normalized === Str::lower($genre)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeGenre(string $token): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $token) ?? $token);

        return Str::title(Str::lower($normalized));
    }
}
