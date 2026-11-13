<?php

namespace Tests\Feature;

use App\Services\TrackParsing\MuzofondTrackParser;
use App\Services\TrackParsing\DTO\ParsedTrack;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

class MuzofondTrackParserTest extends TestCase
{
    public function test_release_type_uses_the_explicit_collection_label(): void
    {
        $method = (new ReflectionClass(app(MuzofondTrackParser::class)))->getMethod('extractAlbumReleaseType');
        $parser = app(MuzofondTrackParser::class);
        $html = '<html><body><div class="collectionTopHeader"><div class="collectionTopLabel">Сингл</div></div><a>Слушать альбом</a></body></html>';
        $this->assertSame('single', $method->invoke($parser, $html));
        $this->assertSame('album', $method->invoke($parser, str_replace('Сингл', 'Альбом', $html)));
        $this->assertNull($method->invoke($parser, str_replace('Сингл', 'Неизвестно', $html)));
    }
    public function test_album_page_completeness_requires_the_whole_structured_tracklist(): void
    {
        $parser = app(\App\Services\TrackParsing\MuzofondTrackParser::class);
        $reflection = new \ReflectionClass($parser);
        $html = file_get_contents(base_path('tests/Fixtures/muzofond-saluki-ulicy-doma.html'));
        $tracks = $reflection->getMethod('extractTracksFromAlbumPage')->invoke($parser, $html, 'SALUKI');
        $complete = $reflection->getMethod('albumPageTracklistIsComplete');
        $this->assertTrue($complete->invoke($parser, $html, $tracks));
        $this->assertFalse($complete->invoke($parser, str_replace('</head>', '<a rel="next" href="/next">next</a></head>', $html), $tracks));
        array_pop($tracks);
        $this->assertFalse($complete->invoke($parser, $html, $tracks));
    }
    public function test_real_album_pages_keep_parenthesized_song_titles_and_page_album(): void
    {
        $cases = [
            ['muzofond-alisher.html', 'Morgenshtern', '5545961', 'ДОМ (Лондон, Прага, Ницца)', 'Alisher', 2, 154],
            ['muzofond-basta-5-part-2.html', 'Баста', '2651337', 'Выпускной (Медлячок)', 'Баста 5. Часть 2', 3, 335],
        ];
        foreach ($cases as [$file, $artist, $audioId, $title, $album, $position, $duration]) {
            $tracks = $this->extractAlbumTracks(file_get_contents(base_path('tests/Fixtures/'.$file)), $artist);
            $track = collect($tracks)->first(fn (ParsedTrack $track) => $track->audioFileId === $audioId);
            $this->assertNotNull($track, $file);
            $this->assertSame($title, $track->title);
            $this->assertSame($album, $track->albumTitle);
            $this->assertSame($position, $track->trackNumber);
            $this->assertSame($duration, $track->durationSeconds);
        }
    }

