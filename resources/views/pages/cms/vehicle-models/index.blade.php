@extends('layouts.app')

@php
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    // 72.6 -> "72,6 kWh", 11.0 -> "11 kW"
    $num = fn ($value, string $unit) => $value === null ? null : str_replace(',0', '', number_format((float) $value, 1, ',', '.')).' '.$unit;
@endphp

@section('content')
    <x-common.page-breadcrumb pageTitle="Tipe & Spesifikasi" />

    @include('pages.cms.vehicle-brands.partials.flash')

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <!-- Toolbar -->
        <div class="flex flex-col gap-4 border-b border-gray-100 px-5 py-4 xl:flex-row xl:items-center xl:justify-between dark:border-gray-800">
            <form method="GET" action="{{ route('vehicle-models.index') }}" class="flex flex-col flex-wrap gap-3 sm:flex-row sm:items-center">
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Cari merek / tipe..."
                    class="{{ $inputClass }} w-full sm:w-56" />
                <select name="brand" class="{{ $inputClass }} select-chevron pr-10">
                    <option value="">Semua merek</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}" @selected((int) request('brand') === $brand->id)>
                            {{ $brand->name }}{{ $brand->is_active ? '' : ' (nonaktif)' }}
                        </option>
                    @endforeach
                </select>
                <select name="specs" class="{{ $inputClass }} select-chevron pr-10">
                    <option value="">Semua spesifikasi</option>
                    <option value="complete" @selected(request('specs') === 'complete')>Lengkap</option>
                    <option value="incomplete" @selected(request('specs') === 'incomplete')>Belum lengkap</option>
                </select>
                <select name="status" class="{{ $inputClass }} select-chevron pr-10">
                    <option value="">Semua status</option>
                    <option value="active" @selected(request('status') === 'active')>Aktif</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
                </select>
                <button type="submit"
                    class="shadow-theme-xs inline-flex h-11 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                    Filter
                </button>
                @if (request()->hasAny(['search', 'brand', 'specs', 'status']))
                    <a href="{{ route('vehicle-models.index') }}" class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">Reset</a>
                @endif
            </form>

            @can('create', App\Models\VehicleModel::class)
                <a href="{{ route('vehicle-models.create', array_filter(['brand' => request('brand')])) }}"
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
                        @foreach (['Merek', 'Jenis / Tipe', 'Baterai', 'AC (maks.)', 'DC (maks.)', 'Jangkauan', 'EV Garage', 'Status', 'Terakhir diubah', ''] as $heading)
                            <th class="px-5 py-3 text-left text-theme-xs font-medium whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($models as $model)
                        @php($inGarage = $model->is_active && $model->brand?->is_active && $model->hasSpecs())
                        <tr>
                            <td class="px-5 py-4 text-theme-sm font-medium whitespace-nowrap text-gray-800 dark:text-white/90">
                                {{ $model->brand?->name }}
                                @unless ($model->brand?->is_active)
                                    <p class="text-theme-xs font-normal text-gray-400">merek nonaktif</p>
                                @endunless
                            </td>
                            <td class="px-5 py-4 text-theme-sm text-gray-700 dark:text-gray-300">{{ $model->name }}</td>
                            <td class="px-5 py-4 text-theme-sm whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $num($model->battery_kwh, 'kWh') ?? '-' }}</td>
                            <td class="px-5 py-4 text-theme-sm whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $num($model->ac_kw, 'kW') ?? '-' }}</td>
                            <td class="px-5 py-4 text-theme-sm whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $num($model->dc_kw, 'kW') ?? '–' }}</td>
                            <td class="px-5 py-4 text-theme-sm whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $model->range_km !== null ? number_format($model->range_km, 0, ',', '.').' km' : '-' }}</td>
                            <td class="px-5 py-4 text-theme-xs whitespace-nowrap">
                                @if ($inGarage)
                                    <span class="text-success-600 dark:text-success-500">Tampil</span>
                                @elseif (! $model->hasSpecs())
                                    <span class="text-warning-600 dark:text-orange-400">Spesifikasi belum lengkap</span>
                                @else
                                    <span class="text-gray-400">Tidak tampil</span>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                @include('pages.cms.vehicle-brands.partials.active-badge', ['active' => $model->is_active])
                            </td>
                            <td class="px-5 py-4 text-theme-sm whitespace-nowrap text-gray-500 dark:text-gray-400">
                                <p>{{ $model->updater?->name ?? '-' }}</p>
                                <p class="text-theme-xs">{{ $model->updated_at?->format('d M Y H:i') }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    @can('update', $model)
                                        <a href="{{ route('vehicle-models.edit', $model) }}"
                                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                            Edit
                                        </a>
                                    @endcan
                                    @can('delete', $model)
                                        <form method="POST" action="{{ route('vehicle-models.destroy', $model) }}"
                                            x-data @submit.prevent="if (confirm('Hapus tipe kendaraan ini?')) $el.submit()">
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
                            <td colspan="10" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                Belum ada tipe kendaraan.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($models->hasPages())
            <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                {{ $models->links() }}
            </div>
        @endif
    </div>
@endsection
