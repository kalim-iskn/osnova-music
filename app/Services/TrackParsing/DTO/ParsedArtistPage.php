<?php

namespace App\Services\TrackParsing\DTO;

final class ParsedArtistPage
{
    /**
     * @param  ParsedTrack[]  $tracks
     */
    public function __construct(
        public string $artistName,
        public string $artistSlug,
        public ?string $imageUrl,
        public array $tracks,
        /** @var array<int, array{url: string, title: string, complete: bool, tracks: array}> */
        public array $albumPages = [],
    ) {
    }
}
