@extends('layouts.app')

@php
    use App\Http\Requests\Cms\GalleryAlbumRequest;

    $isEdit = $album->exists;
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $borderClass = fn (string $field) => $errors->has($field) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $currentStatus = old('status', $album->status?->value);
    $galleryUpload = [
        'url' => route('gallery-albums.photo-uploads.store'),
        'maxKb' => GalleryAlbumRequest::MAX_PHOTO_KB,
        'mimes' => GalleryAlbumRequest::IMAGE_MIMES,
    ];
    $photoErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => str_starts_with($key, 'photos'))->all();
    $iconButton = 'inline-flex h-7 w-7 items-center justify-center rounded-md bg-white/90 text-gray-700 shadow hover:bg-white disabled:opacity-40';
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    @if ($errors->any())
        <div class="border-error-500 bg-error-50 dark:border-error-500/30 dark:bg-error-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">Data belum tersimpan. Periksa kembali isian yang ditandai.</p>
            @if (! $isEdit || $errors->has('image'))
                <p class="text-sm text-gray-700 dark:text-gray-300">Cover yang baru dipilih perlu dipilih ulang. Foto galeri yang sudah terupload tetap tersimpan di form.</p>
            @endif
        </div>
    @endif

    <form method="POST" enctype="multipart/form-data" class="space-y-6"
        action="{{ $isEdit ? route('gallery-albums.update', $album) : route('gallery-albums.store') }}"
        x-data="galleryForm(@js(['photos' => $photos, 'upload' => $galleryUpload, 'maxPhotos' => GalleryAlbumRequest::MAX_PHOTOS, 'errors' => $photoErrors]))"
        @submit="submit($event)">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <!-- Info album -->
        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="border-b border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
                <h3 class="text-base font-medium text-gray-800 dark:text-white/90">Album</h3>
            </div>
            <div class="grid grid-cols-1 gap-6 px-5 py-6 sm:px-6 lg:grid-cols-5">
                <div class="space-y-6 lg:col-span-3">
                    @foreach (['id' => 'Indonesia', 'en' => 'English'] as $lang => $langLabel)
                        <div>
                            <label for="title_{{ $lang }}" class="{{ $labelClass }}">
                                Judul ({{ $langLabel }})@if ($lang === 'id')<span class="text-error-500">*</span>@endif
                            </label>
                            <input type="text" id="title_{{ $lang }}" name="title[{{ $lang }}]"
                                maxlength="{{ GalleryAlbumRequest::MAX_TITLE_LENGTH }}" @required($lang === 'id')
                                value="{{ old("title.$lang", $album->title[$lang] ?? '') }}"
                                placeholder="{{ $lang === 'en' ? 'Kosong = pakai teks Indonesia' : '' }}"
                                class="{{ $inputClass }} {{ $borderClass("title.$lang") }}" />
                            @error("title.$lang")
                                <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach

                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div>
                            <x-form.date-picker id="gallery_date" name="gallery_date" label="Tanggal galeri" required
                                :defaultDate="old('gallery_date', $album->gallery_date?->format('Y-m-d'))"
                                altFormat="j F Y" locale="id" placeholder="Pilih tanggal"
                                :invalid="$errors->has('gallery_date')" />
                            @error('gallery_date')
                                <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                            @enderror
                        </div>

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
                    </div>
                </div>

                <!-- Cover -->
                <div class="lg:col-span-2" x-data="{
                    previewUrl: @js($album->image_url),
                    pick(event) {
                        const file = event.target.files[0];
                        if (!file) return;
                        if (this.previewUrl?.startsWith('blob:')) URL.revokeObjectURL(this.previewUrl);
                        this.previewUrl = URL.createObjectURL(file);
                    }
                }">
                    <label for="image" class="{{ $labelClass }}">
                        Cover album
                        @unless ($isEdit)
                            <span class="text-error-500">*</span>
                        @endunless
                    </label>
                    <div x-show="previewUrl" class="mb-3 aspect-[4/3] overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900">
                        <img :src="previewUrl" alt="Preview cover" class="h-full w-full object-cover" />
                    </div>
                    <input type="file" id="image" name="image" @change="pick" @required(! $isEdit)
                        accept="{{ implode(',', GalleryAlbumRequest::IMAGE_MIMES) }}"
                        class="focus:border-ring-brand-300 shadow-theme-xs h-11 w-full overflow-hidden rounded-lg border {{ $borderClass('image') }} bg-transparent text-sm text-gray-500 transition-colors file:mr-5 file:border-collapse file:cursor-pointer file:rounded-l-lg file:border-0 file:border-r file:border-solid file:border-gray-200 file:bg-gray-50 file:py-3 file:pr-3 file:pl-3.5 file:text-sm file:text-gray-700 placeholder:text-gray-400 hover:file:bg-gray-100 focus:outline-hidden dark:bg-gray-900 dark:text-gray-400 dark:file:border-gray-800 dark:file:bg-white/[0.03] dark:file:text-gray-400" />
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                        JPG, PNG, WEBP (maks {{ GalleryAlbumRequest::MAX_COVER_KB / 1024 }} MB).
                        @if ($isEdit)
                            Kosongkan jika tidak ingin mengganti cover.
                        @endif
                    </p>
                    @error('image')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        <!-- Foto galeri (repeater) -->
        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:border-gray-800">
                <div>
                    <h3 class="text-base font-medium text-gray-800 dark:text-white/90">
                        Foto galeri<span class="text-error-500">*</span>
                        <span class="text-theme-sm font-normal text-gray-500" x-text="'(' + photos.length + '/{{ GalleryAlbumRequest::MAX_PHOTOS }})'"></span>
                    </h3>
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                        JPG, PNG, WEBP (maks {{ GalleryAlbumRequest::MAX_PHOTO_KB / 1024 }} MB per foto). Foto langsung diupload saat dipilih.
                        Urutan foto = urutan tampil di website.
                    </p>
                </div>
                <div>
                    <input type="file" x-ref="photoInput" class="hidden" multiple accept="{{ implode(',', GalleryAlbumRequest::IMAGE_MIMES) }}" @change="onPick($event)">
                    <button type="button" @click="$refs.photoInput.click()"
                        class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-10 items-center justify-center gap-2 rounded-lg px-4 text-sm font-medium text-white transition">
                        + Tambah Foto
                    </button>
                </div>
            </div>

            <div class="px-5 py-5 sm:px-6"
                @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="onDrop($event)">
                <p x-show="uploadingCount" class="mb-3 text-theme-sm text-gray-600 dark:text-gray-400" x-text="'Mengupload ' + uploadingCount + ' foto...'"></p>
                @foreach ($photoErrors as $messages)
                    <p class="text-error-500 mb-1 text-theme-xs">{{ $messages[0] }}</p>
                @endforeach

                <div x-show="photos.length === 0"
                    class="flex flex-col items-center justify-center rounded-xl border-2 border-dashed px-6 py-12 text-center"
                    :class="dragging ? 'border-brand-500 bg-brand-50 dark:bg-brand-500/10' : 'border-gray-300 dark:border-gray-700'">
                    <p class="text-sm text-gray-600 dark:text-gray-400">Drag &amp; drop foto ke sini, atau klik "Tambah Foto".</p>
                    <p class="mt-1 text-theme-xs text-gray-400">Bisa memilih banyak foto sekaligus.</p>
                </div>

                <div x-show="photos.length > 0" class="grid grid-cols-2 gap-4 rounded-xl sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6"
                    :class="dragging ? 'ring-brand-500 ring-2 ring-offset-4' : ''">
                    <template x-for="(photo, index) in photos" :key="photo.key">
                        <div class="group relative aspect-square overflow-hidden rounded-lg border bg-gray-100 dark:bg-gray-800"
                            :class="photo.error || error(index, 'path') || error(index, 'id') ? 'border-error-500' : 'border-gray-200 dark:border-gray-800'">
                            <img :src="photo.url" alt="" class="h-full w-full object-cover" :class="photo.uploading ? 'opacity-50' : ''" />

                            <template x-if="photo.id">
                                <input type="hidden" :name="`photos[${index}][id]`" :value="photo.id">
                            </template>
                            <template x-if="!photo.id && photo.path">
                                <input type="hidden" :name="`photos[${index}][path]`" :value="photo.path">
                            </template>

                            <span class="absolute top-1.5 left-1.5 rounded bg-black/60 px-1.5 text-theme-xs text-white" x-text="index + 1"></span>

                            <!-- Progress upload -->
                            <div x-show="photo.uploading" class="absolute inset-x-2 bottom-2 h-1.5 overflow-hidden rounded-full bg-white/70">
                                <div class="bg-brand-500 h-full transition-all" :style="`width: ${photo.progress}%`"></div>
                            </div>

                            <!-- Error -->
                            <div x-show="photo.error || error(index, 'path') || error(index, 'id')"
                                class="bg-error-500/90 absolute inset-x-0 bottom-0 px-2 py-1 text-theme-xs text-white"
                                x-text="photo.error || error(index, 'path') || error(index, 'id')"></div>

                            <!-- Aksi -->
                            <div class="absolute top-1.5 right-1.5 flex gap-1 opacity-100 transition sm:opacity-0 sm:group-hover:opacity-100">
                                <button type="button" title="Geser ke kiri" @click="move(index, -1)" :disabled="index === 0" class="{{ $iconButton }}">←</button>
                                <button type="button" title="Geser ke kanan" @click="move(index, 1)" :disabled="index === photos.length - 1" class="{{ $iconButton }}">→</button>
                                <button type="button" title="Hapus foto" @click="remove(index)"
                                    class="inline-flex h-7 w-7 items-center justify-center rounded-md bg-error-500 text-white shadow hover:bg-error-600">✕</button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                @if ($isEdit)
                    Dibuat oleh {{ $album->creator?->name ?? '-' }} · {{ $album->created_at?->format('d M Y H:i') }} ·
                    Terakhir diubah oleh {{ $album->updater?->name ?? '-' }} · {{ $album->updated_at?->format('d M Y H:i') }}
                @endif
            </p>
            <div class="flex items-center gap-3">
                <a href="{{ route('gallery-albums.index') }}"
                    class="shadow-theme-xs inline-flex h-11 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                    Batal
                </a>
                <button type="submit" :disabled="uploadingCount > 0"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 items-center justify-center rounded-lg px-5 text-sm font-medium text-white transition disabled:cursor-wait disabled:opacity-60">
                    <span x-show="uploadingCount === 0">{{ $isEdit ? 'Simpan Perubahan' : 'Simpan' }}</span>
                    <span x-show="uploadingCount > 0">Menunggu upload...</span>
                </button>
            </div>
        </div>
    </form>
@endsection
