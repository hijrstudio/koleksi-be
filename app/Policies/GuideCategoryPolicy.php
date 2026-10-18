<?php

namespace App\Policies;

use App\Models\GuideCategory;
use App\Models\User;

class GuideCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('guide-categories.view');
    }

    public function create(User $user): bool
    {
        return $user->can('guide-categories.create');
    }

    public function update(User $user, GuideCategory $model): bool
    {
        return $user->can('guide-categories.update');
    }

    public function delete(User $user, GuideCategory $model): bool
    {
        return $user->can('guide-categories.delete');
    }
}
