<?php

namespace App\Policies;

use App\Models\GalleryAlbum;
use App\Models\User;

class GalleryAlbumPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('gallery-albums.view');
    }

    public function create(User $user): bool
    {
        return $user->can('gallery-albums.create');
    }

    public function update(User $user, GalleryAlbum $model): bool
    {
        return $user->can('gallery-albums.update');
    }

    public function delete(User $user, GalleryAlbum $model): bool
    {
        return $user->can('gallery-albums.delete');
    }
}
