<?php

namespace App\Services\TrackParsing\DTO;

final class ParsedTrack
{
    /**
     * @param  string[]  $artistNames
     */
    public function __construct(
        public string $title,
        public int $durationSeconds,
        public string $audioUrl,
        public ?string $albumTitle,
        public array $artistNames = [],
    ) {
    }
}
