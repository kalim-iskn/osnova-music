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
        public ?int $trackNumber = null,
        public array $artistNames = [],
        public ?int $releaseYear = null,
        public array $genres = [],
    ) {
    }
}
