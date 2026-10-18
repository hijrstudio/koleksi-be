@extends('layouts.app')

@php
    use App\Http\Requests\Cms\VehicleModelRequest;

    $isEdit = $model->exists;
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden disabled:cursor-not-allowed disabled:opacity-60 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $borderClass = fn (string $field) => $errors->has($field) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    // Decimal tersimpan "11.0" -> tampil "11"; "72.6" tetap; angka bulat (jangkauan 450) tidak disentuh
    $specValue = fn ($value) => $value === null ? '' : (str_contains((string) $value, '.') ? rtrim(rtrim((string) $value, '0'), '.') : (string) $value);

    $specFields = [
        ['name' => 'battery_kwh', 'label' => 'Baterai', 'unit' => 'kWh', 'step' => '0.1', 'max' => VehicleModelRequest::MAX_BATTERY_KWH, 'placeholder' => '72.6', 'hint' => null],
        ['name' => 'ac_kw', 'label' => 'AC (maks.)', 'unit' => 'kW', 'step' => '0.1', 'max' => VehicleModelRequest::MAX_AC_KW, 'placeholder' => '11', 'hint' => null],
        ['name' => 'dc_kw', 'label' => 'DC (maks.)', 'unit' => 'kW', 'step' => '0.1', 'max' => VehicleModelRequest::MAX_DC_KW, 'placeholder' => '220', 'hint' => 'Kosongkan jika tidak mendukung DC fast charging (tampil "–").'],
        ['name' => 'range_km', 'label' => 'Jangkauan', 'unit' => 'km', 'step' => '1', 'max' => VehicleModelRequest::MAX_RANGE_KM, 'placeholder' => '451', 'hint' => null],
    ];
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <form method="POST"
        action="{{ $isEdit ? route('vehicle-models.update', $model) : route('vehicle-models.store') }}"
        class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="space-y-6 px-5 py-6 sm:px-6">
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <!-- Merek -->
                <div>
                    <label for="vehicle_brand_id" class="{{ $labelClass }}">Merek<span class="text-error-500">*</span></label>
                    @if ($brandLocked)
                        {{-- Select disabled tidak ikut terkirim: kirim nilai lewat hidden input --}}
                        <input type="hidden" name="vehicle_brand_id" value="{{ $model->vehicle_brand_id }}" />
                    @endif
                    <select id="vehicle_brand_id" @unless ($brandLocked) name="vehicle_brand_id" @endunless required @disabled($brandLocked)
                        class="{{ $inputClass }} {{ $borderClass('vehicle_brand_id') }} select-chevron pr-10">
                        <option value="">Pilih merek</option>
                        @foreach ($brands as $brand)
                            <option value="{{ $brand->id }}" @selected((int) old('vehicle_brand_id', $model->vehicle_brand_id) === $brand->id)>
                                {{ $brand->name }}{{ $brand->is_active ? '' : ' (nonaktif)' }}
                            </option>
                        @endforeach
                    </select>
                    @if ($brandLocked)
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">Tidak bisa diubah: tipe ini sudah dipakai kendaraan member.</p>
                    @endif
                    @error('vehicle_brand_id')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Tipe -->
                <div>
                    <label for="name" class="{{ $labelClass }}">Jenis / Tipe<span class="text-error-500">*</span></label>
                    <input type="text" id="name" name="name" required maxlength="{{ VehicleModelRequest::MAX_NAME_LENGTH }}"
                        value="{{ old('name', $model->name) }}" placeholder="Ioniq 5"
                        class="{{ $inputClass }} {{ $borderClass('name') }}" />
                    @error('name')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Spesifikasi EV Garage -->
            <div class="rounded-xl border border-gray-100 p-4 sm:p-5 dark:border-gray-800">
                <p class="text-sm font-medium text-gray-800 dark:text-white/90">Spesifikasi pengisian daya (EV Garage)</p>
                <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                    Tipe tampil di halaman EV Garage bila Baterai, AC & Jangkauan terisi. Kosongkan semua jika tipe hanya untuk dropdown form member.
                    Desimal boleh pakai titik atau koma.
                </p>

                <div class="mt-5 grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($specFields as $field)
                        <div>
                            <label for="{{ $field['name'] }}" class="{{ $labelClass }}">{{ $field['label'] }} ({{ $field['unit'] }})</label>
                            <div class="relative">
                                <input type="text" id="{{ $field['name'] }}" name="{{ $field['name'] }}"
                                    inputmode="{{ $field['step'] === '1' ? 'numeric' : 'decimal' }}" maxlength="7"
                                    value="{{ old($field['name'], $specValue($model->{$field['name']})) }}" placeholder="{{ $field['placeholder'] }}"
                                    class="{{ $inputClass }} {{ $borderClass($field['name']) }} pr-14" />
                                <span class="pointer-events-none absolute top-1/2 right-4 -translate-y-1/2 text-sm text-gray-400">{{ $field['unit'] }}</span>
                            </div>
                            <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                                {{ $field['hint'] ?? 'Maks. '.number_format($field['max'], 0, ',', '.').' '.$field['unit'].'.' }}
                            </p>
                            @error($field['name'])
                                <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                @include('pages.cms.vehicle-brands.partials.active-field', [
                    'active' => $model->is_active,
                    'hint' => 'Nonaktif = hilang dari dropdown Tipe (form member) & EV Garage.',
                ])
            </div>

            @if ($isEdit)
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                    Dibuat oleh {{ $model->creator?->name ?? '-' }} · {{ $model->created_at?->format('d M Y H:i') }}<br>
                    Terakhir diubah oleh {{ $model->updater?->name ?? '-' }} · {{ $model->updated_at?->format('d M Y H:i') }}
                </p>
            @endif
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
            <a href="{{ route('vehicle-models.index') }}"
                class="shadow-theme-xs inline-flex h-11 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                Batal
            </a>
            <button type="submit"
                class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 items-center justify-center rounded-lg px-5 text-sm font-medium text-white transition">
                {{ $isEdit ? 'Simpan Perubahan' : 'Simpan' }}
            </button>
        </div>
    </form>
@endsection
