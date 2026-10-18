<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('events.view');
    }

    public function create(User $user): bool
    {
        return $user->can('events.create');
    }

    public function update(User $user, Event $model): bool
    {
        return $user->can('events.update');
    }

    public function delete(User $user, Event $model): bool
    {
        return $user->can('events.delete');
    }
}
