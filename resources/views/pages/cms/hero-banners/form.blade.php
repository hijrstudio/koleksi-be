@extends('layouts.app')

@php
    use App\Enums\MediaType;
    use App\Http\Requests\Cms\HeroBannerRequest;

    $isEdit = $banner->exists;
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $borderClass = fn (string $field) => $errors->has($field) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    $currentStatus = old('status', $banner->status?->value);
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <form method="POST" enctype="multipart/form-data"
        action="{{ $isEdit ? route('hero-banners.update', $banner) : route('hero-banners.store') }}"
        class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="space-y-6 px-5 py-6 sm:px-6">
            <!-- File -->
            <div x-data="{
                previewUrl: @js($banner->file_url),
                previewType: @js($banner->file_type?->value),
                pick(event) {
                    const file = event.target.files[0];
                    if (!file) return;
                    if (this.previewUrl?.startsWith('blob:')) URL.revokeObjectURL(this.previewUrl);
                    this.previewUrl = URL.createObjectURL(file);
                    this.previewType = file.type.startsWith('video/') ? 'video' : 'image';
                }
            }">
                <label for="file" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    File (gambar / video)@unless ($isEdit)<span class="text-error-500">*</span>@endunless
                </label>

                <div x-show="previewUrl" class="mb-3 overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900">
                    <template x-if="previewType === 'video'">
                        <video :src="previewUrl" class="max-h-72 w-full object-contain" controls muted></video>
                    </template>
                    <template x-if="previewType !== 'video'">
                        <img :src="previewUrl" alt="Preview" class="max-h-72 w-full object-contain" />
                    </template>
                </div>

                <input type="file" id="file" name="file" @change="pick"
                    accept="{{ implode(',', [...HeroBannerRequest::IMAGE_MIMES, ...HeroBannerRequest::VIDEO_MIMES]) }}"
                    @required(! $isEdit)
                    class="focus:border-ring-brand-300 shadow-theme-xs h-11 w-full overflow-hidden rounded-lg border {{ $borderClass('file') }} bg-transparent text-sm text-gray-500 transition-colors file:mr-5 file:border-collapse file:cursor-pointer file:rounded-l-lg file:border-0 file:border-r file:border-solid file:border-gray-200 file:bg-gray-50 file:py-3 file:pr-3 file:pl-3.5 file:text-sm file:text-gray-700 placeholder:text-gray-400 hover:file:bg-gray-100 focus:outline-hidden dark:bg-gray-900 dark:text-gray-400 dark:file:border-gray-800 dark:file:bg-white/[0.03] dark:file:text-gray-400" />
                <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                    Gambar: JPG, PNG, WEBP (maks {{ HeroBannerRequest::MAX_IMAGE_KB / 1024 }} MB).
                    Video: MP4, WEBM (maks {{ HeroBannerRequest::MAX_VIDEO_KB / 1024 }} MB).
                    @if ($isEdit)
                        Kosongkan jika tidak ingin mengganti file.
                    @endif
                </p>
                @error('file')
                    <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                @enderror
            </div>

            <!-- Title ID / EN -->
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                @foreach (['id' => 'Indonesia', 'en' => 'English'] as $lang => $langLabel)
                    <div>
                        <label for="title_{{ $lang }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Title ({{ $langLabel }})<span class="text-error-500">*</span>
                        </label>
                        <input type="text" id="title_{{ $lang }}" name="title[{{ $lang }}]" maxlength="255" required
                            value="{{ old("title.$lang", $banner->title[$lang] ?? '') }}"
                            class="{{ $inputClass }} {{ $borderClass("title.$lang") }}" />
                        @error("title.$lang")
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
            </div>

            <!-- Status -->
            <div>
                <span class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Status<span class="text-error-500">*</span>
                </span>
                <div class="flex flex-wrap items-center gap-6">
                    @foreach ($statuses as $status)
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                            <input type="radio" name="status" value="{{ $status->value }}" @checked($currentStatus === $status->value)
                                class="accent-brand-500 h-4 w-4" />
                            {{ $status->label() }}
                        </label>
                    @endforeach
                </div>
                @error('status')
                    <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                @enderror
            </div>

            @if ($isEdit)
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                    Dibuat oleh {{ $banner->creator?->name ?? '-' }} · {{ $banner->created_at?->format('d M Y H:i') }}<br>
                    Terakhir diubah oleh {{ $banner->updater?->name ?? '-' }} · {{ $banner->updated_at?->format('d M Y H:i') }}
                </p>
            @endif
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
            <a href="{{ route('hero-banners.index') }}"
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
