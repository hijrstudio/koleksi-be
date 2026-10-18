<?php

namespace App\Policies;

use App\Models\HeroBanner;
use App\Models\User;

class HeroBannerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('hero-banners.view');
    }

    public function create(User $user): bool
    {
        return $user->can('hero-banners.create');
    }

    public function update(User $user, HeroBanner $heroBanner): bool
    {
        return $user->can('hero-banners.update');
    }

    public function delete(User $user, HeroBanner $heroBanner): bool
    {
        return $user->can('hero-banners.delete');
    }
}
