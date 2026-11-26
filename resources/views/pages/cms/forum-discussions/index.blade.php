@extends('layouts.app')

@php
    $control = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    @include('pages.cms.forum-discussions.partials.flash')

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="flex flex-col gap-4 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
            <form method="GET" action="{{ route('forum-discussions.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari judul..." class="{{ $control }} sm:w-64" />
                <select name="category" class="{{ $control }} select-chevron">
                    <option value="">Semua kategori</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) request('category') === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
                <select name="status" class="{{ $control }} select-chevron">
                    <option value="">Semua status</option>
                    <option value="published" @selected(request('status') === 'published')>Tampil</option>
                    <option value="hidden" @selected(request('status') === 'hidden')>Disembunyikan</option>
                </select>
                <button type="submit" class="shadow-theme-xs inline-flex h-11 items-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">Filter</button>
                @if (request()->hasAny(['search', 'status', 'category']))
                    <a href="{{ route('forum-discussions.index') }}" class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">Reset</a>
                @endif
            </form>
            @can('create', App\Models\ForumDiscussion::class)
                <a href="{{ route('forum-discussions.create') }}"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 shrink-0 items-center rounded-lg px-4 text-sm font-medium text-white transition">+ Buat Diskusi</a>
            @endcan
        </div>

        <div class="max-w-full overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        @foreach (['Diskusi', 'Penulis', 'Komentar', 'Rating', 'Dilihat', 'Status', 'Dibuat'] as $heading)
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($discussions as $discussion)
                        @php($author = $discussion->authorPayload())
                        <tr>
                            <td class="max-w-md px-5 py-4">
                                <a href="{{ route('forum-discussions.show', $discussion) }}" class="text-theme-sm font-medium text-gray-800 hover:text-brand-500 dark:text-white/90">
                                    {{ $discussion->title }}
                                </a>
                                <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                                    {{ $discussion->category?->name }}
                                    @if ($discussion->is_pinned) · disematkan @endif
                                    @if ($discussion->is_locked) · komentar ditutup @endif
                                </p>
                            </td>
                            <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                {{ $author['name'] }}
                                <span class="text-theme-xs">({{ $author['role'] === 'Moderator' ? 'Admin' : 'Member' }})</span>
                            </td>
                            <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{{ number_format($discussion->comments_count) }}</td>
                            <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                {{ $discussion->rating_count ? $discussion->ratingAverage().' ('.$discussion->rating_count.')' : '—' }}
                            </td>
                            <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{{ number_format($discussion->views_count) }}</td>
                            <td class="px-5 py-4">
                                <span class="rounded-full px-2.5 py-0.5 text-theme-xs font-medium {{ $discussion->isPublished() ? 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-500' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' }}">
                                    {{ $discussion->isPublished() ? 'Tampil' : 'Disembunyikan' }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{{ $discussion->created_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">Belum ada diskusi.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($discussions->hasPages())
            <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">{{ $discussions->links() }}</div>
        @endif
    </div>
@endsection
