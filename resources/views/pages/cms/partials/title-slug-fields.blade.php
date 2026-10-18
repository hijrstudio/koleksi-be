{{--
    Judul ID / EN + slug yang otomatis terisi dari judul ID (lihat resources/js/components/slug-field.js).

    Variabel:
      $model             model yang sedang dibuat / diedit (HasUniqueSlug, kolom json `title`)
      $noun              sebutan konten, mis. "artikel" / "berita" / "event"
      $slugPlaceholder   contoh slug
      $maxTitleLength    panjang maksimal judul
--}}
@php
    $isEdit = $model->exists;
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $borderClass = fn (string $field) => $errors->has($field) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    // Saat create slug mengikuti judul; saat edit slug dikunci (tidak ikut berubah) agar link lama tetap jalan
    $slugValue = old('slug', $model->slug ?? '');
    $slugAuto = old('slug_auto') !== null ? (bool) old('slug_auto') : ! $isEdit;
@endphp

<div class="space-y-6" x-data="slugField(@js(['slug' => $slugValue, 'auto' => $slugAuto]))">
    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
        @foreach (['id' => 'Indonesia', 'en' => 'English'] as $lang => $langLabel)
            <div>
                <label for="title_{{ $lang }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Judul ({{ $langLabel }})@if ($lang === 'id')<span class="text-error-500">*</span>@endif
                </label>
                <input type="text" id="title_{{ $lang }}" name="title[{{ $lang }}]"
                    maxlength="{{ $maxTitleLength }}" @required($lang === 'id')
                    value="{{ old("title.$lang", $model->title[$lang] ?? '') }}"
                    placeholder="{{ $lang === 'en' ? 'Kosong = pakai teks Indonesia' : '' }}"
                    @if ($lang === 'id') x-ref="titleId" @input="fromTitle($event.target.value)" @endif
                    class="{{ $inputClass }} {{ $borderClass("title.$lang") }}" />
                @error("title.$lang")
                    <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                @enderror
            </div>
        @endforeach
    </div>

    <div>
        <div class="mb-1.5 flex items-center justify-between gap-3">
            <label for="slug" class="text-sm font-medium text-gray-700 dark:text-gray-400">Slug (URL)</label>
            <button type="button" @click="regenerate($refs.titleId.value)" x-show="!auto"
                class="text-brand-500 text-theme-xs hover:underline">Buat ulang dari judul</button>
        </div>
        <input type="text" id="slug" name="slug" x-model="slug" @input="edited()"
            maxlength="{{ $model::MAX_SLUG_LENGTH }}" placeholder="{{ $slugPlaceholder }}"
            class="{{ $inputClass }} {{ $borderClass('slug') }} font-mono" />
        <input type="hidden" name="slug_auto" :value="auto ? 1 : 0">
        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
            <span x-show="auto">Terisi otomatis dari judul (Indonesia).</span>
            <span x-show="!auto">Diisi manual.</span>
            Huruf kecil, angka, dan tanda hubung. Harus unik; jika slug otomatis sudah dipakai, sistem menambahkan akhiran -2, -3, dst.
            @if ($isEdit)
                <br>Mengubah slug {{ $noun }} yang sudah dipublish akan membuat link lama tidak bisa dibuka.
            @endif
        </p>
        @error('slug')
            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
        @enderror
    </div>
</div>
