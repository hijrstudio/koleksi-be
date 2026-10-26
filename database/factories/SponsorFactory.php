<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\Sponsor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sponsor>
 */
class SponsorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->company(),
            'logo' => Sponsor::DIRECTORY.'/'.fake()->uuid().'.png',
            'url' => fake()->url(),
            'sort' => fake()->numberBetween(1, 100),
            'status' => ContentStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => ContentStatus::Published]);
    }
}
