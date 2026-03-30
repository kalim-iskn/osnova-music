<?php

namespace App\Services\TrackParsing;

use App\Services\Genius\GeniusNameMatcher;
use App\Services\TrackParsing\DTO\ParsedArtistPage;
use App\Services\TrackParsing\DTO\ParsedTrack;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class MuzofondTrackParser
{
    private const BASE_URL = 'https://muzofond.fm';
    private const HTTP_BATCH_SIZE = 10;

    /**
     * @var array<string, string>
     */
    private array $htmlCache = [];

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
        $rootArtistUrl = $this->artistPageUrl($url, 1);
        $firstPageHtml = $this->fetchHtml($rootArtistUrl);

        $artistPageUrls = $this->extractArtistPaginationUrls($firstPageHtml, $rootArtistUrl, $pageLimit);
        $pagesHtmlByUrl = [$rootArtistUrl => $firstPageHtml] + $this->fetchHtmlMany(array_slice($artistPageUrls, 1), false);

        if ($pagesHtmlByUrl === []) {
            throw new RuntimeException('Не удалось получить HTML страницы артиста.');
        }

        $orderedArtistPages = [];

        foreach ($artistPageUrls as $artistPageUrl) {
            if (isset($pagesHtmlByUrl[$artistPageUrl])) {
                $orderedArtistPages[] = $pagesHtmlByUrl[$artistPageUrl];
            }
        }

        if ($orderedArtistPages === []) {
            $orderedArtistPages[] = $firstPageHtml;
        }

        $xpath = $this->makeXPath($orderedArtistPages[0]);

        $artistName = $this->firstNonEmptyText($xpath, [
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' tracksHeader ')]//h1[1]",
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' tracksHeader ')]//*[contains(concat(' ', normalize-space(@class), ' '), ' item ') and contains(concat(' ', normalize-space(@class), ' '), ' main ')]//h1[1]",
            '//h1[1]',
        ]);

        $artistName = $artistName !== '' ? $artistName : Str::headline(str_replace('-', ' ', $slug));
        $imageUrl = $this->extractLargestArtistImage($xpath);

        $albumLinks = collect($orderedArtistPages)
            ->flatMap(fn (string $html) => $this->extractRecommendedAlbumLinks($html, $artistName))
            ->unique()
            ->values()
            ->all();
        $albumPagesHtml = $this->fetchHtmlMany($albumLinks, false);
        $albumTracks = [];

        foreach ($albumLinks as $albumLink) {
            $albumHtml = $albumPagesHtml[$albumLink] ?? null;

            if (! is_string($albumHtml) || $albumHtml === '') {
                continue;
            }

            $albumTracks = array_merge($albumTracks, $this->extractTracksFromAlbumPage($albumHtml, $artistName));
        }

        $albumTracks = $this->uniqueTracksByAudioUrl($albumTracks);
        $albumTrackAudioUrls = collect($albumTracks)
            ->map(fn (ParsedTrack $track) => Str::lower($track->audioUrl))
            ->filter()
            ->values()
            ->all();
        $artistTracks = [];

        foreach ($orderedArtistPages as $html) {
            $artistTracks = array_merge(
                $artistTracks,
                $this->extractTracksFromArtistPage($html, $artistName, null, $albumTrackAudioUrls)
            );
        }

        $artistTracks = $this->uniqueTracksByAudioUrl($artistTracks);
        $tracks = $this->uniqueTracksByAudioUrl(array_merge($albumTracks, $artistTracks));

        if ($this->debugParsingEnabled()) {
            Log::debug('Muzofond artist parsing summary.', [
                'artist' => $artistName,
                'artist_page_count' => count($orderedArtistPages),
                'recommended_album_count' => count($albumLinks),
                'album_track_count' => count($albumTracks),
                'artist_only_track_count' => count($artistTracks),
                'merged_track_count' => count($tracks),
            ]);
        }

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
    private function extractArtistPaginationUrls(string $html, string $baseUrl, int $pageLimit = 0): array
    {
        $baseUrl = $this->artistPageUrl($baseUrl, 1);
        $xpath = $this->makeXPath($html);
        $pages = [1 => $baseUrl];
        $nodes = $xpath->query(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' pagination ')]//a[@href]"
        );

        foreach ($nodes ?: [] as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $normalized = $this->normalizeArtistPageHref((string) $node->getAttribute('href'), $baseUrl);

            if ($normalized === null) {
                continue;
            }

            $page = $this->artistPageNumberFromUrl($normalized);
            $pages[$page] = $normalized;
        }

        if ($pageLimit > 0) {
            $pages = array_filter($pages, fn (string $artistPageUrl, int $page): bool => $page <= $pageLimit, ARRAY_FILTER_USE_BOTH);
        }

        ksort($pages);

        return array_values($pages);
    }

    private function normalizeArtistPageHref(string $href, string $baseUrl): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($href === '') {
            return null;
        }

        $url = $this->absoluteUrl($href);
        $segments = $this->pathSegments($url);
        $baseSlug = $this->artistSlugFromUrl($baseUrl);

        if (count($segments) < 3 || $segments[0] !== 'collections' || $segments[1] !== 'artists') {
            return null;
        }

        $slug = rawurldecode((string) $segments[2]);

        if ($slug === '' || ctype_digit($slug) || Str::lower($slug) !== Str::lower($baseSlug)) {
            return null;
        }

        $page = isset($segments[3]) && ctype_digit((string) $segments[3])
            ? max(1, (int) $segments[3])
            : 1;

        return self::BASE_URL . '/collections/artists/' . rawurlencode($slug) . ($page > 1 ? '/' . $page : '');
    }

    private function artistPageNumberFromUrl(string $url): int
    {
        $segments = $this->pathSegments($url);

        if (isset($segments[3]) && ctype_digit((string) $segments[3])) {
            return max(1, (int) $segments[3]);
        }

        return 1;
    }

    /**
     * @return string[]
     */
    private function extractRecommendedAlbumLinks(string $html, string $pageArtistName): array
    {
        $xpath = $this->makeXPath($html);
        $links = [];
        $queries = [
            "//*[self::h2 or self::h3 or self::h4][contains(normalize-space(.), 'Рекомендуемые альбомы')]/following-sibling::*[1]//a[@href]",
            "//*[self::h2 or self::h3 or self::h4][contains(normalize-space(.), 'Рекомендуемые альбомы')]/following::*[contains(concat(' ', normalize-space(@class), ' '), ' swiper ')][1]//a[@href]",
            "//*[self::h2 or self::h3 or self::h4][contains(normalize-space(.), 'Рекомендуемые альбомы')]/parent::*[1]//*[contains(concat(' ', normalize-space(@class), ' '), ' swiper ')][1]//a[@href]",
        ];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);

            foreach ($nodes ?: [] as $node) {
                if (! $node instanceof \DOMElement) {
                    continue;
                }

                if (! $this->albumListingBelongsToArtist($xpath, $node, $pageArtistName)) {
                    continue;
                }

                $normalized = $this->normalizeAlbumListingHref((string) $node->getAttribute('href'));

                if ($normalized !== null && ! in_array($normalized, $links, true)) {
                    $links[] = $normalized;
                }
            }

            if ($links !== []) {
                break;
            }
        }

        if ($links === []) {
            $headingPosition = mb_stripos($html, 'Рекомендуемые альбомы');

            if ($headingPosition === false) {
                return [];
            }

            $snippet = mb_substr($html, $headingPosition, 12000);

            preg_match_all(
                '~href=["\'](?P<href>https?://muzofond\.fm/collections/albums/[^"\']+|/collections/albums/[^"\']+)["\']~iu',
                $snippet,
                $matches
            );

            foreach ($matches['href'] ?? [] as $href) {
                $normalized = $this->normalizeAlbumListingHref($href);

                if ($normalized !== null && ! in_array($normalized, $links, true)) {
                    $links[] = $normalized;
                }
            }
        }

        return $links;
    }

    private function albumListingBelongsToArtist(\DOMXPath $xpath, \DOMElement $link, string $pageArtistName): bool
    {
        $labels = collect([
            $this->normalizeText($link->textContent ?? ''),
            $this->normalizeText((string) $link->getAttribute('title')),
        ]);

        $image = $this->firstNodeFromContext($xpath, $link, ['.//img[@alt][1]']);

        if ($image instanceof \DOMElement) {
            $labels->push($this->normalizeText((string) $image->getAttribute('alt')));
        }

        $explicitArtistCandidates = [];

        foreach ($labels->filter()->all() as $label) {
            $parts = preg_split('/\s+[—-]\s+/u', $label, 2);

            if (! is_array($parts) || count($parts) !== 2) {
                continue;
            }

            $candidateArtist = $this->normalizeText((string) ($parts[0] ?? ''));

            if ($candidateArtist !== '') {
                $explicitArtistCandidates[] = $candidateArtist;
            }
        }

        if ($explicitArtistCandidates === []) {
            return true;
        }

        return GeniusNameMatcher::bestArtistScore($pageArtistName, $explicitArtistCandidates) >= 0.82;
    }

    private function normalizeAlbumListingHref(string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($href === '') {
            return null;
        }

        $url = $this->absoluteUrl($href);
        $segments = $this->pathSegments($url);

        if (count($segments) < 3 || $segments[0] !== 'collections' || $segments[1] !== 'albums') {
            return null;
        }

        $slug = rawurldecode((string) $segments[2]);

        if ($slug === '' || ctype_digit($slug)) {
            return null;
        }

        return self::BASE_URL . '/collections/albums/' . rawurlencode($slug);
    }

    /**
     * @return ParsedTrack[]
     */
    private function extractTracksFromAlbumPage(string $html, string $pageArtistName): array
    {
        if (! $this->albumPageBelongsToArtist($html, $pageArtistName)) {
            if ($this->debugParsingEnabled()) {
                Log::debug('Muzofond album page skipped because it belongs to another artist.', [
                    'artist' => $pageArtistName,
                ]);
            }

            return [];
        }

        $albumTitle = $this->extractAlbumTitleFromPage($html, $pageArtistName);
        $tracks = $this->extractTrackListItems($html, $pageArtistName, $albumTitle, [], false, ParsedTrack::SOURCE_ALBUM_PAGE);

        if ($this->debugParsingEnabled()) {
            Log::debug('Muzofond album page parsed.', [
                'artist' => $pageArtistName,
                'album' => $albumTitle,
                'track_count' => count($tracks),
            ]);
        }

        return $tracks;
    }

    private function albumPageBelongsToArtist(string $html, string $pageArtistName): bool
    {
        $xpath = $this->makeXPath($html);
        $candidates = [
            $this->firstNonEmptyText($xpath, ['//h1[1]']),
            $this->firstNonEmptyText($xpath, ['//title[1]']),
        ];

        foreach ($candidates as $candidate) {
            $candidate = $this->normalizeRawText($candidate);

            if ($candidate === '') {
                continue;
            }

            $candidate = preg_replace('/\s+слушать музыку альбома онлайн бесплатно.*$/iu', '', $candidate) ?? $candidate;
            $candidate = preg_replace('/\s*\((19|20)\d{2}\)\s*$/u', '', $candidate) ?? $candidate;
            $candidate = trim((string) preg_replace('/\s{2,}/u', ' ', $candidate));
            $parts = preg_split('/\s+[—-]\s+/u', $candidate, 2);

            if (! is_array($parts) || count($parts) !== 2) {
                continue;
            }

            $candidateArtist = $this->normalizeText((string) ($parts[0] ?? ''));

            if ($candidateArtist === '') {
                continue;
            }

            return GeniusNameMatcher::bestArtistScore($pageArtistName, [$candidateArtist]) >= 0.82;
        }

        return true;
    }

    private function extractAlbumTitleFromPage(string $html, string $pageArtistName): ?string
    {
        $xpath = $this->makeXPath($html);
        $candidates = [
            $this->firstNonEmptyText($xpath, ['//h1[1]']),
            $this->firstNonEmptyText($xpath, ['//title[1]']),
        ];

        foreach ($candidates as $candidate) {
            $candidate = $this->normalizeRawText($candidate);

            if ($candidate === '') {
                continue;
            }

            $candidate = preg_replace('/\s+слушать музыку альбома онлайн бесплатно.*$/iu', '', $candidate) ?? $candidate;
            $candidate = preg_replace('/\s*\((19|20)\d{2}\)\s*$/u', '', $candidate) ?? $candidate;
            $candidate = trim((string) preg_replace('/\s{2,}/u', ' ', $candidate));

            if ($candidate === '') {
                continue;
            }

            $parts = preg_split('/\s+[—–-]\s+/u', $candidate, 2);

            if (is_array($parts) && count($parts) === 2) {
                $candidateArtist = $this->normalizeText((string) $parts[0]);
                $candidateAlbum = $this->normalizeText((string) $parts[1]);

                if ($candidateAlbum !== ''
                    && GeniusNameMatcher::bestArtistScore($pageArtistName, [$candidateArtist]) >= 0.72) {
                    return $candidateAlbum;
                }

                continue;
            }

            if (GeniusNameMatcher::bestArtistScore($pageArtistName, [$candidate]) < 0.72) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  ParsedTrack[]  $tracks
     * @return ParsedTrack[]
     */
    private function uniqueTracksByAudioUrl(array $tracks): array
    {
        return collect($tracks)
            ->unique(fn (ParsedTrack $track) => Str::lower($track->audioUrl !== '' ? $track->audioUrl : ($track->title . '|' . $track->durationSeconds)))
            ->values()
            ->all();
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
    private function extractTracksFromArtistPage(
        string $html,
        string $pageArtistName,
        ?string $defaultAlbumTitle = null,
        array $skippedAudioUrls = [],
    ): array
    {
        return $this->extractTrackListItems($html, $pageArtistName, $defaultAlbumTitle, $skippedAudioUrls, true, ParsedTrack::SOURCE_ARTIST_PAGE);
    }

    /**
     * @param  string[]  $skippedAudioUrls
     * @return ParsedTrack[]
     */
    private function extractTrackListItems(
        string $html,
        string $pageArtistName,
        ?string $defaultAlbumTitle = null,
        array $skippedAudioUrls = [],
        bool $filterToMainArtistSection = false,
        string $sourceType = ParsedTrack::SOURCE_ARTIST_PAGE,
    ): array {
        $xpath = $this->makeXPath($html);
        $tracks = [];
        $skippedAudioLookup = collect($skippedAudioUrls)
            ->map(fn ($audioUrl) => Str::lower($this->absoluteUrl((string) $audioUrl)))
            ->filter()
            ->flip()
            ->all();
        $items = $this->extractTrackItemNodes($xpath);

        foreach ($items as $item) {
            if ($filterToMainArtistSection && ! $this->isMainArtistTrackItem($xpath, $item)) {
                continue;
            }

            $playNode = $this->firstNodeFromContext($xpath, $item, [
                ".//li[contains(concat(' ', normalize-space(@class), ' '), ' play ')][@data-url]",
            ]);

            if (! $playNode instanceof \DOMElement) {
                continue;
            }

            $audioUrl = trim((string) $playNode->getAttribute('data-url'));

            $audioUrl = $this->absoluteUrl($audioUrl);

            if ($audioUrl === '' || isset($skippedAudioLookup[Str::lower($audioUrl)])) {
                continue;
            }

            $rowArtists = $this->firstNonEmptyTextFromContext($xpath, $item, [
                ".//span[contains(concat(' ', normalize-space(@class), ' '), ' artist ')][1]",
            ]);

            $rawTrackTitle = $this->firstNonEmptyRawTextFromContext($xpath, $item, [
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
            $artistNames = $this->extractArtistNames($rowArtists, $pageArtistName);

            if ($meta['title'] === '' || $artistNames === []) {
                continue;
            }

            if (! $this->trackBelongsToPageArtist($pageArtistName, $artistNames, $rowArtists)) {
                continue;
            }

            $albumTitle = $meta['album'] ?? $defaultAlbumTitle;
            $albumTitle = $albumTitle !== null ? $this->normalizeText($albumTitle) : null;
            $albumTitle = $albumTitle !== '' ? $albumTitle : null;

            $tracks[] = new ParsedTrack(
                title: $meta['title'],
                durationSeconds: $durationSeconds,
                audioUrl: $audioUrl,
                albumTitle: $albumTitle,
                trackNumber: count($tracks) + 1,
                artistNames: $artistNames,
                releaseYear: $meta['year'],
                genres: $genres,
                sourceType: $sourceType,
            );
        }

        return $this->uniqueTracksByAudioUrl($tracks);
    }

    /**
     * @param  string[]  $artistNames
     */
    private function trackBelongsToPageArtist(string $pageArtistName, array $artistNames, string $rawArtists): bool
    {
        if (GeniusNameMatcher::bestArtistScore($pageArtistName, $artistNames) >= 0.82) {
            return true;
        }

        return GeniusNameMatcher::bestArtistScore($pageArtistName, [$rawArtists]) >= 0.92;
    }

    /**
     * @return \DOMElement[]
     */
    private function extractTrackItemNodes(\DOMXPath $xpath): array
    {
        $queries = [
            "(//ul[contains(concat(' ', normalize-space(@class), ' '), ' mainSongs ')][1]//li[contains(concat(' ', normalize-space(@class), ' '), ' item ')][@data-id][@data-duration])",
            "//ul[contains(concat(' ', normalize-space(@class), ' '), ' mainSongs ')]//li[contains(concat(' ', normalize-space(@class), ' '), ' item ')][@data-id][@data-duration]",
            "//li[contains(concat(' ', normalize-space(@class), ' '), ' item ')][@data-id][@data-duration]",
        ];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);

            if (! $nodes || $nodes->length === 0) {
                continue;
            }

            $items = [];

            foreach ($nodes as $node) {
                if ($node instanceof \DOMElement) {
                    $items[] = $node;
                }
            }

            if ($items !== []) {
                return $items;
            }
        }

        return [];
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

        return $absolute;
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
        $title = $this->normalizeRawText($rawTrackTitle);
        $title = preg_replace('/\b(новинки|new|новое)\b/iu', '', $title) ?? $title;
        $title = $this->normalizeRawText($title);
        $album = null;
        $year = null;

        $metadata = $this->splitTrailingMetadataBlock($title);

        if (is_array($metadata)) {
            $baseTitle = $this->normalizeRawText((string) ($metadata['title'] ?? ''));
            $meta = $this->normalizeRawText((string) ($metadata['meta'] ?? ''));

            if ($baseTitle !== '' && $meta !== '') {
                $parsed = $this->parseMetaBlock($meta, $genresFromDescription);
                $normalizedBaseTitle = GeniusNameMatcher::canonicalTrack($baseTitle);

                if ($parsed['recognized']) {
                    if ($parsed['ringtone'] ?? false) {
                        return [
                            'title' => $this->normalizeRawText($baseTitle . ' (' . $meta . ')'),
                            'album' => null,
                            'year' => $parsed['year'],
                        ];
                    }

                    $normalizedAlbumTitle = GeniusNameMatcher::canonicalTrack((string) ($parsed['album'] ?? ''));

                    if ($parsed['year'] !== null
                        && $normalizedBaseTitle !== ''
                        && $normalizedAlbumTitle !== ''
                        && $normalizedAlbumTitle === $normalizedBaseTitle) {
                        if ($this->debugParsingEnabled()) {
                            Log::debug('Muzofond metadata block repeated the track title, album was suppressed.', [
                                'raw_track_title' => $rawTrackTitle,
                                'normalized_title' => $baseTitle,
                                'meta_block' => $meta,
                                'suppressed_album' => $parsed['album'],
                                'year' => $parsed['year'],
                            ]);
                        }

                        $parsed['album'] = null;
                    }

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
        $title = $this->normalizeRawText($title);

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

            $baseTitle = $this->normalizeRawText(mb_substr($title, 0, $position));
            $meta = $this->normalizeRawText(mb_substr($title, $position + 1, $length - $position - 2));

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
     * @return array{recognized:bool, album:?string, year:?int, ringtone:bool}
     */
    private function parseMetaBlock(string $meta, array $genresFromDescription): array
    {
        $meta = $this->normalizeText($meta);

        if ($meta === '') {
            return [
                'recognized' => false,
                'album' => null,
                'year' => null,
                'ringtone' => false,
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

        $normalizedMeta = GeniusNameMatcher::normalizeLoose($meta);

        if (in_array($normalizedMeta, ['новинки', 'new', 'новое', 'news'], true)) {
            $meta = '';
        }

        $ringtone = $this->metaContainsRingtoneMarkers($normalizedMeta);
        $album = $meta !== '' && ! $ringtone ? $meta : null;

        return [
            'recognized' => $year !== null || $album !== null || $ringtone,
            'album' => $album,
            'year' => $year,
            'ringtone' => $ringtone,
        ];
    }

    private function metaContainsRingtoneMarkers(string $normalizedMeta): bool
    {
        if ($normalizedMeta === '') {
            return false;
        }

        foreach (['ringtone', 'ring tone', 'rington', 'caller tune', 'na zvonok'] as $marker) {
            if (str_contains(' ' . $normalizedMeta . ' ', ' ' . $marker . ' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function extractArtistNames(string $rawArtists, ?string $pageArtistName = null): array
    {
        $value = $this->normalizeText($rawArtists);

        if ($value === '') {
            return [];
        }

        $value = preg_replace('/\b(feat(?:uring)?|ft|feature|with)\b\.?/iu', ',', $value) ?? $value;
        $value = preg_replace('/\s+(?:x|×)\s+/u', ',', $value) ?? $value;
        $value = preg_replace('/\s+\x{00D7}\s+/u', ',', $value) ?? $value;
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

    private function normalizeRawText(string $value): string
    {
        $value = GeniusNameMatcher::forceUtf8($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = GeniusNameMatcher::forceUtf8($value);
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
        $url = $this->absoluteUrl($url);

        if (isset($this->htmlCache[$url])) {
            return $this->htmlCache[$url];
        }

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

        return $this->htmlCache[$url] = $this->forceUtf8((string) $response->body());
    }

    /**
     * @param  string[]  $urls
     * @return array<string, string>
     */
    private function fetchHtmlMany(array $urls, bool $failOnError = true): array
    {
        $result = [];
        $missingUrls = [];

        foreach (array_values(array_unique(array_filter($urls))) as $url) {
            $url = $this->absoluteUrl($url);

            if (isset($this->htmlCache[$url])) {
                $result[$url] = $this->htmlCache[$url];

                continue;
            }

            $missingUrls[] = $url;
        }

        foreach (array_chunk($missingUrls, self::HTTP_BATCH_SIZE) as $urlChunk) {
            $responses = Http::pool(function (Pool $pool) use ($urlChunk) {
                $requests = [];

                foreach ($urlChunk as $index => $url) {
                    $requests[] = $pool->as('request_' . $index)
                        ->timeout(45)
                        ->retry(2, 900)
                        ->withHeaders($this->defaultHeaders())
                        ->withOptions([
                            'allow_redirects' => true,
                            'http_errors' => false,
                            'verify' => false,
                        ])
                        ->get($url);
                }

                return $requests;
            });

            foreach ($urlChunk as $index => $url) {
                $response = $responses['request_' . $index] ?? null;

                if (! $response instanceof Response) {
                    if ($failOnError) {
                        throw new RuntimeException(sprintf('РќРµ СѓРґР°Р»РѕСЃСЊ РїРѕР»СѓС‡РёС‚СЊ HTML РґР»СЏ %s', $url));
                    }

                    if ($this->debugParsingEnabled()) {
                        Log::warning('Muzofond batch fetch returned no response.', [
                            'url' => $url,
                        ]);
                    }

                    continue;
                }

                try {
                    $this->ensureSuccessfulHtmlResponse($response, $url);
                } catch (RuntimeException $exception) {
                    if ($failOnError) {
                        throw $exception;
                    }

                    if ($this->debugParsingEnabled()) {
                        Log::warning('Muzofond batch fetch skipped one URL.', [
                            'url' => $url,
                            'error' => $exception->getMessage(),
                        ]);
                    }

                    continue;
                }

                $result[$url] = $this->htmlCache[$url] = $this->forceUtf8((string) $response->body());
            }
        }

        return $result;
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

    private function firstNonEmptyRawTextFromContext(\DOMXPath $xpath, \DOMElement $context, array $queries): string
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query, $context);

            if (! $nodes || $nodes->length === 0) {
                continue;
            }

            foreach ($nodes as $node) {
                $text = $this->normalizeRawText($node->textContent ?? '');

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

    private function debugParsingEnabled(): bool
    {
        return (bool) config('services.muzofond.debug_parsing', false);
    }
}
