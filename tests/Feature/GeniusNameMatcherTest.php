<?php

namespace Tests\Feature;

use App\Services\Genius\GeniusNameMatcher;
use Tests\TestCase;

class GeniusNameMatcherTest extends TestCase
{
    public function test_normalize_stored_track_title_removes_invisible_markers(): void
    {
        $input = "\u{200C}\u{200D}\u{200D}xaNNy";

        $this->assertSame('xaNNy', GeniusNameMatcher::storageValue($input));
        $this->assertSame('xaNNy', GeniusNameMatcher::normalizeStoredTrackTitle($input));
    }

    public function test_split_artist_credits_uses_anchor_for_and_separator(): void
    {
        $artists = GeniusNameMatcher::splitArtistCredits('Saluki and Anikv', 'Saluki');

        $this->assertSame(['Saluki', 'Anikv'], $artists);
    }
}
