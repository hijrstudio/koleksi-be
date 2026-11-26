<?php

namespace App\Notifications;

use App\Models\ForumDiscussion;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Lonceng CMS (tanpa e-mail): member membuat diskusi baru di forum (langsung terbit, admin bisa memoderasi). */
class NewForumDiscussion extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ForumDiscussion $discussion) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        $author = $this->discussion->authorPayload()['name'];

        return [
            'type' => 'forum.discussion_created',
            'title' => 'Diskusi forum baru',
            'message' => "{$author} membuat diskusi \"{$this->discussion->title}\".",
            'discussion_id' => $this->discussion->id,
            'action_url' => route('forum-discussions.show', $this->discussion),
        ];
    }
}
