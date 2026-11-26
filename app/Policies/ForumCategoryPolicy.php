<?php

namespace App\Policies;

use App\Models\ForumCategory;
use App\Models\User;

/** Admin CMS (izin forum-categories.*). Member website tidak memakai policy ini (lihat Api\Member\ForumController). */
class ForumCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('forum-categories.view');
    }

    public function view(User $user, ForumCategory $model): bool
    {
        return $user->can('forum-categories.view');
    }

    public function create(User $user): bool
    {
        return $user->can('forum-categories.create');
    }

    public function update(User $user, ForumCategory $model): bool
    {
        return $user->can('forum-categories.update');
    }

    public function delete(User $user, ForumCategory $model): bool
    {
        return $user->can('forum-categories.delete');
    }
}
