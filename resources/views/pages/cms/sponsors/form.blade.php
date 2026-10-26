@extends('layouts.app')

@php
    use App\Http\Requests\Cms\SponsorRequest;

    $isEdit = $sponsor->exists;
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $borderClass = fn (string $field) => $errors->has($field) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <form method="POST" enctype="multipart/form-data"
        action="{{ $isEdit ? route('sponsors.update', $sponsor) : route('sponsors.store') }}"
        class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="grid grid-cols-1 gap-6 px-5 py-6 sm:px-6 lg:grid-cols-5">
            <div class="space-y-6 lg:col-span-3">
                <!-- Nama -->
                <div>
                    <label for="title" class="{{ $labelClass }}">Nama sponsor<span class="text-error-500">*</span></label>
                    <input type="text" id="title" name="title" required maxlength="{{ SponsorRequest::MAX_TITLE_LENGTH }}"
                        value="{{ old('title', $sponsor->title) }}" placeholder="PT Mobil Listrik Indonesia"
                        class="{{ $inputClass }} {{ $borderClass('title') }}" />
                    @error('title')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>

                <!-- URL -->
                <div>
                    <label for="url" class="{{ $labelClass }}">URL website</label>
                    <input type="text" id="url" name="url" maxlength="{{ SponsorRequest::MAX_URL_LENGTH }}" inputmode="url"
                        value="{{ old('url', $sponsor->url) }}" placeholder="https://sponsor.co.id"
                        class="{{ $inputClass }} {{ $borderClass('url') }}" />
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">Opsional. Tanpa "https://" akan dilengkapi otomatis.</p>
                    @error('url')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <!-- Urutan -->
                    <div>
                        <label for="sort" class="{{ $labelClass }}">Urutan</label>
                        <input type="number" id="sort" name="sort" min="0" max="{{ SponsorRequest::MAX_SORT }}"
                            value="{{ old('sort', $sponsor->sort) }}"
                            class="{{ $inputClass }} {{ $borderClass('sort') }}" />
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">Angka kecil tampil lebih dulu.</p>
                        @error('sort')
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Status -->
                    @include('pages.cms.partials.status-field', ['model' => $sponsor])
                </div>

                @if ($isEdit)
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                        Dibuat oleh {{ $sponsor->creator?->name ?? '-' }} · {{ $sponsor->created_at?->format('d M Y H:i') }}<br>
                        Terakhir diubah oleh {{ $sponsor->updater?->name ?? '-' }} · {{ $sponsor->updated_at?->format('d M Y H:i') }}
                    </p>
                @endif
            </div>

            <!-- Logo -->
            <div class="lg:col-span-2" x-data="{
                previewUrl: @js($sponsor->logo_url),
                pick(event) {
                    const file = event.target.files[0];
                    if (!file) return;
                    if (this.previewUrl?.startsWith('blob:')) URL.revokeObjectURL(this.previewUrl);
                    this.previewUrl = URL.createObjectURL(file);
                }
            }">
                <label for="logo" class="{{ $labelClass }}">
                    Logo
                    @unless ($isEdit)
                        <span class="text-error-500">*</span>
                    @endunless
                </label>
                <div x-show="previewUrl" class="mb-3 flex h-40 items-center justify-center rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800">
                    <img :src="previewUrl" alt="Preview logo" class="max-h-full max-w-full object-contain" />
                </div>
                <input type="file" id="logo" name="logo" @change="pick" @required(! $isEdit)
                    accept="{{ implode(',', SponsorRequest::LOGO_MIMES) }}"
                    class="focus:border-ring-brand-300 shadow-theme-xs h-11 w-full overflow-hidden rounded-lg border {{ $borderClass('logo') }} bg-transparent text-sm text-gray-500 transition-colors file:mr-5 file:border-collapse file:cursor-pointer file:rounded-l-lg file:border-0 file:border-r file:border-solid file:border-gray-200 file:bg-gray-50 file:py-3 file:pr-3 file:pl-3.5 file:text-sm file:text-gray-700 placeholder:text-gray-400 hover:file:bg-gray-100 focus:outline-hidden dark:bg-gray-900 dark:text-gray-400 dark:file:border-gray-800 dark:file:bg-white/[0.03] dark:file:text-gray-400" />
                <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                    PNG / WEBP transparan disarankan, JPG juga bisa (maks {{ SponsorRequest::MAX_LOGO_KB / 1024 }} MB).
                    @if ($isEdit)
                        Kosongkan jika tidak ingin mengganti logo.
                    @endif
                </p>
                @error('logo')
                    <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
            <a href="{{ route('sponsors.index') }}"
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
