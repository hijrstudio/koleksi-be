<?php

namespace App\Policies;

use App\Models\Member;
use App\Models\User;

class MemberPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('members.view');
    }

    public function view(User $user, Member $member): bool
    {
        return $user->can('members.view');
    }

    public function updateStatus(User $user, Member $member): bool
    {
        return $user->can('members.update-status');
    }

    /** Lihat antrian & detail pendaftaran baru, beserta dokumennya. */
    public function viewRegistrations(User $user): bool
    {
        return $user->can('members.review') || $user->can('members.view');
    }

    /** Setujui / tolak pendaftaran. */
    public function review(User $user, Member $member): bool
    {
        return $user->can('members.review');
    }
}
