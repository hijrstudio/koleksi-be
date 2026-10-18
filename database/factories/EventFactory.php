<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\Event;
use App\Models\EventCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-1 month', '+2 months');

        return [
            'event_category_id' => EventCategory::factory(),
            'title' => ['id' => fake()->sentence(4), 'en' => fake()->sentence(4)],
            'start_date' => $start->format('Y-m-d'),
            'end_date' => (clone $start)->modify('+'.fake()->numberBetween(0, 3).' days')->format('Y-m-d'),
            'description' => ['id' => '<p>'.fake()->paragraph().'</p>', 'en' => null],
            'location' => fake()->city(),
            'status' => ContentStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => ContentStatus::Published]);
    }

    public function between(string $start, ?string $end = null): static
    {
        return $this->state(['start_date' => $start, 'end_date' => $end ?? $start]);
    }
}
