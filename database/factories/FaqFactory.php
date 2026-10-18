<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\Faq;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Faq>
 */
class FaqFactory extends Factory
{
    public function definition(): array
    {
        return [
            'question' => [
                'id' => rtrim(fake()->sentence(6), '.').'?',
                'en' => rtrim(fake()->sentence(6), '.').'?',
            ],
            'answer' => [
                'id' => '<p>'.fake()->paragraph().'</p>',
                'en' => '<p>'.fake()->paragraph().'</p>',
            ],
            'sort_order' => fake()->numberBetween(1, 100),
            'status' => ContentStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => ContentStatus::Published]);
    }
}
