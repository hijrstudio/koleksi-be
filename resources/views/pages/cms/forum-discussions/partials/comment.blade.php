{{-- Satu komentar + balasannya (rekursif). $commentsByParent: parent_id (0 = utama) → komentar --}}
@php($author = $comment->authorPayload())
@php($hidden = $comment->status !== App\Models\ForumComment::PUBLISHED)
<div class="{{ $comment->depth > 0 ? 'ml-6 border-l-2 border-gray-100 pl-4 dark:border-gray-800' : '' }} mt-3">
    <div class="rounded-xl p-4 {{ $hidden ? 'bg-gray-100 opacity-70 dark:bg-gray-800' : 'bg-gray-50 dark:bg-white/[0.03]' }}" x-data="{ reply: false }">
        <div class="flex flex-wrap items-center gap-2 text-theme-sm">
            <span class="font-medium text-gray-800 dark:text-white/90">{{ $author['name'] }}</span>
            <span class="rounded-full px-2 py-0.5 text-theme-xs {{ $author['role'] === 'Moderator' ? 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-500' : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">{{ $author['role'] }}</span>
            <span class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $comment->created_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }}</span>
            @if ($hidden)
                <span class="text-theme-xs font-medium text-gray-500">· disembunyikan</span>
            @endif
        </div>
        <p class="mt-2 whitespace-pre-line text-theme-sm text-gray-700 dark:text-gray-300">{{ $comment->body }}</p>

        @can('update', $discussion)
            <div class="mt-3 flex flex-wrap items-center gap-3 text-theme-xs">
                @unless ($hidden)
                    <button type="button" @click="reply = !reply" class="text-brand-500 hover:underline">Balas</button>
                @endunless
                <form method="POST" action="{{ route('forum-comments.toggle', $comment) }}">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="text-gray-600 hover:underline dark:text-gray-400">{{ $hidden ? 'Tampilkan' : 'Sembunyikan' }}</button>
                </form>
                <form method="POST" action="{{ route('forum-comments.destroy', $comment) }}"
                    x-data @submit.prevent="if (confirm('Hapus komentar ini beserta semua balasannya?')) $el.submit()">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-error-600 hover:underline dark:text-error-500">Hapus</button>
                </form>
            </div>
            <form method="POST" action="{{ route('forum-discussions.comments.store', $discussion) }}" x-show="reply" x-cloak class="mt-3">
                @csrf
                <input type="hidden" name="parent_id" value="{{ $comment->id }}" />
                <textarea name="body" rows="3" required maxlength="{{ App\Models\ForumComment::BODY_MAX }}" placeholder="Balas sebagai Moderator"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"></textarea>
                <button type="submit" class="bg-brand-500 hover:bg-brand-600 mt-2 rounded-lg px-4 py-2 text-sm font-medium text-white">Kirim Balasan</button>
            </form>
        @endcan
    </div>

    @foreach ($commentsByParent->get($comment->id, collect()) as $child)
        @include('pages.cms.forum-discussions.partials.comment', ['comment' => $child])
    @endforeach
</div>
