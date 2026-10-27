@extends('layouts.app')

@php
    use App\Http\Requests\Cms\EventVideoRequest;
    use App\Http\Requests\Cms\GuideVideoRequest;

    $isEdit = $video->exists;
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $borderClass = fn (string $field) => $errors->has($field) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $currentStatus = old('status', $video->status?->value);
    $youtubeUrl = old('youtube_url', $video->watch_url ?? '');
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <form method="POST" enctype="multipart/form-data"
        action="{{ $isEdit ? route('event-videos.update', $video) : route('event-videos.store') }}"
        class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="space-y-6 px-5 py-6 sm:px-6">
            <!-- Status -->
                <div>
                    <span class="{{ $labelClass }}">Status<span class="text-error-500">*</span></span>
                    <div class="flex h-11 flex-wrap items-center gap-6">
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

            <!-- Judul ID / EN -->
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                @foreach (['id' => 'Indonesia', 'en' => 'English'] as $lang => $langLabel)
                    <div>
                        <label for="title_{{ $lang }}" class="{{ $labelClass }}">
                            Judul ({{ $langLabel }})@if ($lang === 'id')<span class="text-error-500">*</span>@endif
                        </label>
                        <input type="text" id="title_{{ $lang }}" name="title[{{ $lang }}]"
                            maxlength="{{ GuideVideoRequest::MAX_TITLE_LENGTH }}" @required($lang === 'id')
                            value="{{ old("title.$lang", $video->title[$lang] ?? '') }}"
                            placeholder="{{ $lang === 'en' ? 'Kosong = pakai teks Indonesia' : '' }}"
                            class="{{ $inputClass }} {{ $borderClass("title.$lang") }}" />
                        @error("title.$lang")
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <!-- Link YouTube + preview -->
                <div x-data="youtubeField(@js(['url' => $youtubeUrl]))">
                    <label for="youtube_url" class="{{ $labelClass }}">Link YouTube<span class="text-error-500">*</span></label>
                    <input type="text" id="youtube_url" name="youtube_url" x-model="url" @input="playing = false" required maxlength="500"
                        placeholder="https://www.youtube.com/watch?v=... atau https://youtu.be/..."
                        class="{{ $inputClass }} {{ $borderClass('youtube_url') }}" :class="invalid ? 'border-error-500' : ''" />
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                        Tempel link video dari YouTube (watch, youtu.be, shorts). Yang disimpan hanya ID videonya.
                    </p>
                    <p x-show="invalid" class="text-error-500 mt-1.5 text-theme-xs">Link YouTube tidak dikenali.</p>
                    <p x-show="videoId" class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">ID video: <span class="font-mono" x-text="videoId"></span></p>
                    @error('youtube_url')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror

                    <div x-show="videoId" class="mt-3 aspect-video overflow-hidden rounded-xl border border-gray-200 bg-black dark:border-gray-800">
                        <template x-if="!playing">
                            <button type="button" @click="playing = true" class="group relative block h-full w-full" title="Putar preview">
                                <img :src="thumbnail" alt="" class="h-full w-full object-cover" />
                                <span class="absolute inset-0 flex items-center justify-center bg-black/30 transition group-hover:bg-black/40">
                                    <svg class="h-14 w-14 text-white" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.5v13l11-6.5-11-6.5Z" /></svg>
                                </span>
                            </button>
                        </template>
                        <template x-if="playing">
                            <iframe :src="embedUrl" class="h-full w-full" title="Preview video"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                        </template>
                    </div>
                </div>

                <!-- Thumbnail (opsional) -->
                <div x-data="{
                    previewUrl: @js($video->thumbnail ? $video->thumbnail_url : null),
                    remove: @js((bool) old('remove_thumbnail')),
                    pick(event) {
                        const file = event.target.files[0];
                        if (!file) return;
                        if (this.previewUrl?.startsWith('blob:')) URL.revokeObjectURL(this.previewUrl);
                        this.previewUrl = URL.createObjectURL(file);
                        this.remove = false;
                    }
                }">
                    <label for="thumbnail" class="{{ $labelClass }}">Thumbnail</label>
                    <div x-show="previewUrl && !remove" class="mb-3 aspect-video overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900">
                        <img :src="previewUrl" alt="Preview thumbnail" class="h-full w-full object-cover" />
                    </div>
                    <input type="file" id="thumbnail" name="thumbnail" @change="pick" accept="{{ implode(',', GuideVideoRequest::THUMBNAIL_MIMES) }}"
                        class="focus:border-ring-brand-300 shadow-theme-xs h-11 w-full overflow-hidden rounded-lg border {{ $borderClass('thumbnail') }} bg-transparent text-sm text-gray-500 transition-colors file:mr-5 file:border-collapse file:cursor-pointer file:rounded-l-lg file:border-0 file:border-r file:border-solid file:border-gray-200 file:bg-gray-50 file:py-3 file:pr-3 file:pl-3.5 file:text-sm file:text-gray-700 placeholder:text-gray-400 hover:file:bg-gray-100 focus:outline-hidden dark:bg-gray-900 dark:text-gray-400 dark:file:border-gray-800 dark:file:bg-white/[0.03] dark:file:text-gray-400" />
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                        Opsional. JPG, PNG, WEBP (maks {{ EventVideoRequest::MAX_THUMBNAIL_KB / 1024 }} MB), rasio 16:9.
                        Jika kosong, website memakai thumbnail bawaan YouTube.
                    </p>
                    @if ($video->thumbnail)
                        <label class="mt-2 flex cursor-pointer items-center gap-2 text-theme-xs text-gray-600 dark:text-gray-400">
                            <input type="checkbox" name="remove_thumbnail" value="1" x-model="remove" class="accent-error-500 h-4 w-4" />
                            Hapus thumbnail (kembali pakai thumbnail YouTube)
                        </label>
                    @endif
                    @error('thumbnail')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            @if ($isEdit)
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                    Dibuat oleh {{ $video->creator?->name ?? '-' }} · {{ $video->created_at?->format('d M Y H:i') }}<br>
                    Terakhir diubah oleh {{ $video->updater?->name ?? '-' }} · {{ $video->updated_at?->format('d M Y H:i') }}
                </p>
            @endif
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
            <a href="{{ route('event-videos.index') }}"
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
