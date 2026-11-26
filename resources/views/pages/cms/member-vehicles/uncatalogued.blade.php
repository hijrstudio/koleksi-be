@extends('layouts.app')

@php
    $selectClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 select-chevron h-10 w-full rounded-lg border border-gray-300 bg-transparent pl-3 py-2 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    @foreach (['success' => 'success', 'error' => 'error'] as $key => $color)
        @if (session($key))
            <div class="border-{{ $color }}-500 bg-{{ $color }}-50 dark:border-{{ $color }}-500/30 dark:bg-{{ $color }}-500/15 mb-6 rounded-xl border p-4">
                <p class="text-sm text-gray-700 dark:text-gray-300">{{ session($key) }}</p>
            </div>
        @endif
    @endforeach
    @if ($errors->any())
        <div class="border-error-500 bg-error-50 dark:border-error-500/30 dark:bg-error-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ $errors->first() }}</p>
        </div>
    @endif

    <div class="mb-6 rounded-2xl border border-gray-200 bg-white px-5 py-4 dark:border-gray-800 dark:bg-white/[0.03]">
        <p class="text-theme-sm text-gray-700 dark:text-gray-300">
            Merek / tipe yang ditulis sendiri oleh member saat daftar atau ubah profil karena belum ada di katalog,
            dikelompokkan per tulisan yang sama. Tambahkan dulu merek / tipenya di menu
            <a href="{{ route('vehicle-models.index') }}" class="text-brand-500 hover:underline">EV Garage</a>, lalu
            tautkan: semua kendaraan di kelompok itu langsung memakai merek &amp; tipe katalog.
        </p>
    </div>

    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        @foreach (['Merek', 'Tipe (ditulis member)', 'Kendaraan', 'Tautkan ke katalog'] as $heading)
                            <th class="px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($groups as $group)
                        <tr>
                            <td class="px-5 py-3 text-theme-sm text-gray-800 dark:text-white/90">
                                @if ($group->vehicle_brand_id)
                                    {{ $group->brand_name }}
                                @else
                                    {{ $group->brand_other ?? '—' }}
                                    <span class="bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-orange-400 ml-1 rounded-full px-2 py-0.5 text-theme-xs">di luar katalog</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-theme-sm text-gray-800 dark:text-white/90">{{ $group->model_other ?? '—' }}</td>
                            <td class="px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ number_format($group->total) }}</td>
                            <td class="px-5 py-3">
                                @can('updateSettings', App\Models\Member::class)
                                    <form method="POST" action="{{ route('member-vehicles.uncatalogued.link') }}"
                                        class="flex flex-wrap items-center gap-2"
                                        x-data="{ brand: @js((string) ($group->vehicle_brand_id ?? '')), model: '',
                                            get models() { return window.catalogBrands.find((b) => String(b.id) === this.brand)?.models ?? []; } }"
                                        @submit="if (!confirm(@js("Tautkan {$group->total} kendaraan ini ke merek & tipe yang dipilih?"))) $event.preventDefault()">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="source_brand_id" value="{{ $group->vehicle_brand_id }}" />
                                        <input type="hidden" name="source_brand_other" value="{{ $group->brand_other }}" />
                                        <input type="hidden" name="source_model_other" value="{{ $group->model_other }}" />
                                        <select name="vehicle_brand_id" x-model="brand" @change="model = ''" required aria-label="Merek katalog"
                                            class="{{ $selectClass }} sm:w-44">
                                            <option value="">Merek…</option>
                                            <template x-for="b in window.catalogBrands" :key="b.id">
                                                <option :value="String(b.id)" x-text="b.name" :selected="String(b.id) === brand"></option>
                                            </template>
                                        </select>
                                        <select name="vehicle_model_id" x-model="model" :disabled="!brand" required aria-label="Tipe katalog"
                                            class="{{ $selectClass }} sm:w-52">
                                            <option value="">Tipe…</option>
                                            <template x-for="m in models" :key="m.id">
                                                <option :value="String(m.id)" x-text="m.name"></option>
                                            </template>
                                        </select>
                                        <button type="submit" :disabled="!model"
                                            class="bg-brand-500 hover:bg-brand-600 inline-flex h-10 items-center rounded-lg px-4 text-sm font-medium text-white disabled:opacity-50">
                                            Tautkan
                                        </button>
                                    </form>
                                @else
                                    <span class="text-theme-xs text-gray-500 dark:text-gray-400">—</span>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                Semua kendaraan member sudah memakai merek &amp; tipe dari katalog.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($groups->hasPages())
            <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">{{ $groups->links() }}</div>
        @endif
    </div>

    <script>
        window.catalogBrands = @json($brands);
    </script>
@endsection
