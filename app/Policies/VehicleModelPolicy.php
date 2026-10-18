<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleModel;

class VehicleModelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('vehicle-models.view');
    }

    public function create(User $user): bool
    {
        return $user->can('vehicle-models.create');
    }

    public function update(User $user, VehicleModel $model): bool
    {
        return $user->can('vehicle-models.update');
    }

    public function delete(User $user, VehicleModel $model): bool
    {
        return $user->can('vehicle-models.delete');
    }
}
