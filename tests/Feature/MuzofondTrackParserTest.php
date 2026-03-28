<?php

namespace Tests\Feature;

use App\Services\TrackParsing\MuzofondTrackParser;
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
}
