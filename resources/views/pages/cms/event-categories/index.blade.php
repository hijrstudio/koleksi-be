@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="Kategori Kegiatan" />

    @if (session('success'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
            class="border-success-500 bg-success-50 dark:border-success-500/30 dark:bg-success-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ session('success') }}</p>
        </div>
    @endif

    @if (session('error'))
        <div class="border-error-500 bg-error-50 dark:border-error-500/30 dark:bg-error-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ session('error') }}</p>
        </div>
    @endif

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <!-- Toolbar -->
        <div class="flex flex-col gap-4 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
            <form method="GET" action="{{ route('event-categories.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari kategori..."
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
                    <a href="{{ route('event-categories.index') }}" class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">Reset</a>
                @endif
            </form>

            @can('create', App\Models\EventCategory::class)
                <a href="{{ route('event-categories.create') }}"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 items-center justify-center gap-2 rounded-lg px-4 text-sm font-medium text-white transition">
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
                        @foreach (['No', 'Kategori', 'Jumlah event', 'Status', 'Terakhir diubah', ''] as $heading)
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($categories as $category)
                        <tr>
                            <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                {{ $categories->firstItem() + $loop->index }}
                            </td>
                            <td class="px-5 py-4">
                                <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    <span class="mr-1 rounded bg-gray-100 px-1.5 py-0.5 text-theme-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">ID</span>
                                    {{ $category->name['id'] ?? '-' }}
                                </p>
                                <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
                                    <span class="mr-1 rounded bg-gray-100 px-1.5 py-0.5 text-theme-xs dark:bg-gray-800">EN</span>
                                    {{ $category->name['en'] ?? '-' }}
                                </p>
                            </td>
                            <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                @can('viewAny', App\Models\Event::class)
                                    <a href="{{ route('events.index', ['category' => $category->id]) }}" class="text-brand-500 hover:underline">
                                        {{ $category->events_count }} event
                                    </a>
                                @else
                                    {{ $category->events_count }} event
                                @endcan
                            </td>
                            <td class="px-5 py-4">
                                <span class="rounded-full px-2.5 py-0.5 text-theme-xs font-medium {{ $category->status->badgeClass() }}">
                                    {{ $category->status->label() }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                <p>{{ $category->updater?->name ?? '-' }}</p>
                                <p class="text-theme-xs">{{ $category->updated_at?->format('d M Y H:i') }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    @can('update', $category)
                                        <a href="{{ route('event-categories.edit', $category) }}"
                                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                            Edit
                                        </a>
                                    @endcan
                                    @can('delete', $category)
                                        <form method="POST" action="{{ route('event-categories.destroy', $category) }}"
                                            x-data @submit.prevent="if (confirm('Hapus kategori ini?')) $el.submit()">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" @disabled($category->events_count > 0)
                                                title="{{ $category->events_count > 0 ? 'Kategori masih dipakai event' : 'Hapus' }}"
                                                class="border-error-300 text-error-600 hover:bg-error-50 dark:border-error-500/40 dark:text-error-500 dark:hover:bg-error-500/10 rounded-lg border px-3 py-1.5 text-theme-sm font-medium disabled:cursor-not-allowed disabled:opacity-40">
                                                Hapus
                                            </button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                Belum ada kategori.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($categories->hasPages())
            <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                {{ $categories->links() }}
            </div>
        @endif
    </div>
@endsection
