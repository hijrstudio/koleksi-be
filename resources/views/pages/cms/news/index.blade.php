@extends('layouts.app')

@php
    use App\Models\News;

    $selectClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 select-chevron h-11 rounded-lg border border-gray-300 bg-transparent pl-4 py-2.5 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
@endphp

@section('content')
    <x-common.page-breadcrumb pageTitle="Berita" />

    @if (session('success'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
            class="border-success-500 bg-success-50 dark:border-success-500/30 dark:bg-success-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ session('success') }}</p>
        </div>
    @endif

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <!-- Toolbar -->
        <div class="flex flex-col gap-4 border-b border-gray-100 px-5 py-4 xl:flex-row xl:items-center xl:justify-between dark:border-gray-800">
            <form method="GET" action="{{ route('news.index') }}" class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari judul..."
                    class="shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden sm:w-56 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                <select name="category" class="{{ $selectClass }}">
                    <option value="">Semua kategori</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->value }}" @selected(request('category') === $category->value)>{{ $category->label() }}</option>
                    @endforeach
                </select>
                <select name="status" class="{{ $selectClass }}">
                    <option value="">Semua status</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
                <select name="author" class="{{ $selectClass }}">
                    <option value="">Semua penulis</option>
                    <option value="{{ News::AUTHOR_USER }}" @selected(request('author') === News::AUTHOR_USER)>Admin</option>
                    <option value="{{ News::AUTHOR_MEMBER }}" @selected(request('author') === News::AUTHOR_MEMBER)>Member</option>
                </select>
                <button type="submit"
                    class="shadow-theme-xs inline-flex h-11 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                    Filter
                </button>
                @if (request()->hasAny(['search', 'category', 'status', 'author']))
                    <a href="{{ route('news.index') }}" class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">Reset</a>
                @endif
            </form>

            @can('create', News::class)
                <a href="{{ route('news.create') }}"
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
                        @foreach (['No', 'Gambar', 'Judul', 'Kategori', 'Penulis', 'Status', 'Terakhir diubah', ''] as $heading)
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($news as $item)
                        <tr>
                            <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                {{ $news->firstItem() + $loop->index }}
                            </td>
                            <td class="px-5 py-4">
                                <div class="h-14 w-24 overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-800">
                                    @if ($item->image_url)
                                        <img src="{{ $item->image_url }}" alt="" class="h-full w-full object-cover" loading="lazy" />
                                    @endif
                                </div>
                            </td>
                            <td class="max-w-md px-5 py-4">
                                <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    <span class="mr-1 rounded bg-gray-100 px-1.5 py-0.5 text-theme-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">ID</span>
                                    {{ $item->title['id'] ?? '-' }}
                                </p>
                                <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
                                    <span class="mr-1 rounded bg-gray-100 px-1.5 py-0.5 text-theme-xs dark:bg-gray-800">EN</span>
                                    {{ $item->title['en'] ?? '-' }}
                                </p>
                                <p class="mt-1 truncate text-theme-xs text-gray-400">/{{ $item->slug }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <span class="rounded-full px-2.5 py-0.5 text-theme-xs font-medium whitespace-nowrap {{ $item->category->badgeClass() }}">
                                    {{ $item->category->label() }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-theme-sm">
                                @if ($item->isExternal())
                                    {{-- Berita EV: penulis & nama media dari sumber berita --}}
                                    <p class="text-gray-800 dark:text-white/90">{{ $item->source_author ?: ($item->source_name ?: '-') }}</p>
                                    <span class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $item->source_name ?: 'Media' }}</span>
                                @else
                                    <p class="text-gray-800 dark:text-white/90">{{ $item->authorName() ?? 'Akun dihapus' }}</p>
                                    <span class="text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $item->authorType() === News::AUTHOR_MEMBER ? 'Member' : 'Admin' }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <span class="rounded-full px-2.5 py-0.5 text-theme-xs font-medium {{ $item->status->badgeClass() }}">
                                    {{ $item->status->label() }}
                                </span>
                                @if ($item->published_at)
                                    <p class="mt-1 text-theme-xs text-gray-400">{{ $item->published_at->format('d M Y') }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                <p>{{ $item->updater?->name ?? '-' }}</p>
                                <p class="text-theme-xs">{{ $item->updated_at?->format('d M Y H:i') }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    @can('update', $item)
                                        <a href="{{ route('news.edit', $item) }}"
                                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                            Edit
                                        </a>
                                    @endcan
                                    @can('delete', $item)
                                        <form method="POST" action="{{ route('news.destroy', $item) }}"
                                            x-data @submit.prevent="if (confirm('Hapus berita ini? Gambar utama juga akan dihapus.')) $el.submit()">
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
                            <td colspan="8" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                Belum ada berita.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($news->hasPages())
            <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                {{ $news->links() }}
            </div>
        @endif
    </div>
@endsection
