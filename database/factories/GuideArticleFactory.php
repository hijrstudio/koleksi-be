<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\GuideArticle;
use App\Models\GuideCategory;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuideArticle>
 */
class GuideArticleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'guide_category_id' => GuideCategory::factory(),
            'title' => ['id' => fake()->sentence(5), 'en' => fake()->sentence(5)],
            'content' => ['id' => '<p>'.fake()->paragraph().'</p>', 'en' => '<p>'.fake()->paragraph().'</p>'],
            'status' => ContentStatus::Draft,
        ];
    }

    /**
     * Default penulis: admin (user baru). Penulis tidak fillable, jadi diisi lewat afterMaking.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (GuideArticle $article) {
            if ($article->author_member_id === null && $article->author_user_id === null) {
                $article->setAuthorUser(User::factory()->create()->id);
            }
        });
    }

    public function byMember(?Member $member = null): static
    {
        return $this->afterMaking(fn (GuideArticle $article) => $article->setAuthorMember(($member ?? Member::factory()->create())->id));
    }

    public function byUser(User $user): static
    {
        return $this->afterMaking(fn (GuideArticle $article) => $article->setAuthorUser($user->id));
    }

    public function published(): static
    {
        return $this->state(['status' => ContentStatus::Published]);
    }
}
