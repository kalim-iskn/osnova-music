<?php

namespace App\Support;

final class ReleaseType
{
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return match (mb_strtolower(trim($value))) {
            'single', 'сингл' => 'single',
            'album', 'альбом' => 'album',
            'ep', 'мини-альбом', 'мини альбом' => 'ep',
            'mixtape', 'микстейп' => 'mixtape',
            'compilation', 'сборник' => 'compilation',
            default => null,
        };
    }
}
