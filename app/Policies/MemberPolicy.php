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

    /** Edit data member (No. lambung). */
    public function update(User $user, Member $member): bool
    {
        return $user->can('members.update');
    }

    /** Hapus member (soft delete). */
    public function delete(User $user, Member $member): bool
    {
        return $user->can('members.delete');
    }

    /** Pengaturan pendaftaran anggota baru (nomor awal No. lambung). */
    public function updateSettings(User $user): bool
    {
        return $user->can('members.update');
    }
}
