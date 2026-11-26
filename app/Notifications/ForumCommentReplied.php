<?php

namespace App\Notifications;

use App\Models\ForumDiscussion;
use App\Models\Member;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Lonceng website (tanpa e-mail): komentar member dibalas orang lain. */
class ForumCommentReplied extends Notification
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
            'type' => 'forum.reply',
            'title' => 'Balasan untuk komentar Anda',
            'message' => "{$this->actor} membalas komentar Anda di \"{$this->discussion->title}\".",
            'actor_name' => $this->actor,
            'discussion_slug' => $this->discussion->slug,
            'discussion_title' => $this->discussion->title,
        ];
    }
}
