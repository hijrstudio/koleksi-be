<?php

namespace App\Policies;

use App\Models\ManagementPeriod;
use App\Models\User;

class ManagementPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('management.view');
    }

    public function create(User $user): bool
    {
        return $user->can('management.create');
    }

    public function update(User $user, ManagementPeriod $managementPeriod): bool
    {
        return $user->can('management.update');
    }

    public function delete(User $user, ManagementPeriod $managementPeriod): bool
    {
        return $user->can('management.delete');
    }
}
