<?php

namespace Tests\Feature;

use App\Services\TrackParsing\MuzofondTrackParser;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

class MuzofondTrackParserTest extends TestCase
{
    public function test_extract_tracks_from_artist_page_ignores_duplicate_title_metadata_as_album(): void
    {
        $tracks = $this->extractTracks($this->trackHtml('Mayot', 'Снег (Снег 2019)', '/snow.mp3', '03:06'), 'Mayot');
        $track = $tracks[0] ?? null;

        $this->assertNotNull($track);
        $this->assertSame('Снег', $track->title);
        $this->assertNull($track->albumTitle);
        $this->assertSame(2019, $track->releaseYear);
    }

    public function test_extract_tracks_from_artist_page_keeps_real_album_metadata(): void
    {
        $tracks = $this->extractTracks($this->trackHtml('Stromae', 'Alors On Danse (Cheese 2010)', '/alors.mp3', '03:27'), 'Stromae');
        $track = $tracks[0] ?? null;

        $this->assertNotNull($track);
        $this->assertSame('Alors On Danse', $track->title);
        $this->assertSame('Cheese', $track->albumTitle);
        $this->assertSame(2010, $track->releaseYear);
    }

    public function test_extract_tracks_from_artist_page_keeps_ringtone_marker_and_does_not_treat_it_as_album(): void
    {
        $tracks = $this->extractTracks($this->trackHtml('Mayot', 'Ты (Рингтон 2021)', '/ringtone.mp3', '00:30'), 'Mayot');
        $track = $tracks[0] ?? null;

        $this->assertNotNull($track);
        $this->assertSame('Ты (Рингтон 2021)', $track->title);
        $this->assertNull($track->albumTitle);
        $this->assertSame(2021, $track->releaseYear);
    }

    public function test_extract_tracks_from_album_page_keeps_parenthesized_word_suffix_in_title(): void
    {
        $tracks = $this->extractAlbumTracks($this->albumPageHtml('Mayot - РћР±Р°', [
            $this->trackRowHtml('21', '134', 'https://files.test/drugoy.mp3', 'Mayot', 'Р”СЂСѓРі(РѕР№)'),
        ]), 'Mayot');
        $track = $tracks[0] ?? null;

        $this->assertNotNull($track);
        $this->assertSame('Р”СЂСѓРі(РѕР№)', $track->title);
        $this->assertSame('РћР±Р°', $track->albumTitle);
        $this->assertSame(1, $track->trackNumber);
    }

