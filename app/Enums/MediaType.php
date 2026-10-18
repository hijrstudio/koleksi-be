<?php

namespace App\Enums;

enum MediaType: string
{
    case Image = 'image';
    case Video = 'video';

    public static function fromMime(string $mime): self
    {
        return str_starts_with($mime, 'video/') ? self::Video : self::Image;
    }
}
