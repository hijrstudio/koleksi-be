<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\GalleryAlbum;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GalleryAlbum>
 */
class GalleryAlbumFactory extends Factory
{
    public function definition(): array
    {
        return [
            'image' => GalleryAlbum::DIRECTORY.'/'.fake()->uuid().'.jpg',
            'title' => ['id' => fake()->sentence(4), 'en' => fake()->sentence(4)],
            'gallery_date' => fake()->dateTimeBetween('-2 years')->format('Y-m-d'),
            'status' => ContentStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => ContentStatus::Published]);
    }
}