    public function test_parse_artist_page_collects_recommended_album_tracks_first_and_skips_duplicate_audio_urls(): void
    {
        Http::fake([
            'https://muzofond.fm/collections/artists/mayot' => Http::response(
                $this->artistPageHtml([
                    $this->trackRowHtml('1', '186', 'https://files.test/shared.mp3', 'Mayot', 'Снег'),
                ], true),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
            'https://muzofond.fm/collections/artists/mayot/2' => Http::response(
                $this->artistPageHtml([
                    $this->trackRowHtml('2', '200', 'https://files.test/artist-only.mp3', 'Mayot', 'Лето'),
                ], false),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
            'https://muzofond.fm/collections/albums/mayot%20ghetto%20garden' => Http::response(
                $this->albumPageHtml('Mayot - Ghetto Garden', [
                    $this->trackRowHtml('11', '186', 'https://files.test/shared.mp3', 'Mayot', 'Снег'),
                    $this->trackRowHtml('12', '210', 'https://files.test/album-only.mp3', 'Mayot feat OG Buda', 'Торчи'),
                ]),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
        ]);

        $pages = app(MuzofondTrackParser::class)->parse('https://muzofond.fm/collections/artists/mayot');
        $page = $pages[0] ?? null;

        $this->assertNotNull($page);
        $this->assertSame('Mayot', $page->artistName);
        $this->assertCount(3, $page->tracks);
        $this->assertSame('https://files.test/shared.mp3', $page->tracks[0]->audioUrl);
        $this->assertSame('Ghetto Garden', $page->tracks[0]->albumTitle);
        $this->assertSame('https://files.test/album-only.mp3', $page->tracks[1]->audioUrl);
        $this->assertSame('Ghetto Garden', $page->tracks[1]->albumTitle);
        $this->assertSame('https://files.test/artist-only.mp3', $page->tracks[2]->audioUrl);
        $this->assertNull($page->tracks[2]->albumTitle);
    }

    public function test_parse_artist_page_ignores_unrelated_album_links_and_tracks_from_other_artists(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://muzofond.fm/collections/artists/mayot' => Http::response(
                $this->artistPageHtml([
                    $this->trackRowHtml('1', '186', 'https://files.test/mayot-main.mp3', 'Mayot', 'Снег'),
                    $this->trackRowHtml('2', '190', 'https://files.test/krovostok.mp3', 'Кровосток', 'Приснился'),
                ], false, <<<HTML
<h3>Рекомендуемые альбомы</h3>
<div class="swiper">
    <div class="swiper-wrapper">
        <div class="swiper-slide">
            <a href="https://muzofond.fm/collections/albums/mayot%20ghetto%20garden">
                <span class="title">Mayot - Ghetto Garden</span>
            </a>
        </div>
    </div>
</div>
<a href="https://muzofond.fm/collections/albums/krovostok%20skvoznoe"><span class="title">Кровосток - Сквозное</span></a>
HTML),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
            'https://muzofond.fm/collections/albums/mayot%20ghetto%20garden' => Http::response(
                $this->albumPageHtml('Mayot - Ghetto Garden', [
                    $this->trackRowHtml('11', '186', 'https://files.test/mayot-album.mp3', 'Mayot', 'Торчи'),
                ]),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
        ]);

        $pages = app(MuzofondTrackParser::class)->parse('https://muzofond.fm/collections/artists/mayot');
        $page = $pages[0] ?? null;

        $this->assertNotNull($page);
        $this->assertCount(2, $page->tracks);
        $this->assertSame(['https://files.test/mayot-album.mp3', 'https://files.test/mayot-main.mp3'], array_map(
            fn ($track) => $track->audioUrl,
            $page->tracks,
        ));
        $this->assertFalse(collect($page->tracks)->contains(fn ($track) => $track->title === 'Приснился'));
        Http::assertSentCount(2);
    }

    public function test_parse_artist_page_prefers_album_page_track_when_artist_page_contains_same_song_with_another_audio_url(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://muzofond.fm/collections/artists/mayot' => Http::response(
                $this->artistPageHtml([
                    $this->trackRowHtml('1', '186', 'https://files.test/artist-snow.mp3', 'Mayot', 'РЎРЅРµРі'),
                    $this->trackRowHtml('2', '200', 'https://files.test/artist-only.mp3', 'Mayot', 'Р›РµС‚Рѕ'),
                ], false, <<<HTML
<h3>Р РµРєРѕРјРµРЅРґСѓРµРјС‹Рµ Р°Р»СЊР±РѕРјС‹</h3>
<div class="swiper">
    <div class="swiper-wrapper">
        <div class="swiper-slide">
            <a href="https://muzofond.fm/collections/albums/mayot%20ghetto%20garden">
                <span class="title">Mayot - Ghetto Garden</span>
            </a>
        </div>
    </div>
</div>
HTML),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
            'https://muzofond.fm/collections/albums/mayot%20ghetto%20garden' => Http::response(
                $this->albumPageHtml('Mayot - Ghetto Garden', [
                    $this->trackRowHtml('11', '186', 'https://files.test/album-snow.mp3', 'Mayot', 'РЎРЅРµРі'),
                ]),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
        ]);

        $pages = app(MuzofondTrackParser::class)->parse('https://muzofond.fm/collections/artists/mayot');
        $page = $pages[0] ?? null;

        $this->assertNotNull($page);
        $this->assertCount(2, $page->tracks);
        $this->assertSame('https://files.test/album-snow.mp3', $page->tracks[0]->audioUrl);
        $this->assertSame('Ghetto Garden', $page->tracks[0]->albumTitle);
        $this->assertSame('https://files.test/artist-only.mp3', $page->tracks[1]->audioUrl);
    }

    /**
     * @return array<int, object>
     */
    private function extractTracks(string $html, string $pageArtistName): array
    {
        $parser = app(MuzofondTrackParser::class);
        $reflection = new ReflectionClass($parser);
        $method = $reflection->getMethod('extractTracksFromArtistPage');
        $method->setAccessible(true);

        /** @var array<int, object> $tracks */
        $tracks = $method->invoke($parser, $html, $pageArtistName);

        return $tracks;
    }

    /**
     * @return array<int, object>
     */
    private function extractAlbumTracks(string $html, string $pageArtistName): array
    {
        $parser = app(MuzofondTrackParser::class);
        $reflection = new ReflectionClass($parser);
        $method = $reflection->getMethod('extractTracksFromAlbumPage');
        $method->setAccessible(true);

        /** @var array<int, object> $tracks */
        $tracks = $method->invoke($parser, $html, $pageArtistName);

        return $tracks;
    }

    private function trackHtml(string $artist, string $track, string $audioUrl, string $duration): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<body>
<ul class="plateItems ajaxContent">
    <li class="item" data-id="1" data-duration="186">
        <ul><li class="play" data-url="{$audioUrl}"></li></ul>
        <div class="desc descriptionIs">
            <h3>
                <span class="artist">{$artist}</span>
                 —
                <span class="track">{$track}</span>
            </h3>
            <div class="description">
                <span><a href="/popular/hip-hop">Hip-Hop</a></span>
            </div>
        </div>
        <div class="duration enemy">{$duration}</div>
    </li>
</ul>
</body>
</html>
HTML;
    }

