<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\EventVideo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventVideo>
 */
class EventVideoFactory extends Factory
{
    public function definition(): array
    {
        return [
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
