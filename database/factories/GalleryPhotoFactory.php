<?php

namespace Database\Factories;

use App\Models\GalleryAlbum;
use App\Models\GalleryPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GalleryPhoto>
 */
class GalleryPhotoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'gallery_album_id' => GalleryAlbum::factory(),
            'image' => GalleryPhoto::DIRECTORY.'/'.now()->format('Y/m').'/'.Str::random(40).'.jpg',
            'sort_order' => 1,
        ];
    }
}
