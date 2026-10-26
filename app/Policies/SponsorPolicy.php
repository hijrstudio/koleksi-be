<?php

namespace App\Policies;

use App\Models\Sponsor;
use App\Models\User;

class SponsorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sponsors.view');
    }

    public function create(User $user): bool
    {
        return $user->can('sponsors.create');
    }

    public function update(User $user, Sponsor $model): bool
    {
        return $user->can('sponsors.update');
    }

    public function delete(User $user, Sponsor $model): bool
    {
        return $user->can('sponsors.delete');
    }
}
