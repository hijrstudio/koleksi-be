<?php

namespace App\Policies;

use App\Models\EventCategory;
use App\Models\User;

class EventCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('event-categories.view');
    }

    public function create(User $user): bool
    {
        return $user->can('event-categories.create');
    }

    public function update(User $user, EventCategory $model): bool
    {
        return $user->can('event-categories.update');
    }

    public function delete(User $user, EventCategory $model): bool
    {
        return $user->can('event-categories.delete');
    }
}
