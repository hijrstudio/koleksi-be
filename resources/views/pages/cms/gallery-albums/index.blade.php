@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="Galeri Foto" />

    @if (session('success'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
            class="border-success-500 bg-success-50 dark:border-success-500/30 dark:bg-success-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ session('success') }}</p>
        </div>
    @endif

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <!-- Toolbar -->
        <div class="flex flex-col gap-4 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
            <form method="GET" action="{{ route('gallery-albums.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari judul album..."
                    class="shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden sm:w-64 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                <select name="status"
                    class="shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 select-chevron h-11 rounded-lg border border-gray-300 bg-transparent pl-4 py-2.5 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    <option value="">Semua status</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
                <button type="submit"
                    class="shadow-theme-xs inline-flex h-11 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                    Filter
                </button>
                @if (request()->hasAny(['search', 'status']))
                    <a href="{{ route('gallery-albums.index') }}" class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">Reset</a>
                @endif
            </form>

            @can('create', App\Models\GalleryAlbum::class)
                <a href="{{ route('gallery-albums.create') }}"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 items-center justify-center gap-2 rounded-lg px-4 text-sm font-medium text-white transition">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M10 4.167v11.666M4.167 10h11.666" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                    </svg>
                    Tambah Album
                </a>
            @endcan
        </div>

        <!-- Grid album -->
        @if ($albums->isEmpty())
            <p class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">Belum ada album.</p>
        @else
            <div class="grid grid-cols-1 gap-5 p-5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                @foreach ($albums as $album)
                    <div class="flex flex-col overflow-hidden rounded-xl border border-gray-200 dark:border-gray-800">
                        <div class="h-48 shrink-0 overflow-hidden bg-gray-100 dark:bg-gray-800">
                            <img src="{{ $album->image_url }}" alt="" class="h-full w-full object-cover" loading="lazy" />
                        </div>
                        <div class="flex flex-1 flex-col gap-1 p-4">
                            <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                                <span class="inline-flex items-center gap-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none"><path d="M7.75 3.5V6M16.25 3.5V6M4.75 9.25H19.25M6.25 5H17.75C18.5784 5 19.25 5.67157 19.25 6.5V18.5C19.25 19.3284 18.5784 20 17.75 20H6.25C5.42157 20 4.75 19.3284 4.75 18.5V6.5C4.75 5.67157 5.42157 5 6.25 5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                    {{ $album->gallery_date?->translatedFormat('d F Y') }}
                                </span>
                                <div class="flex items-center gap-1.5">
                                    <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-theme-xs font-medium text-gray-600 dark:bg-white/[0.05] dark:text-gray-400">
                                        {{ $album->photos_count }} foto
                                    </span>
                                    <span class="rounded-full px-2.5 py-0.5 text-theme-xs font-medium {{ $album->status->badgeClass() }}">
                                        {{ $album->status->label() }}
                                    </span>
                                </div>
                            </div>
                            <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $album->title['id'] ?? '-' }}</p>
                            <p class="text-theme-xs text-gray-500 dark:text-gray-400">EN: {{ $album->title['en'] ?? '-' }}</p>
                            <p class="mt-auto pt-2 text-theme-xs text-gray-400">
                                Diubah {{ $album->updater?->name ?? '-' }} · {{ $album->updated_at?->format('d M Y H:i') }}
                            </p>
                        </div>
                        <div class="flex items-center justify-end gap-2 border-t border-gray-100 px-4 py-3 dark:border-gray-800">
                            @can('update', $album)
                                <a href="{{ route('gallery-albums.edit', $album) }}"
                                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                    Edit
                                </a>
                            @endcan
                            @can('delete', $album)
                                <form method="POST" action="{{ route('gallery-albums.destroy', $album) }}"
                                    x-data @submit.prevent="if (confirm('Hapus album ini? Cover dan semua fotonya ({{ $album->photos_count }}) juga akan dihapus.')) $el.submit()">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                        class="border-error-300 text-error-600 hover:bg-error-50 dark:border-error-500/40 dark:text-error-500 dark:hover:bg-error-500/10 rounded-lg border px-3 py-1.5 text-theme-sm font-medium">
                                        Hapus
                                    </button>
                                </form>
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($albums->hasPages())
            <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                {{ $albums->links() }}
            </div>
        @endif
    </div>
@endsection