    public function test_extract_tracks_from_artist_page_ignores_duplicate_title_metadata_as_album(): void
    {
        $tracks = $this->extractTracks($this->trackHtml('Mayot', 'РЎРЅРµРі (РЎРЅРµРі 2019)', '/snow.mp3', '03:06'), 'Mayot');
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

    public function test_real_stromae_html_identifies_cheese_recording_by_audio_id(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/stromae-muzofond-artist.html'));
        $tracks = $this->extractTracks($html, 'Stromae');
        $recording = collect($tracks)->first(fn (ParsedTrack $track) => $track->audioFileId === '3565061');

        $this->assertNotNull($recording);
        $this->assertSame('Alors On Danse', $recording->title);
        $this->assertSame('Cheese', $recording->albumTitle);
        $this->assertSame(207, $recording->durationSeconds);
        $this->assertNull($recording->trackNumber);
    }

    public function test_duplicate_title_without_year_is_not_treated_as_an_album(): void
    {
        $tracks = $this->extractTracks($this->trackHtml('Example Artist', 'Example Song (Example Song)', '/song.mp3', '03:27'), 'Example Artist');

        $this->assertSame('Example Song', $tracks[0]->title);
        $this->assertNull($tracks[0]->albumTitle);
    }

    public function test_named_version_survives_a_generic_self_titled_album_copy_of_same_audio(): void
    {
        $parser = app(MuzofondTrackParser::class);
        $generic = new ParsedTrack('Example Song', 240, 'https://audio.test/song.mp3', 'Example Song', sourceType: ParsedTrack::SOURCE_ALBUM_PAGE);
        $named = new ParsedTrack('Example Song (Named Remix)', 240, 'https://audio.test/song.mp3', null, sourceType: ParsedTrack::SOURCE_ARTIST_PAGE);
        $preferred = (new ReflectionClass($parser))->getMethod('preferredParsedTrack')->invoke($parser, $generic, $named);

        $this->assertSame('Example Song (Named Remix)', $preferred->title);
    }

    public function test_same_audio_and_album_name_retains_both_page_urls(): void
    {
        $parser = app(MuzofondTrackParser::class);
        $first = new ParsedTrack('Song', 120, 'https://audio.test/1.mp3', 'Release', 1, sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
            albumAppearances: [['title' => 'Release', 'source_type' => ParsedTrack::SOURCE_ALBUM_PAGE, 'track_number' => 1, 'source_url' => 'https://source.test/a']]);
        $second = new ParsedTrack('Song', 120, 'https://audio.test/1.mp3', 'Release', 1, sourceType: ParsedTrack::SOURCE_ALBUM_PAGE,
            albumAppearances: [['title' => 'Release', 'source_type' => ParsedTrack::SOURCE_ALBUM_PAGE, 'track_number' => 1, 'source_url' => 'https://source.test/b']]);
        $preferred = (new ReflectionClass($parser))->getMethod('preferredParsedTrack')->invoke($parser, $first, $second);
        $this->assertEqualsCanonicalizing(['https://source.test/a', 'https://source.test/b'], $preferred->albumAppearances[0]['source_urls']);
    }

    public function test_explicit_artist_page_release_survives_self_titled_album_page_for_same_audio(): void
    {
        $parser = app(MuzofondTrackParser::class);
        $single = new ParsedTrack('Alors On Danse', 207, 'https://audio.test/same.mp3', 'Alors On Danse', 2, ['Stromae'], sourceType: ParsedTrack::SOURCE_ALBUM_PAGE);
        $album = new ParsedTrack('Alors On Danse', 207, 'https://audio.test/same.mp3', 'Cheese', null, ['Stromae'], sourceType: ParsedTrack::SOURCE_ARTIST_PAGE);
        $single->albumAppearances = [['title' => 'Alors On Danse', 'source_type' => ParsedTrack::SOURCE_ALBUM_PAGE, 'track_number' => 2]];
        $album->albumAppearances = [['title' => 'Cheese', 'source_type' => ParsedTrack::SOURCE_ARTIST_PAGE, 'track_number' => null]];

        $preferred = (new ReflectionClass($parser))->getMethod('preferredParsedTrack')->invoke($parser, $single, $album);

        $this->assertSame('Cheese', $preferred->albumTitle);
        $this->assertCount(2, $preferred->albumAppearances);
        $this->assertNull($preferred->trackNumber);
    }

    public function test_extract_tracks_from_artist_page_keeps_ringtone_marker_and_does_not_treat_it_as_album(): void
    {
        $tracks = $this->extractTracks($this->trackHtml('Mayot', 'РўС‹ (Р РёРЅРіС‚РѕРЅ 2021)', '/ringtone.mp3', '00:30'), 'Mayot');
        $track = $tracks[0] ?? null;

        $this->assertNotNull($track);
        $this->assertSame('Ты (Рингтон 2021)', $track->title);
        $this->assertNull($track->albumTitle);
        $this->assertSame(2021, $track->releaseYear);
    }

    public function test_extract_tracks_from_album_page_keeps_parenthesized_word_suffix_in_title(): void
    {
        $albumTitle = json_decode('"\u041e\u0431\u0430"');
        $trackTitle = json_decode('"\u0414\u0440\u0443\u0433(\u043e\u0439)"');
        $tracks = $this->extractAlbumTracks($this->albumPageHtml('Mayot - ' . $albumTitle, [
            $this->trackRowHtml('21', '134', 'https://files.test/drugoy.mp3', 'Mayot', $trackTitle),
        ]), 'Mayot');
        $track = $tracks[0] ?? null;

        $this->assertNotNull($track);
        $this->assertSame($trackTitle, $track->title);
        $this->assertSame($albumTitle, $track->albumTitle);
        $this->assertSame(1, $track->trackNumber);
    }

    public function test_parse_artist_page_collects_recommended_album_tracks_first_and_skips_duplicate_audio_urls(): void
    {
        Http::fake([
            'https://muzofond.fm/collections/artists/mayot' => Http::response(
                $this->artistPageHtml([
                    $this->trackRowHtml('1', '186', 'https://files.test/shared.mp3', 'Mayot', 'РЎРЅРµРі'),
                ], true),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
            'https://muzofond.fm/collections/artists/mayot/2' => Http::response(
                $this->artistPageHtml([
                    $this->trackRowHtml('2', '200', 'https://files.test/artist-only.mp3', 'Mayot', 'Р›РµС‚Рѕ'),
                ], false),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
            'https://muzofond.fm/collections/albums/mayot%20ghetto%20garden' => Http::response(
                $this->albumPageHtml('Mayot - Ghetto Garden', [
                    $this->trackRowHtml('11', '186', 'https://files.test/shared.mp3', 'Mayot', 'РЎРЅРµРі'),
                    $this->trackRowHtml('12', '210', 'https://files.test/album-only.mp3', 'Mayot feat OG Buda', 'РўРѕСЂС‡Рё'),
                ]),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
        ]);

        $pages = app(MuzofondTrackParser::class)->parse('https://muzofond.fm/collections/artists/mayot');
        $page = $pages[0] ?? null;

        $this->assertNotNull($page);
        $this->assertSame('Mayot', $page->artistName);
        $this->assertCount(1, $page->albumPages);
        $this->assertFalse($page->albumPages[0]['complete']);
        $this->assertSame('https://muzofond.fm/collections/albums/mayot%20ghetto%20garden', $page->albumPages[0]['url']);
        $this->assertSame($page->albumPages[0]['url'], $page->tracks[0]->albumAppearances[0]['source_url']);
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
                    $this->trackRowHtml('1', '186', 'https://files.test/mayot-main.mp3', 'Mayot', 'РЎРЅРµРі'),
                    $this->trackRowHtml('2', '190', 'https://files.test/krovostok.mp3', 'РљСЂРѕРІРѕСЃС‚РѕРє', 'РџСЂРёСЃРЅРёР»СЃСЏ'),
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
<a href="https://muzofond.fm/collections/albums/krovostok%20skvoznoe"><span class="title">РљСЂРѕРІРѕСЃС‚РѕРє - РЎРєРІРѕР·РЅРѕРµ</span></a>
HTML),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
            'https://muzofond.fm/collections/albums/mayot%20ghetto%20garden' => Http::response(
                $this->albumPageHtml('Mayot - Ghetto Garden', [
                    $this->trackRowHtml('11', '186', 'https://files.test/mayot-album.mp3', 'Mayot', 'РўРѕСЂС‡Рё'),
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
        $this->assertFalse(collect($page->tracks)->contains(fn ($track) => $track->title === 'РџСЂРёСЃРЅРёР»СЃСЏ'));
        Http::assertSentCount(2);
    }

    public function test_parse_artist_page_keeps_artist_and_album_tracks_when_audio_files_differ(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://muzofond.fm/collections/artists/mayot' => Http::response(
                $this->artistPageHtml([
                    $this->trackRowHtml('1', '186', 'https://files.test/artist-snow.mp3', 'Mayot', 'Р РЋР Р…Р ВµР С–'),
                    $this->trackRowHtml('2', '200', 'https://files.test/artist-only.mp3', 'Mayot', 'Р вЂєР ВµРЎвЂљР С•'),
                ], false, <<<HTML
<h3>Р В Р ВµР С”Р С•Р СР ВµР Р…Р Т‘РЎС“Р ВµР СРЎвЂ№Р Вµ Р В°Р В»РЎРЉР В±Р С•Р СРЎвЂ№</h3>
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
                    $this->trackRowHtml('11', '186', 'https://files.test/album-snow.mp3', 'Mayot', 'Р РЋР Р…Р ВµР С–'),
                ]),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
        ]);

        $pages = app(MuzofondTrackParser::class)->parse('https://muzofond.fm/collections/artists/mayot');
        $page = $pages[0] ?? null;

        $this->assertNotNull($page);
        $this->assertCount(3, $page->tracks);
        $this->assertSame('https://files.test/album-snow.mp3', $page->tracks[0]->audioUrl);
        $this->assertSame('Ghetto Garden', $page->tracks[0]->albumTitle);
        $this->assertSame('https://files.test/artist-snow.mp3', $page->tracks[1]->audioUrl);
        $this->assertSame('https://files.test/artist-only.mp3', $page->tracks[2]->audioUrl);
    }

    public function test_parse_artist_page_keeps_multiple_versions_with_same_canonical_title(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://muzofond.fm/collections/artists/korol-i-shut' => Http::response(
                $this->artistPageHtml([
                    $this->trackRowHtml('1', '186', 'https://files.test/lesnik-live.mp3', 'РљРѕСЂРѕР»СЊ Рё РЁСѓС‚', 'Р›РµСЃРЅРёРє (Live)'),
                    $this->trackRowHtml('2', '190', 'https://files.test/lesnik-instrumental.mp3', 'РљРѕСЂРѕР»СЊ Рё РЁСѓС‚', 'Р›РµСЃРЅРёРє (Instrumental)'),
                ], false, <<<HTML
<h3>Р РµРєРѕРјРµРЅРґСѓРµРјС‹Рµ Р°Р»СЊР±РѕРјС‹</h3>
<div class="swiper">
    <div class="swiper-wrapper">
        <div class="swiper-slide">
            <a href="https://muzofond.fm/collections/albums/korol-i-shut%20eli-myaso-muzhiki">
                <span class="title">РљРѕСЂРѕР»СЊ Рё РЁСѓС‚ - Р•Р»Рё РјСЏСЃРѕ РјСѓР¶РёРєРё</span>
            </a>
        </div>
    </div>
</div>
HTML, 'РљРѕСЂРѕР»СЊ Рё РЁСѓС‚', 'korol-i-shut'),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
            'https://muzofond.fm/collections/albums/korol-i-shut%20eli-myaso-muzhiki' => Http::response(
                $this->albumPageHtml('РљРѕСЂРѕР»СЊ Рё РЁСѓС‚ - Р•Р»Рё РјСЏСЃРѕ РјСѓР¶РёРєРё', [
                    $this->trackRowHtml('11', '186', 'https://files.test/lesnik-original.mp3', 'РљРѕСЂРѕР»СЊ Рё РЁСѓС‚', 'Р›РµСЃРЅРёРє'),
                ]),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
        ]);

        $pages = app(MuzofondTrackParser::class)->parse('https://muzofond.fm/collections/artists/korol-i-shut');
        $page = $pages[0] ?? null;

        $this->assertNotNull($page);
        $lesnikTracks = array_values(array_filter($page->tracks, fn ($track) => str_contains($track->title, 'Лесник')));

        $this->assertCount(3, $lesnikTracks);
        $this->assertEqualsCanonicalizing([
            'Лесник',
            'Лесник (Live)',
            'Лесник (Instrumental)',
        ], array_map(fn ($track) => $track->title, $lesnikTracks));
    }

    public function test_parser_deduplicates_same_audio_id_but_keeps_identical_metadata_for_different_audio_ids(): void
    {
        $encodedFirstFile = base64_encode('http://f.mp3poisk.net/mp3/003/565/061/3565061.mp3');
        $encodedSecondFile = base64_encode('http://f.mp3poisk.net/mp3/003/565/062/3565062.mp3');
        $tracks = $this->extractTracks($this->artistPageHtml([
            $this->trackRowHtml('row-1', '207', 'https://dl3s4.muzofond.fm/' . $encodedFirstFile, 'Stromae', 'Alors On Danse (Cheese 2010)', '3565061'),
            $this->trackRowHtml('row-2', '207', 'https://dl3s5.muzofond.fm/' . $encodedFirstFile, 'Stromae', 'Alors On Danse (Cheese 2010)'),
            $this->trackRowHtml('row-3', '207', 'https://dl3s4.muzofond.fm/' . $encodedSecondFile, 'Stromae', 'Alors On Danse (Cheese 2010)'),
        ], false), 'Stromae');

        $this->assertCount(2, $tracks);
        $this->assertEqualsCanonicalizing(['3565061', '3565062'], array_map(fn ($track) => $track->audioFileId, $tracks));
    }

    public function test_parse_artist_page_recovers_original_lesnik_from_multi_step_mojibake_artist_and_album_pages(): void
    {
        $artist = json_decode('"\u041a\u043e\u0440\u043e\u043b\u044c \u0438 \u0428\u0443\u0442"');
        $liveTitle = json_decode('"\u041b\u0435\u0441\u043d\u0438\u043a (Live)"');
        $panicTitle = json_decode('"\u041f\u0430\u043d\u0438\u043a\u0430 \u0432 \u0441\u0435\u043b\u0435"');
        $albumTitle = json_decode('"\u0411\u0443\u0434\u044c \u043a\u0430\u043a \u0434\u043e\u043c\u0430, \u041f\u0443\u0442\u043d\u0438\u043a..."');
        $originalTitle = json_decode('"\u041b\u0435\u0441\u043d\u0438\u043a"');

        Http::preventStrayRequests();
        Http::fake([
            'https://muzofond.fm/collections/artists/korol-i-shut' => Http::response(
                $this->cp1251Utf8Mojibake($this->artistPageHtml([
                    $this->trackRowHtml('1', '186', 'https://files.test/lesnik-live.mp3', $artist, $liveTitle),
                    $this->trackRowHtml('2', '190', 'https://files.test/panic.mp3', $artist, $panicTitle),
                ], false, <<<HTML
<h3>Р РµРєРѕРјРµРЅРґСѓРµРјС‹Рµ Р°Р»СЊР±РѕРјС‹</h3>
<div class="swiper">
    <div class="swiper-wrapper">
        <div class="swiper-slide">
            <a href="https://muzofond.fm/collections/albums/korol-i-shut%20bud-kak-doma-putnik">
                <span class="title">{$artist} - {$albumTitle}</span>
            </a>
        </div>
    </div>
</div>
HTML, $artist, 'korol-i-shut'), 1),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
            'https://muzofond.fm/collections/albums/korol-i-shut%20bud-kak-doma-putnik' => Http::response(
                $this->cp1251Utf8Mojibake($this->albumPageHtml($artist . ' - ' . $albumTitle, [
                    $this->trackRowHtml('11', '186', 'https://files.test/lesnik-original.mp3', $artist, $originalTitle),
                ]), 1),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
        ]);

        $pages = app(MuzofondTrackParser::class)->parse('https://muzofond.fm/collections/artists/korol-i-shut');
        $page = $pages[0] ?? null;

        $this->assertNotNull($page);
        $this->assertSame($artist, $page->artistName);

        $lesnikTracks = array_values(array_filter($page->tracks, fn ($track) => str_contains($track->title, $originalTitle)));

        $this->assertCount(2, $lesnikTracks);
        $this->assertEqualsCanonicalizing([
            $originalTitle,
            $liveTitle,
        ], array_map(fn ($track) => $track->title, $lesnikTracks));

        $originalTrack = collect($lesnikTracks)->firstWhere('title', $originalTitle);

        $this->assertNotNull($originalTrack);
        $this->assertSame($albumTitle, $originalTrack->albumTitle);
        $this->assertSame('https://files.test/lesnik-original.mp3', $originalTrack->audioUrl);
    }

    public function test_parse_artist_page_prefers_album_original_title_when_artist_page_reuses_same_audio_for_live_variant(): void
    {
        $artist = json_decode('"\u041a\u043e\u0440\u043e\u043b\u044c \u0438 \u0428\u0443\u0442"');
        $albumTitle = json_decode('"\u0411\u0443\u0434\u044c \u043a\u0430\u043a \u0434\u043e\u043c\u0430, \u041f\u0443\u0442\u043d\u0438\u043a..."');
        $originalTitle = json_decode('"\u041b\u0435\u0441\u043d\u0438\u043a"');
        $liveTitle = json_decode('"\u041b\u0435\u0441\u043d\u0438\u043a (Live)"');
        $sharedAudioUrl = 'https://files.test/lesnik-shared.mp3';

        Http::preventStrayRequests();
        Http::fake([
            'https://muzofond.fm/collections/artists/korol-i-shut' => Http::response(
                $this->artistPageHtml([
                    $this->trackRowHtml('1', '186', $sharedAudioUrl, $artist, $liveTitle),
                ], false, <<<HTML
<h3>Р РµРєРѕРјРµРЅРґСѓРµРјС‹Рµ Р°Р»СЊР±РѕРјС‹</h3>
<div class="swiper">
    <div class="swiper-wrapper">
        <div class="swiper-slide">
            <a href="https://muzofond.fm/collections/albums/korol-i-shut%20bud-kak-doma-putnik">
                <span class="title">{$artist} - {$albumTitle}</span>
            </a>
        </div>
    </div>
</div>
HTML, $artist, 'korol-i-shut'),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
            'https://muzofond.fm/collections/albums/korol-i-shut%20bud-kak-doma-putnik' => Http::response(
                $this->albumPageHtml($artist . ' - ' . $albumTitle, [
                    $this->trackRowHtml('11', '186', $sharedAudioUrl, $artist, $originalTitle),
                ]),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
        ]);

        $pages = app(MuzofondTrackParser::class)->parse('https://muzofond.fm/collections/artists/korol-i-shut');
        $page = $pages[0] ?? null;

        $this->assertNotNull($page);
        $this->assertCount(1, $page->tracks);
        $this->assertSame($originalTitle, $page->tracks[0]->title);
        $this->assertSame($albumTitle, $page->tracks[0]->albumTitle);
        $this->assertSame($sharedAudioUrl, $page->tracks[0]->audioUrl);
    }

    public function test_parse_artist_page_merges_distinct_versions_from_artist_search_results(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://muzofond.fm/collections/artists/mayot' => Http::response(
                $this->artistPageHtml([
                    $this->trackRowHtml('1', '186', 'https://files.test/snow-original.mp3', 'Mayot', 'Snow'),
                ], false, '', 'Mayot', 'mayot'),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
            'https://muzofond.fm/search/Mayot?artist=1' => Http::response(
                $this->searchPageHtml([
                    $this->trackRowHtml('11', '186', 'https://files.test/snow-original.mp3', 'Mayot', 'Snow'),
                    $this->trackRowHtml('12', '190', 'https://files.test/snow-live.mp3', 'Mayot', 'Snow (Live)'),
                    $this->trackRowHtml('13', '194', 'https://files.test/snow-instrumental.mp3', 'Mayot', 'Snow (Instrumental)'),
                ], 'Mayot'),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
        ]);

        $pages = app(MuzofondTrackParser::class)->parse('https://muzofond.fm/collections/artists/mayot');
        $page = $pages[0] ?? null;

        $this->assertNotNull($page);
        $snowTracks = array_values(array_filter($page->tracks, fn ($track) => str_contains($track->title, 'Snow')));

        $this->assertCount(3, $snowTracks);
        $this->assertEqualsCanonicalizing([
            'Snow',
            'Snow (Live)',
            'Snow (Instrumental)',
        ], array_map(fn ($track) => $track->title, $snowTracks));
        Http::assertSentCount(2);
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
                 вЂ”
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
    private function artistPageHtml(
        array $rows,
        bool $withPagination,
        string $tailHtml = '',
        string $artistName = 'Mayot',
        string $artistSlug = 'mayot',
    ): string {
        $artistSlug = trim($artistSlug) !== '' ? trim($artistSlug) : Str::slug($artistName);
        $artistSlug = $artistSlug !== '' ? $artistSlug : 'artist';
        $pagination = $withPagination
            ? '<div class="pagination"><ul><a href="https://muzofond.fm/collections/artists/' . $artistSlug . '"><li class="activePage">1</li></a><a href="https://muzofond.fm/collections/artists/' . $artistSlug . '/2"><li>2</li></a></ul></div>'
            : '';
        $recommendedAlbums = $withPagination
            ? <<<HTML
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
HTML
            : '';
        $rowsHtml = implode("\n", $rows);

        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<body>
<div class="tracksHeader"><h1>{$artistName}</h1><img src="https://img.test/{$artistSlug}.jpg"></div>
<ul class="mainSongs unstyled songsListen songs" data-type="tracks" data-url="https://muzofond.fm/collections/artists/{$artistSlug}">
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
    <title>{$heading} (2020) СЃР»СѓС€Р°С‚СЊ РјСѓР·С‹РєСѓ Р°Р»СЊР±РѕРјР° РѕРЅР»Р°Р№РЅ Р±РµСЃРїР»Р°С‚РЅРѕ</title>
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

    /**
     * @param  string[]  $rows
     */
    private function searchPageHtml(array $rows, string $searchTerm): string
    {
        $rowsHtml = implode("\n", $rows);
        $encodedSearchTerm = rawurlencode($searchTerm);

        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<body>
<h1>{$searchTerm}</h1>
<ul class="mainSongs unstyled songsListen songs" data-type="tracks" data-url="https://muzofond.fm/search/{$encodedSearchTerm}?artist=1">
{$rowsHtml}
</ul>
</body>
</html>
HTML;
    }

    private function trackRowHtml(string $id, string $durationSeconds, string $audioUrl, string $artist, string $track, ?string $audioFileId = null): string
    {
        $audioFileIdAttribute = $audioFileId !== null ? 'data-id="' . e($audioFileId) . '"' : '';

        return <<<HTML
<li class="item" data-id="{$id}" data-duration="{$durationSeconds}">
    <ul><li class="play" {$audioFileIdAttribute} data-url="{$audioUrl}"></li></ul>
    <div class="desc descriptionIs">
        <h3>
            <span class="artist">{$artist}</span>
             вЂ”
            <span class="track">{$track}</span>
        </h3>
    </div>
    <div class="duration enemy">03:06</div>
</li>
HTML;
    }

    private function cp1251Utf8Mojibake(string $value, int $passes = 1): string
    {
        $current = $value;

        for ($attempt = 0; $attempt < $passes; $attempt++) {
            $current = iconv('Windows-1251', 'UTF-8//IGNORE', $current);
        }

        return $current;
    }
}
