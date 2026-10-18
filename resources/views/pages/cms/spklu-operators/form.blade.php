@extends('layouts.app')

@php
    use App\Http\Requests\Cms\SpkluOperatorRequest;

    $isEdit = $operator->exists;
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $borderClass = fn (string $field) => $errors->has($field) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';

    $textFields = [
        ['name' => 'brand_spklu', 'label' => 'Brand SPKLU', 'max' => SpkluOperatorRequest::MAX_BRAND_LENGTH, 'placeholder' => 'PLN', 'hint' => 'Nama yang tampil di accordion & slider logo website.'],
        ['name' => 'nama_operator', 'label' => 'Nama operator', 'max' => SpkluOperatorRequest::MAX_OPERATOR_LENGTH, 'placeholder' => 'PT PLN (Persero)', 'hint' => null],
        ['name' => 'aplikasi', 'label' => 'Aplikasi', 'max' => SpkluOperatorRequest::MAX_APP_LENGTH, 'placeholder' => 'PLN Mobile', 'hint' => null],
    ];

    $linkFields = [
        ['name' => 'link_playstore', 'label' => 'Link Play Store', 'placeholder' => 'https://play.google.com/store/apps/details?id=...'],
        ['name' => 'link_appstore', 'label' => 'Link App Store', 'placeholder' => 'https://apps.apple.com/id/app/...'],
    ];
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <form method="POST" enctype="multipart/form-data"
        action="{{ $isEdit ? route('spklu-operators.update', $operator) : route('spklu-operators.store') }}"
        class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="grid grid-cols-1 gap-6 px-5 py-6 sm:px-6 lg:grid-cols-5">
            <div class="space-y-6 lg:col-span-3">
                @foreach ($textFields as $field)
                    <div>
                        <label for="{{ $field['name'] }}" class="{{ $labelClass }}">{{ $field['label'] }}<span class="text-error-500">*</span></label>
                        <input type="text" id="{{ $field['name'] }}" name="{{ $field['name'] }}" required maxlength="{{ $field['max'] }}"
                            value="{{ old($field['name'], $operator->{$field['name']}) }}" placeholder="{{ $field['placeholder'] }}"
                            class="{{ $inputClass }} {{ $borderClass($field['name']) }}" />
                        @if ($field['hint'])
                            <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ $field['hint'] }}</p>
                        @endif
                        @error($field['name'])
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach

                @foreach ($linkFields as $field)
                    <div>
                        <label for="{{ $field['name'] }}" class="{{ $labelClass }}">{{ $field['label'] }}</label>
                        <input type="text" id="{{ $field['name'] }}" name="{{ $field['name'] }}" inputmode="url"
                            maxlength="{{ SpkluOperatorRequest::MAX_URL_LENGTH }}"
                            value="{{ old($field['name'], $operator->{$field['name']}) }}" placeholder="{{ $field['placeholder'] }}"
                            class="{{ $inputClass }} {{ $borderClass($field['name']) }}" />
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            Opsional. Hanya link dari {{ implode(' / ', SpkluOperatorRequest::STORE_HOSTS[$field['name']]) }}.
                        </p>
                        @error($field['name'])
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach

                @include('pages.cms.partials.status-field', ['model' => $operator])

                @if ($isEdit)
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                        Dibuat oleh {{ $operator->creator?->name ?? '-' }} · {{ $operator->created_at?->format('d M Y H:i') }}<br>
                        Terakhir diubah oleh {{ $operator->updater?->name ?? '-' }} · {{ $operator->updated_at?->format('d M Y H:i') }}
                    </p>
                @endif
            </div>

            <!-- Logo (opsional) -->
            <div class="lg:col-span-2" x-data="{
                previewUrl: @js($operator->logo_url),
                removed: @js((bool) old('remove_logo')),
                pick(event) {
                    const file = event.target.files[0];
                    if (!file) return;
                    if (this.previewUrl?.startsWith('blob:')) URL.revokeObjectURL(this.previewUrl);
                    this.previewUrl = URL.createObjectURL(file);
                    this.removed = false;
                },
                remove() {
                    if (this.previewUrl?.startsWith('blob:')) URL.revokeObjectURL(this.previewUrl);
                    this.previewUrl = null;
                    this.removed = true;
                    this.$refs.file.value = '';
                }
            }">
                <label for="logo" class="{{ $labelClass }}">Logo</label>
                <div x-show="previewUrl" class="relative mb-3 flex h-40 items-center justify-center rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800">
                    <img :src="previewUrl" alt="Preview logo" class="max-h-full max-w-full object-contain" />
                    <button type="button" @click="remove()"
                        class="border-error-300 text-error-600 hover:bg-error-50 absolute top-2 right-2 rounded-lg border bg-white px-2.5 py-1 text-theme-xs font-medium">
                        Hapus logo
                    </button>
                </div>
                <input type="hidden" name="remove_logo" :value="removed ? 1 : 0" />
                <input type="file" id="logo" name="logo" x-ref="file" @change="pick"
                    accept="{{ implode(',', SpkluOperatorRequest::LOGO_MIMES) }}"
                    class="focus:border-ring-brand-300 shadow-theme-xs h-11 w-full overflow-hidden rounded-lg border {{ $borderClass('logo') }} bg-transparent text-sm text-gray-500 transition-colors file:mr-5 file:border-collapse file:cursor-pointer file:rounded-l-lg file:border-0 file:border-r file:border-solid file:border-gray-200 file:bg-gray-50 file:py-3 file:pr-3 file:pl-3.5 file:text-sm file:text-gray-700 placeholder:text-gray-400 hover:file:bg-gray-100 focus:outline-hidden dark:bg-gray-900 dark:text-gray-400 dark:file:border-gray-800 dark:file:bg-white/[0.03] dark:file:text-gray-400" />
                <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                    Opsional. PNG / WEBP transparan disarankan, JPG juga bisa (maks {{ SpkluOperatorRequest::MAX_LOGO_KB / 1024 }} MB).
                    Tanpa logo, website menampilkan nama brand.
                </p>
                @error('logo')
                    <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
            <a href="{{ route('spklu-operators.index') }}"
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
