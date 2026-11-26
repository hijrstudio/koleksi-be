@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    @include('pages.cms.forum-discussions.partials.flash')

    @php($author = $discussion->authorPayload())

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('forum-discussions.index') }}" class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">← Kembali ke daftar diskusi</a>
        <div class="flex items-center gap-2">
            @can('update', $discussion)
                <a href="{{ route('forum-discussions.edit', $discussion) }}"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400">Edit</a>
            @endcan
            @can('delete', $discussion)
                <form method="POST" action="{{ route('forum-discussions.destroy', $discussion) }}"
                    x-data @submit.prevent="if (confirm('Hapus diskusi ini? Diskusi hilang dari website.')) $el.submit()">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="border-error-300 text-error-600 hover:bg-error-50 dark:border-error-500/40 dark:text-error-500 rounded-lg border px-3 py-1.5 text-theme-sm font-medium">Hapus</button>
                </form>
            @endcan
        </div>
    </div>

    <div class="mb-6 rounded-2xl border border-gray-200 bg-white p-5 sm:p-6 dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="flex flex-wrap items-center gap-2 text-theme-xs">
            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ $discussion->category?->name }}</span>
            <span class="rounded-full px-2.5 py-0.5 {{ $discussion->isPublished() ? 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-500' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' }}">
                {{ $discussion->isPublished() ? 'Tampil' : 'Disembunyikan' }}
            </span>
            @if ($discussion->is_pinned)<span class="rounded-full bg-brand-50 px-2.5 py-0.5 text-brand-500">Disematkan</span>@endif
            @if ($discussion->is_locked)<span class="rounded-full bg-warning-50 px-2.5 py-0.5 text-warning-700">Komentar ditutup</span>@endif
        </div>
        <h2 class="mt-3 text-xl font-semibold text-gray-800 dark:text-white/90">{{ $discussion->title }}</h2>
        <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
            {{ $author['name'] }} ({{ $author['role'] === 'Moderator' ? 'Admin' : 'Member' }})
            · {{ $discussion->created_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }}
            · {{ number_format($discussion->comments_count) }} komentar · {{ number_format($discussion->views_count) }} dilihat
            · rating {{ $discussion->rating_count ? $discussion->ratingAverage().' dari '.$discussion->rating_count.' member' : 'belum ada' }}
        </p>
        @if ($discussion->imageUrl())
            <img src="{{ $discussion->imageUrl() }}" alt="" class="mt-4 max-h-72 rounded-xl object-cover" />
        @endif
        <p class="mt-4 whitespace-pre-line text-theme-sm text-gray-700 dark:text-gray-300">{{ $discussion->body }}</p>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white p-5 sm:p-6 dark:border-gray-800 dark:bg-white/[0.03]">
        <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Komentar</h3>

        @can('update', $discussion)
            <form method="POST" action="{{ route('forum-discussions.comments.store', $discussion) }}" class="mt-4">
                @csrf
                <textarea name="body" rows="3" required maxlength="{{ App\Models\ForumComment::BODY_MAX }}" placeholder="Tulis komentar sebagai Moderator"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"></textarea>
                <button type="submit" class="bg-brand-500 hover:bg-brand-600 mt-2 rounded-lg px-4 py-2 text-sm font-medium text-white">Kirim Komentar</button>
            </form>
        @endcan

        @forelse ($commentsByParent->get(0, collect()) as $comment)
            @include('pages.cms.forum-discussions.partials.comment', ['comment' => $comment])
        @empty
            <p class="mt-4 text-theme-sm text-gray-500 dark:text-gray-400">Belum ada komentar.</p>
        @endforelse
    </div>
@endsection
