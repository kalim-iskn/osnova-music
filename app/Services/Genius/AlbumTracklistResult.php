<?php

namespace App\Services\Genius;

final readonly class AlbumTracklistResult
{
    /**
     * @param array<int, array<string, mixed>> $tracks
     */
    public function __construct(
        public array $tracks,
        public bool $complete,
        public ?string $error = null,
        public int $pagesFetched = 0,
    ) {}
}
