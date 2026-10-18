@extends('layouts.app')

@php
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
@endphp

@section('content')
    <x-common.page-breadcrumb pageTitle="Merek Kendaraan" />

    @include('pages.cms.vehicle-brands.partials.flash')

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <!-- Toolbar -->
        <div class="flex flex-col gap-4 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
            <form method="GET" action="{{ route('vehicle-brands.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari nama merek..."
                    class="{{ $inputClass }} w-full sm:w-64" />
                <select name="status" class="{{ $inputClass }} select-chevron pr-10">
                    <option value="">Semua status</option>
                    <option value="active" @selected(request('status') === 'active')>Aktif</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
                </select>
                <button type="submit"
                    class="shadow-theme-xs inline-flex h-11 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                    Filter
                </button>
                @if (request()->hasAny(['search', 'status']))
                    <a href="{{ route('vehicle-brands.index') }}" class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">Reset</a>
                @endif
            </form>

            @can('create', App\Models\VehicleBrand::class)
                <a href="{{ route('vehicle-brands.create') }}"
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
                        @foreach (['Urutan', 'Logo', 'Merek', 'Tipe', 'Status', 'Terakhir diubah', ''] as $heading)
                            <th class="px-5 py-3 text-left text-theme-xs font-medium whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($brands as $brand)
                        <tr>
                            <td class="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{{ $brand->sort_order }}</td>
                            <td class="px-5 py-4">
                                <div class="flex h-14 w-28 items-center justify-center rounded-lg border border-gray-100 bg-white p-2 dark:border-gray-800">
                                    @if ($brand->logo_url)
                                        <img src="{{ $brand->logo_url }}" alt="{{ $brand->name }}" class="max-h-full max-w-full object-contain" loading="lazy" />
                                    @else
                                        <span class="text-theme-xs text-gray-400">Tanpa logo</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $brand->name }}</p>
                                <p class="text-theme-xs text-gray-400">{{ $brand->slug }}</p>
                            </td>
                            <td class="px-5 py-4 text-theme-sm whitespace-nowrap text-gray-500 dark:text-gray-400">
                                @can('viewAny', App\Models\VehicleModel::class)
                                    <a href="{{ route('vehicle-models.index', ['brand' => $brand->id]) }}" class="text-brand-500 hover:underline">
                                        {{ $brand->models_count }} tipe
                                    </a>
                                @else
                                    {{ $brand->models_count }} tipe
                                @endcan
                                <p class="text-theme-xs">{{ $brand->garage_models_count }} tampil di EV Garage</p>
                            </td>
                            <td class="px-5 py-4">
                                @include('pages.cms.vehicle-brands.partials.active-badge', ['active' => $brand->is_active])
                            </td>
                            <td class="px-5 py-4 text-theme-sm whitespace-nowrap text-gray-500 dark:text-gray-400">
                                <p>{{ $brand->updater?->name ?? '-' }}</p>
                                <p class="text-theme-xs">{{ $brand->updated_at?->format('d M Y H:i') }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    @can('update', $brand)
                                        <a href="{{ route('vehicle-brands.edit', $brand) }}"
                                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                            Edit
                                        </a>
                                    @endcan
                                    @can('delete', $brand)
                                        <form method="POST" action="{{ route('vehicle-brands.destroy', $brand) }}"
                                            x-data @submit.prevent="if (confirm('Hapus merek ini? Semua tipe & logonya juga akan dihapus.')) $el.submit()">
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
                                Belum ada merek.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($brands->hasPages())
            <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                {{ $brands->links() }}
            </div>
        @endif
    </div>
@endsection
