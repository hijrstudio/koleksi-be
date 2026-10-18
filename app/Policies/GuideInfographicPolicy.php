<?php

namespace App\Policies;

use App\Models\GuideInfographic;
use App\Models\User;

class GuideInfographicPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('guide-infographics.view');
    }

    public function create(User $user): bool
    {
        return $user->can('guide-infographics.create');
    }

    public function update(User $user, GuideInfographic $model): bool
    {
        return $user->can('guide-infographics.update');
    }

    public function delete(User $user, GuideInfographic $model): bool
    {
        return $user->can('guide-infographics.delete');
    }
}
