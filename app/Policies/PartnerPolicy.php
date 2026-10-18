<?php

namespace App\Policies;

use App\Models\Partner;
use App\Models\User;

class PartnerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('partners.view');
    }

    public function create(User $user): bool
    {
        return $user->can('partners.create');
    }

    public function update(User $user, Partner $model): bool
    {
        return $user->can('partners.update');
    }

    public function delete(User $user, Partner $model): bool
    {
        return $user->can('partners.delete');
    }
}
