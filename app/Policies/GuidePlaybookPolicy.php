<?php

namespace App\Policies;

use App\Models\GuidePlaybook;
use App\Models\User;

class GuidePlaybookPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('guide-playbooks.view');
    }

    public function create(User $user): bool
    {
        return $user->can('guide-playbooks.create');
    }

    public function update(User $user, GuidePlaybook $model): bool
    {
        return $user->can('guide-playbooks.update');
    }

    public function delete(User $user, GuidePlaybook $model): bool
    {
        return $user->can('guide-playbooks.delete');
    }
}
