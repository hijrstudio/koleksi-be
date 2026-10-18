<?php

namespace App\Policies;

use App\Models\Faq;
use App\Models\User;

class FaqPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('faqs.view');
    }

    public function create(User $user): bool
    {
        return $user->can('faqs.create');
    }

    public function update(User $user, Faq $faq): bool
    {
        return $user->can('faqs.update');
    }

    public function delete(User $user, Faq $faq): bool
    {
        return $user->can('faqs.delete');
    }
}
