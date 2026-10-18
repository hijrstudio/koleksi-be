<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Enums\NewsCategory;
use App\Models\Member;
use App\Models\News;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<News>
 */
class NewsFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category' => fake()->randomElement(NewsCategory::cases()),
            'title' => ['id' => fake()->sentence(6), 'en' => fake()->sentence(6)],
            'content' => ['id' => '<p>'.fake()->paragraph().'</p>', 'en' => '<p>'.fake()->paragraph().'</p>'],
            'status' => ContentStatus::Draft,
        ];
    }

    /**
     * Default penulis: admin (user baru). Penulis tidak fillable, jadi diisi lewat afterMaking.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (News $news) {
            if ($news->author_member_id === null && $news->author_user_id === null) {
                $news->setAuthorUser(User::factory()->create()->id);
            }
        });
    }

    public function byMember(?Member $member = null): static
    {
        return $this->afterMaking(fn (News $news) => $news->setAuthorMember(($member ?? Member::factory()->create())->id));
    }

    public function published(): static
    {
        return $this->state(['status' => ContentStatus::Published]);
    }
}
