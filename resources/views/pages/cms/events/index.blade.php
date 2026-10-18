@extends('layouts.app')

@php
    $selectClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 select-chevron h-11 rounded-lg border border-gray-300 bg-transparent pl-4 py-2.5 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
    $tabClass = fn (bool $active) => $active
        ? 'border-brand-500 text-brand-600 dark:text-brand-400'
        : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300';
    // Tab mempertahankan filter lain (search, kategori, status)
    $tabUrl = fn (?string $phase) => route('events.index', array_filter([...request()->except(['phase', 'page']), 'phase' => $phase]));
@endphp

@section('content')
    <x-common.page-breadcrumb pageTitle="Event" />

    @if (session('success'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
            class="border-success-500 bg-success-50 dark:border-success-500/30 dark:bg-success-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ session('success') }}</p>
        </div>
    @endif

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <!-- Tab fase (dihitung dari tanggal mulai & selesai) -->
        <div class="flex gap-6 overflow-x-auto border-b border-gray-100 px-5 dark:border-gray-800">
            <a href="{{ $tabUrl(null) }}" class="-mb-px border-b-2 py-3.5 text-sm font-medium whitespace-nowrap {{ $tabClass($currentPhase === null) }}">
                Semua <span class="ml-1 text-theme-xs text-gray-400">{{ $totalCount }}</span>
            </a>
            @foreach ($phases as $phase)
                <a href="{{ $tabUrl($phase->value) }}" class="-mb-px border-b-2 py-3.5 text-sm font-medium whitespace-nowrap {{ $tabClass($currentPhase === $phase) }}">
                    {{ $phase->label() }} <span class="ml-1 text-theme-xs text-gray-400">{{ $phaseCounts[$phase->value] }}</span>
                </a>
            @endforeach
        </div>

        <!-- Toolbar -->
        <div class="flex flex-col gap-4 border-b border-gray-100 px-5 py-4 xl:flex-row xl:items-center xl:justify-between dark:border-gray-800">
            <form method="GET" action="{{ route('events.index') }}" class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                @if ($currentPhase)
                    <input type="hidden" name="phase" value="{{ $currentPhase->value }}">
                @endif
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari judul / lokasi..."
                    class="shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden sm:w-56 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
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
                    <a href="{{ $currentPhase ? route('events.index', ['phase' => $currentPhase->value]) : route('events.index') }}"
                        class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">Reset</a>
                @endif
            </form>

            @can('create', App\Models\Event::class)
                <a href="{{ route('events.create') }}"
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
                        @foreach (['No', 'Gambar', 'Event', 'Tanggal', 'Lokasi', 'Fase', 'Status', 'Terakhir diubah', ''] as $heading)
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($events as $event)
                        <tr>
                            <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                {{ $events->firstItem() + $loop->index }}
                            </td>
                            <td class="px-5 py-4">
                                <div class="h-14 w-24 overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-800">
                                    @if ($event->image_url)
                                        <img src="{{ $event->image_url }}" alt="" class="h-full w-full object-cover" loading="lazy" />
                                    @endif
                                </div>
                            </td>
                            <td class="max-w-sm px-5 py-4">
                                <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $event->title['id'] ?? '-' }}</p>
                                <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">EN: {{ $event->title['en'] ?? '-' }}</p>
                                <p class="mt-1 text-theme-xs text-gray-400">
                                    {{ $event->category?->name['id'] ?? '-' }}@if ($event->category?->status === App\Enums\ContentStatus::Draft) (kategori draft)@endif
                                    · <span class="font-mono">/{{ $event->slug }}</span>
                                </p>
                            </td>
                            <td class="px-5 py-4 text-theme-sm whitespace-nowrap text-gray-700 dark:text-gray-300">
                                @if ($event->start_date->equalTo($event->end_date))
                                    {{ $event->start_date->translatedFormat('d M Y') }}
                                @else
                                    {{ $event->start_date->translatedFormat('d M Y') }} –<br>{{ $event->end_date->translatedFormat('d M Y') }}
                                @endif
                            </td>
                            <td class="max-w-xs px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                <p class="line-clamp-2">{{ $event->location }}</p>
                                @if ($event->maps_url)
                                    <a href="{{ $event->maps_url }}" target="_blank" rel="noopener noreferrer" class="text-brand-500 text-theme-xs hover:underline">Lihat di Maps</a>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <span class="rounded-full px-2.5 py-0.5 text-theme-xs font-medium whitespace-nowrap {{ $event->phase->badgeClass() }}">
                                    {{ $event->phase->label() }}
                                </span>
                            </td>
                            <td class="px-5 py-4">
                                <span class="rounded-full px-2.5 py-0.5 text-theme-xs font-medium {{ $event->status->badgeClass() }}">
                                    {{ $event->status->label() }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                <p>{{ $event->updater?->name ?? '-' }}</p>
                                <p class="text-theme-xs">{{ $event->updated_at?->format('d M Y H:i') }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    @can('update', $event)
                                        <a href="{{ route('events.edit', $event) }}"
                                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                            Edit
                                        </a>
                                    @endcan
                                    @can('delete', $event)
                                        <form method="POST" action="{{ route('events.destroy', $event) }}"
                                            x-data @submit.prevent="if (confirm('Hapus event ini?')) $el.submit()">
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
                            <td colspan="9" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                Belum ada event.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($events->hasPages())
            <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                {{ $events->links() }}
            </div>
        @endif
    </div>
@endsection
