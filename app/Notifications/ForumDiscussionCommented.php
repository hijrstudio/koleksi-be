<?php

namespace App\Notifications;

use App\Models\ForumDiscussion;
use App\Models\Member;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Lonceng website (tanpa e-mail): diskusi milik member dikomentari orang lain. */
class ForumDiscussionCommented extends Notification
{
    use Queueable;

    public function __construct(public readonly ForumDiscussion $discussion, public readonly string $actor) {}

    /** @return list<string> */
    public function via(Member $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'type' => 'forum.comment',
            'title' => 'Komentar baru di diskusi Anda',
            'message' => "{$this->actor} mengomentari \"{$this->discussion->title}\".",
            'actor_name' => $this->actor,
            'discussion_slug' => $this->discussion->slug,
            'discussion_title' => $this->discussion->title,
        ];
    }
}
