<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Enums\MediaType;
use App\Models\HeroBanner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HeroBanner>
 */
class HeroBannerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'file_path' => HeroBanner::DIRECTORY.'/'.fake()->uuid().'.jpg',
            'file_type' => MediaType::Image,
            'mime_type' => 'image/jpeg',
            'title' => [
                'id' => fake()->sentence(4),
                'en' => fake()->sentence(4),
            ],
            'status' => ContentStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => ContentStatus::Published]);
    }
}
