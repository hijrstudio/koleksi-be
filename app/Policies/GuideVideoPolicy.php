<?php

namespace App\Policies;

use App\Models\GuideVideo;
use App\Models\User;

class GuideVideoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('guide-videos.view');
    }

    public function create(User $user): bool
    {
        return $user->can('guide-videos.create');
    }

    public function update(User $user, GuideVideo $model): bool
    {
        return $user->can('guide-videos.update');
    }

    public function delete(User $user, GuideVideo $model): bool
    {
        return $user->can('guide-videos.delete');
    }
}
