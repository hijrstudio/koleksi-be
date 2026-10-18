<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\GuideCategory;
use App\Models\GuideInfographic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuideInfographic>
 */
class GuideInfographicFactory extends Factory
{
    public function definition(): array
    {
        return [
            'guide_category_id' => GuideCategory::factory(),
            'title' => ['id' => fake()->sentence(5), 'en' => fake()->sentence(5)],
            'image' => GuideInfographic::DIRECTORY.'/'.fake()->uuid().'.png',
            'status' => ContentStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => ContentStatus::Published]);
    }
}
