@extends('layouts.app')

@php
    $selectClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 select-chevron h-11 rounded-lg border border-gray-300 bg-transparent pl-4 py-2.5 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
@endphp

@section('content')
    <x-common.page-breadcrumb pageTitle="Video Edukasi" />

    @if (session('success'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
            class="border-success-500 bg-success-50 dark:border-success-500/30 dark:bg-success-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ session('success') }}</p>
        </div>
    @endif

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <!-- Toolbar -->
        <div class="flex flex-col gap-4 border-b border-gray-100 px-5 py-4 xl:flex-row xl:items-center xl:justify-between dark:border-gray-800">
            <form method="GET" action="{{ route('guide-videos.index') }}" class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari judul / link YouTube..."
                    class="shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden sm:w-64 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                <select name="category" class="{{ $selectClass }}">
                    <option value="">Semua kategori</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((int) request('category') === $category->id)>{{ $category->name['id'] ?? '-' }}</option>
                    @endforeach
                </select>
                <select name="status" class="{{ $selectClass }}">
                    <option value="">Semua status</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
                <button type="submit"
                    class="shadow-theme-xs inline-flex h-11 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                    Filter
                </button>
                @if (request()->hasAny(['search', 'category', 'status']))
                    <a href="{{ route('guide-videos.index') }}" class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">Reset</a>
                @endif
            </form>

            @can('create', App\Models\GuideVideo::class)
                <a href="{{ route('guide-videos.create') }}"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-lg px-4 text-sm font-medium text-white transition">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M10 4.167v11.666M4.167 10h11.666" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                    </svg>
                    Tambah Data
                </a>
            @endcan
        </div>

        <!-- Table -->
        <div class="max-w-full overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        @foreach (['No', 'Thumbnail', 'Judul', 'Kategori', 'Status', 'Terakhir diubah', ''] as $heading)
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($videos as $video)
                        <tr>
                            <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                {{ $videos->firstItem() + $loop->index }}
                            </td>
                            <td class="px-5 py-4">
                                <a href="{{ $video->watch_url }}" target="_blank" rel="noopener noreferrer" title="Buka di YouTube"
                                    class="relative block h-14 w-24 overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-800">
                                    <img src="{{ $video->thumbnail_url }}" alt="" class="h-full w-full object-cover" loading="lazy" />
                                    <span class="absolute inset-0 flex items-center justify-center bg-black/20">
                                        <svg class="h-6 w-6 text-white" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.5v13l11-6.5-11-6.5Z" /></svg>
                                    </span>
                                </a>
                                <span class="mt-1 block text-theme-xs text-gray-400">{{ $video->thumbnail ? 'Upload' : 'Dari YouTube' }}</span>
                            </td>
                            <td class="max-w-md px-5 py-4">
                                <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    <span class="mr-1 rounded bg-gray-100 px-1.5 py-0.5 text-theme-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">ID</span>
                                    {{ $video->title['id'] ?? '-' }}
                                </p>
                                <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
                                    <span class="mr-1 rounded bg-gray-100 px-1.5 py-0.5 text-theme-xs dark:bg-gray-800">EN</span>
                                    {{ $video->title['en'] ?? '-' }}
                                </p>
                                <p class="mt-1 font-mono text-theme-xs text-gray-400">YouTube: {{ $video->youtube_id }}</p>
                            </td>
                            <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                {{ $video->category?->name['id'] ?? '-' }}
                            </td>
                            <td class="px-5 py-4">
                                <span class="rounded-full px-2.5 py-0.5 text-theme-xs font-medium {{ $video->status->badgeClass() }}">
                                    {{ $video->status->label() }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                <p>{{ $video->updater?->name ?? '-' }}</p>
                                <p class="text-theme-xs">{{ $video->updated_at?->format('d M Y H:i') }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    @can('update', $video)
                                        <a href="{{ route('guide-videos.edit', $video) }}"
                                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                            Edit
                                        </a>
                                    @endcan
                                    @can('delete', $video)
                                        <form method="POST" action="{{ route('guide-videos.destroy', $video) }}"
                                            x-data @submit.prevent="if (confirm('Hapus video ini? Thumbnail yang diupload juga akan dihapus.')) $el.submit()">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                class="border-error-300 text-error-600 hover:bg-error-50 dark:border-error-500/40 dark:text-error-500 dark:hover:bg-error-500/10 rounded-lg border px-3 py-1.5 text-theme-sm font-medium">
                                                Hapus
                                            </button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                Belum ada video.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($videos->hasPages())
            <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                {{ $videos->links() }}
            </div>
        @endif
    </div>
@endsection