    /**
     * @param  string[]  $rows
     */
    private function artistPageHtml(array $rows, bool $withPagination, string $tailHtml = ''): string
    {
        $pagination = $withPagination
            ? '<div class="pagination"><ul><a href="https://muzofond.fm/collections/artists/mayot"><li class="activePage">1</li></a><a href="https://muzofond.fm/collections/artists/mayot/2"><li>2</li></a></ul></div>'
            : '';
        $recommendedAlbums = $withPagination
            ? <<<HTML
<h3>Рекомендуемые альбомы</h3>
<div class="swiper">
    <div class="swiper-wrapper">
        <div class="swiper-slide">
            <a href="https://muzofond.fm/collections/albums/mayot%20ghetto%20garden">
                <span class="title">Mayot - Ghetto Garden</span>
            </a>
        </div>
    </div>
</div>
HTML
            : '';
        $rowsHtml = implode("\n", $rows);

        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<body>
<div class="tracksHeader"><h1>Mayot</h1><img src="https://img.test/mayot.jpg"></div>
<ul class="mainSongs unstyled songsListen songs" data-type="tracks" data-url="https://muzofond.fm/collections/artists/mayot">
{$rowsHtml}
</ul>
{$pagination}
{$recommendedAlbums}
{$tailHtml}
</body>
</html>
HTML;
    }

    /**
     * @param  string[]  $rows
     */
    private function albumPageHtml(string $heading, array $rows): string
    {
        $rowsHtml = implode("\n", $rows);

        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <title>{$heading} (2020) слушать музыку альбома онлайн бесплатно</title>
</head>
<body>
<h1>{$heading}</h1>
<ul class="mainSongs unstyled songsListen songs" data-type="tracks" data-url="https://muzofond.fm/collections/albums/mayot ghetto garden">
{$rowsHtml}
</ul>
</body>
</html>
HTML;
    }

    private function trackRowHtml(string $id, string $durationSeconds, string $audioUrl, string $artist, string $track): string
    {
        return <<<HTML
<li class="item" data-id="{$id}" data-duration="{$durationSeconds}">
    <ul><li class="play" data-url="{$audioUrl}"></li></ul>
    <div class="desc descriptionIs">
        <h3>
            <span class="artist">{$artist}</span>
             —
            <span class="track">{$track}</span>
        </h3>
    </div>
    <div class="duration enemy">03:06</div>
</li>
HTML;
    }
}
