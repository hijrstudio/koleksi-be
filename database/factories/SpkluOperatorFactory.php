<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\SpkluOperator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpkluOperator>
 */
class SpkluOperatorFactory extends Factory
{
    public function definition(): array
    {
        $brand = fake()->unique()->company();

        return [
            'nama_operator' => 'PT '.$brand,
            'brand_spklu' => $brand,
            'logo' => SpkluOperator::DIRECTORY.'/'.fake()->uuid().'.png',
            'aplikasi' => $brand.' App',
            'link_playstore' => 'https://play.google.com/store/apps/details?id=com.example.'.fake()->unique()->lexify('??????'),
            'link_appstore' => 'https://apps.apple.com/id/app/example/id'.fake()->numerify('##########'),
            'status' => ContentStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => ContentStatus::Published]);
    }
}
