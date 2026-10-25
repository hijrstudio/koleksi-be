<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\GuidePlaybook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuidePlaybook>
 */
class GuidePlaybookFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => ['id' => fake()->sentence(5), 'en' => fake()->sentence(5)],
            'cover_image' => GuidePlaybook::DIRECTORY.'/'.fake()->uuid().'.png',
            'file_pdf' => GuidePlaybook::DIRECTORY.'/'.fake()->uuid().'.pdf',
            'status' => ContentStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => ContentStatus::Published]);
    }
}
