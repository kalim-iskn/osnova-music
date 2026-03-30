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

    public function test_song_search_queries_include_album_variants_when_album_is_known(): void
    {
        $queries = GeniusNameMatcher::songSearchQueries('Mayot', 'Snow', 'Ghetto Garden');

        $this->assertContains('Mayot Snow Ghetto Garden', $queries);
        $this->assertTrue(
            collect($queries)->contains(fn (string $query) => str_contains($query, 'Snow') && str_contains($query, 'Ghetto Garden'))
        );
    }

    public function test_album_storage_value_preserves_symbolic_album_titles(): void
    {
        $this->assertSame('√ (Racine carree)', GeniusNameMatcher::albumStorageValue('в€љ (Racine carree)'));
    }
    public function test_album_storage_value_removes_english_translation_suffixes(): void
    {
        $this->assertSame('БОГ РЭПА', GeniusNameMatcher::albumStorageValue('БОГ РЭПА (RAPGOD)'));
        $this->assertSame('ПРОКЛЯТАЯ ПЕЧАТЬ', GeniusNameMatcher::albumStorageValue('ПРОКЛЯТАЯ ПЕЧАТЬ (CURSED SEAL)'));
    }
}
