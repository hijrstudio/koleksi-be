@extends('layouts.app')

@php
    $selectClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 select-chevron h-11 rounded-lg border border-gray-300 bg-transparent pl-4 py-2.5 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
@endphp

@section('content')
    <x-common.page-breadcrumb pageTitle="Web Playbook" />

    @if (session('success'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
            class="border-success-500 bg-success-50 dark:border-success-500/30 dark:bg-success-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ session('success') }}</p>
        </div>
    @endif

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <!-- Toolbar -->
        <div class="flex flex-col gap-4 border-b border-gray-100 px-5 py-4 xl:flex-row xl:items-center xl:justify-between dark:border-gray-800">
            <form method="GET" action="{{ route('guide-playbooks.index') }}" class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari judul..."
                    class="shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden sm:w-64 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
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
                @if (request()->hasAny(['search', 'status']))
                    <a href="{{ route('guide-playbooks.index') }}" class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">Reset</a>
                @endif
            </form>

            @can('create', App\Models\GuidePlaybook::class)
                <a href="{{ route('guide-playbooks.create') }}"
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
                        @foreach (['No', 'Cover', 'Judul', 'File PDF', 'Status', 'Terakhir diubah', ''] as $heading)
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($playbooks as $playbook)
                        <tr>
                            <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                {{ $playbooks->firstItem() + $loop->index }}
                            </td>
                            <td class="px-5 py-4">
                                <a href="{{ $playbook->cover_url }}" target="_blank" rel="noopener noreferrer" title="Lihat ukuran penuh"
                                    class="block h-20 w-16 overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-800">
                                    <img src="{{ $playbook->cover_url }}" alt="" class="h-full w-full object-cover" loading="lazy" />
                                </a>
                            </td>
                            <td class="max-w-md px-5 py-4">
                                <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    <span class="mr-1 rounded bg-gray-100 px-1.5 py-0.5 text-theme-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">ID</span>
                                    {{ $playbook->title['id'] ?? '-' }}
                                </p>
                                <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
                                    <span class="mr-1 rounded bg-gray-100 px-1.5 py-0.5 text-theme-xs dark:bg-gray-800">EN</span>
                                    {{ $playbook->title['en'] ?? '-' }}
                                </p>
                            </td>
                            <td class="px-5 py-4">
                                <a href="{{ route('guide-playbooks.pdf', $playbook) }}" target="_blank" rel="noopener noreferrer"
                                    class="text-brand-500 inline-flex items-center gap-1.5 text-theme-sm font-medium hover:underline">
                                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                        <path d="M11.667 1.667H5a1.667 1.667 0 0 0-1.667 1.666v13.334A1.667 1.667 0 0 0 5 18.333h10a1.667 1.667 0 0 0 1.667-1.666v-10l-5-5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                                        <path d="M11.667 1.667v5h5" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                                    </svg>
                                    Lihat PDF
                                </a>
                            </td>
                            <td class="px-5 py-4">
                                <span class="rounded-full px-2.5 py-0.5 text-theme-xs font-medium {{ $playbook->status->badgeClass() }}">
                                    {{ $playbook->status->label() }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                                <p>{{ $playbook->updater?->name ?? '-' }}</p>
                                <p class="text-theme-xs">{{ $playbook->updated_at?->format('d M Y H:i') }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    @can('update', $playbook)
                                        <a href="{{ route('guide-playbooks.edit', $playbook) }}"
                                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                            Edit
                                        </a>
                                    @endcan
                                    @can('delete', $playbook)
                                        <form method="POST" action="{{ route('guide-playbooks.destroy', $playbook) }}"
                                            x-data @submit.prevent="if (confirm('Hapus Web Playbook ini? Cover dan file PDF-nya juga akan dihapus.')) $el.submit()">
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
                                Belum ada Web Playbook.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($playbooks->hasPages())
            <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                {{ $playbooks->links() }}
            </div>
        @endif
    </div>
@endsection
