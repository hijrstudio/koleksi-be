<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\GuideCategory;
use App\Models\GuideVideo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuideVideo>
 */
class GuideVideoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'guide_category_id' => GuideCategory::factory(),
            'title' => ['id' => fake()->sentence(5), 'en' => fake()->sentence(5)],
            'youtube_id' => fake()->regexify('[A-Za-z0-9_-]{11}'),
            'status' => ContentStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => ContentStatus::Published]);
    }
}
