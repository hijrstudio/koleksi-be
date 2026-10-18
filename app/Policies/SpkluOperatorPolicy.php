<?php

namespace App\Policies;

use App\Models\SpkluOperator;
use App\Models\User;

class SpkluOperatorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('spklu-operators.view');
    }

    public function create(User $user): bool
    {
        return $user->can('spklu-operators.create');
    }

    public function update(User $user, SpkluOperator $model): bool
    {
        return $user->can('spklu-operators.update');
    }

    public function delete(User $user, SpkluOperator $model): bool
    {
        return $user->can('spklu-operators.delete');
    }
}
