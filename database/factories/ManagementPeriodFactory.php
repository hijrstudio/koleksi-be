<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\ManagementPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManagementPeriod>
 */
class ManagementPeriodFactory extends Factory
{
    public function definition(): array
    {
        $start = fake()->unique()->numberBetween(2000, 2100);

        return [
            'start_year' => $start,
            'end_year' => $start + 5,
            'status' => ContentStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => ContentStatus::Published]);
    }
}
