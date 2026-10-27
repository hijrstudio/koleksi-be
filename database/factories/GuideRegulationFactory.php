<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\GuideRegulation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuideRegulation>
 */
class GuideRegulationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => 'PERMEN NO. '.fake()->numberBetween(1, 99).' TAHUN '.fake()->numberBetween(2019, 2026),
            'file_pdf' => GuideRegulation::DIRECTORY.'/'.fake()->uuid().'.pdf',
            'status' => ContentStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => ContentStatus::Published]);
    }
}
