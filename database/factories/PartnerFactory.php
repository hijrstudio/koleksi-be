<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Partner>
 */
class PartnerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->company(),
            'logo' => Partner::DIRECTORY.'/'.fake()->uuid().'.png',
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
