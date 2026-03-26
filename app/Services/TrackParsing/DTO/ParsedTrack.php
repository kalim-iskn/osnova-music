<?php

namespace App\Services\TrackParsing\DTO;

final class ParsedTrack
{
    /**
     * @param  string[]  $artistNames
     * @param  string[]  $genres
     */
    public function __construct(
        public string $title,
        public int $durationSeconds,
        public string $audioUrl,
        public ?string $albumTitle,
        public array $artistNames = [],
        public ?int $releaseYear = null,
        public array $genres = [],
    ) {
    }
}
