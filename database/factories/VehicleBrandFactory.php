<?php

namespace Database\Factories;

use App\Models\VehicleBrand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleBrand>
 */
class VehicleBrandFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->lexify('Brand ????'),
            'logo_path' => null,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
