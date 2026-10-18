<?php

namespace App\Policies;

use App\Models\GuideArticle;
use App\Models\User;

class GuideArticlePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('guide-articles.view');
    }

    public function create(User $user): bool
    {
        return $user->can('guide-articles.create');
    }

    public function update(User $user, GuideArticle $model): bool
    {
        return $user->can('guide-articles.update');
    }

    public function delete(User $user, GuideArticle $model): bool
    {
        return $user->can('guide-articles.delete');
    }
}
