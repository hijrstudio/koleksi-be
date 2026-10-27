<?php

namespace App\Policies;

use App\Models\GuideRegulation;
use App\Models\User;

class GuideRegulationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('guide-regulations.view');
    }

    public function create(User $user): bool
    {
        return $user->can('guide-regulations.create');
    }

    public function update(User $user, GuideRegulation $model): bool
    {
        return $user->can('guide-regulations.update');
    }

    public function delete(User $user, GuideRegulation $model): bool
    {
        return $user->can('guide-regulations.delete');
    }
}
