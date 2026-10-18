<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleBrand;

class VehicleBrandPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('vehicle-brands.view');
    }

    public function create(User $user): bool
    {
        return $user->can('vehicle-brands.create');
    }

    public function update(User $user, VehicleBrand $model): bool
    {
        return $user->can('vehicle-brands.update');
    }

    public function delete(User $user, VehicleBrand $model): bool
    {
        return $user->can('vehicle-brands.delete');
    }
}
