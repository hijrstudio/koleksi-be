<?php

namespace App\Policies;

use App\Models\EventVideo;
use App\Models\User;

class EventVideoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('event-videos.view');
    }

    public function create(User $user): bool
    {
        return $user->can('event-videos.create');
    }

    public function update(User $user, EventVideo $model): bool
    {
        return $user->can('event-videos.update');
    }

    public function delete(User $user, EventVideo $model): bool
    {
        return $user->can('event-videos.delete');
    }
}
