<?php

namespace App\Policies;

use App\Models\ForumPoll;
use App\Models\User;

/** Admin CMS (izin forum-polls.*). Member website tidak memakai policy ini (lihat Api\Member\ForumPollController). */
class ForumPollPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('forum-polls.view');
    }

    public function view(User $user, ForumPoll $model): bool
    {
        return $user->can('forum-polls.view');
    }

    public function create(User $user): bool
    {
        return $user->can('forum-polls.create');
    }

    public function update(User $user, ForumPoll $model): bool
    {
        return $user->can('forum-polls.update');
    }

    public function delete(User $user, ForumPoll $model): bool
    {
        return $user->can('forum-polls.delete');
    }
}
