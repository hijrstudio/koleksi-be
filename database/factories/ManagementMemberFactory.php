<?php

namespace Database\Factories;

use App\Enums\ManagementGroup;
use App\Models\ManagementMember;
use App\Models\ManagementPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManagementMember>
 */
class ManagementMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'management_period_id' => ManagementPeriod::factory(),
            'group' => ManagementGroup::Bidang,
            'name' => fake()->name(),
            'position' => ['id' => 'Bidang '.fake()->word(), 'en' => fake()->word().' Division'],
            'photo' => null,
            'sort_order' => 1,
        ];
    }

    public function penasihat(): static
    {
        return $this->state(['group' => ManagementGroup::Penasihat]);
    }
}
