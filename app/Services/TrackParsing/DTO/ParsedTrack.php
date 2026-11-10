<?php

namespace App\Services\TrackParsing\DTO;

final class ParsedTrack
{
    public const SOURCE_ARTIST_PAGE = 'artist_page';

    public const SOURCE_ALBUM_PAGE = 'album_page';

    /**
     * @param  string[]  $artistNames
     * @param  string[]  $genres
     */
    public function __construct(
        public string $title,
        public int $durationSeconds,
        public string $audioUrl,
        public ?string $albumTitle,
        public ?int $trackNumber = null,
        public array $artistNames = [],
        public ?int $releaseYear = null,
        public array $genres = [],
        public string $sourceType = self::SOURCE_ARTIST_PAGE,
        public ?string $audioFileId = null,
        /** @var array<int, array{title: string, source_type: string, track_number: ?int}> */
        public array $albumAppearances = [],
        public ?string $albumCoverImageUrl = null,
    ) {
    }
}
