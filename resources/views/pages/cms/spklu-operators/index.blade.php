@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="SPKLU" />

    @if (session('success'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
            class="border-success-500 bg-success-50 dark:border-success-500/30 dark:bg-success-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ session('success') }}</p>
        </div>
    @endif

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <!-- Toolbar -->
        <div class="flex flex-col gap-4 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
            <form method="GET" action="{{ route('spklu-operators.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari brand / operator / aplikasi..."
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
                    <a href="{{ route('spklu-operators.index') }}" class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">Reset</a>
                @endif
            </form>

            @can('create', App\Models\SpkluOperator::class)
                <a href="{{ route('spklu-operators.create') }}"
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
                        @foreach (['Logo', 'Brand SPKLU', 'Aplikasi', 'Link Aplikasi', 'Status', 'Terakhir diubah', ''] as $heading)
                            <th class="px-5 py-3 text-left text-theme-xs font-medium whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($operators as $operator)
                        <tr>
                            <td class="px-5 py-4">
                                <div class="flex h-14 w-28 items-center justify-center rounded-lg border border-gray-100 bg-white p-2 dark:border-gray-800">
                                    @if ($operator->logo_url)
                                        <img src="{{ $operator->logo_url }}" alt="{{ $operator->brand_spklu }}" class="max-h-full max-w-full object-contain" loading="lazy" />
                                    @else
                                        <span class="text-theme-xs text-gray-400">Tanpa logo</span>
                                    @endif
                                </div>
                            </td>
                            <td class="max-w-xs px-5 py-4">
                                <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $operator->brand_spklu }}</p>
                                <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $operator->nama_operator }}</p>
                            </td>
                            <td class="px-5 py-4 text-theme-sm text-gray-700 dark:text-gray-400">{{ $operator->aplikasi }}</td>
                            <td class="px-5 py-4">
                                <div class="flex flex-col gap-1 text-theme-xs whitespace-nowrap">
                                    @foreach (['link_playstore' => 'Play Store', 'link_appstore' => 'App Store'] as $field => $store)
                                        @if ($operator->{$field})
                                            <a href="{{ $operator->{$field} }}" target="_blank" rel="noopener noreferrer nofollow"
                                                class="text-brand-500 hover:underline">{{ $store }} ↗</a>
                                        @else
                                            <span class="text-gray-400">{{ $store }}: -</span>
                                        @endif
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <span class="rounded-full px-2.5 py-0.5 text-theme-xs font-medium {{ $operator->status->badgeClass() }}">
                                    {{ $operator->status->label() }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-theme-sm whitespace-nowrap text-gray-500 dark:text-gray-400">
                                <p>{{ $operator->updater?->name ?? '-' }}</p>
                                <p class="text-theme-xs">{{ $operator->updated_at?->format('d M Y H:i') }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    @can('update', $operator)
                                        <a href="{{ route('spklu-operators.edit', $operator) }}"
                                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                            Edit
                                        </a>
                                    @endcan
                                    @can('delete', $operator)
                                        <form method="POST" action="{{ route('spklu-operators.destroy', $operator) }}"
                                            x-data @submit.prevent="if (confirm('Hapus operator SPKLU ini? Logo juga akan dihapus.')) $el.submit()">
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
                                Belum ada operator SPKLU.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($operators->hasPages())
            <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                {{ $operators->links() }}
            </div>
        @endif
    </div>
@endsection
