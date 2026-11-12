<?php

namespace App\Services\Genius;

use RuntimeException;

final class IncompleteGeniusAlbumTracklistException extends RuntimeException
{
    public static function forAlbum(int $albumId, ?string $reason = null): self
    {
        return new self(sprintf(
            'Genius album tracklist %d is incomplete; retry the artist parsing job. %s',
            $albumId,
            $reason ?: 'Unknown Genius tracklist error.',
        ));
    }
}
