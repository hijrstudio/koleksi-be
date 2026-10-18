<?php

namespace App\Policies;

use App\Models\User;

/**
 * Data singleton: tidak ada create/delete terpisah, simpan pertama kali = update.
 */
class AboutUsPolicy
{
    public function view(User $user): bool
    {
        return $user->can('about-us.view');
    }

    public function update(User $user): bool
    {
        return $user->can('about-us.update');
    }
}
