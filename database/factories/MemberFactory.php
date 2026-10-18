<?php

namespace Database\Factories;

use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'no_lambung' => fake()->unique()->numberBetween(1, 99999),
            'nama_lengkap' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'no_hp' => '08'.fake()->unique()->numerify('##########'),
            'tgl_bergabung' => fake()->dateTimeBetween('2021-08-01', 'now'),
            'password' => 'password',
            'must_change_password' => true,
        ];
    }

    public function active(): static
    {
        return $this->afterMaking(fn (Member $m) => $m->forceFill(['status' => Member::STATUS_ACTIVE]));
    }
}
