<?php

namespace App\Policies;

use App\Models\ForumDiscussion;
use App\Models\User;

/** Admin CMS (izin forum-discussions.*). Member website tidak memakai policy ini (lihat Api\Member\ForumController). */
class ForumDiscussionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('forum-discussions.view');
    }

    public function view(User $user, ForumDiscussion $model): bool
    {
        return $user->can('forum-discussions.view');
    }

    public function create(User $user): bool
    {
        return $user->can('forum-discussions.create');
    }

    public function update(User $user, ForumDiscussion $model): bool
    {
        return $user->can('forum-discussions.update');
    }

    public function delete(User $user, ForumDiscussion $model): bool
    {
        return $user->can('forum-discussions.delete');
    }
}
