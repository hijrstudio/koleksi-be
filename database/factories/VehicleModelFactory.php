<?php

namespace Database\Factories;

use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleModel>
 */
class VehicleModelFactory extends Factory
{
    /**
     * Default: tanpa spesifikasi (hanya untuk dropdown). Pakai withSpecs() untuk tampil di EV Garage.
     */
    public function definition(): array
    {
        return [
            'vehicle_brand_id' => VehicleBrand::factory(),
            'name' => fake()->unique()->bothify('Model ##??'),
            'is_active' => true,
        ];
    }

    public function withSpecs(array $specs = []): static
    {
        return $this->state(array_merge([
            'battery_kwh' => 60.5,
            'ac_kw' => 11,
            'dc_kw' => 150,
            'range_km' => 450,
        ], $specs));
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
