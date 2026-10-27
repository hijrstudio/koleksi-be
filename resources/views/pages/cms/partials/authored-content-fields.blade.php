{{--
    Field bersama konten bertulis (artikel panduan, berita, ...): judul + slug, konten rich editor
    (bisa sisip gambar), gambar utama, dan penulis (admin / member).

    Variabel:
      $model                   model yang sedang dibuat / diedit (HasAuthor, HasUniqueSlug, HasRichContent)
      $contentImageUploadUrl   endpoint upload gambar konten
      $memberOptionsUrl        endpoint pencarian member penulis
      $selectedMember          ['id' => .., 'label' => ..] | null
      $noun                    sebutan konten, mis. "artikel" / "berita"
      $slugPlaceholder         contoh slug
      $afterTitle              (opsional) view yang disisipkan setelah judul, mis. field khusus kategori
      $hideWhen                (opsional) ekspresi Alpine; true = field konten & penulis disembunyikan
                               (mis. Berita EV yang hanya berupa link ke media lain)
--}}
@php
    use App\Http\Requests\Cms\AuthoredContentRequest as Limits;

    $isEdit = $model->exists;
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $borderClass = fn (string $field) => $errors->has($field) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $languages = ['id' => 'Indonesia', 'en' => 'English'];
    $authorUser = $model::AUTHOR_USER;
    $authorMember = $model::AUTHOR_MEMBER;
    $authorType = old('author_type', $model->authorType() ?? $authorUser);
    // Konten milik admin lain tetap atas nama admin tsb; selain itu penulis admin = user yang login
    $adminName = $model->authorUser?->name ?? auth()->user()->name;
    $contentImageUpload = [
        'url' => $contentImageUploadUrl,
        'maxKb' => Limits::MAX_CONTENT_IMAGE_KB,
        'mimes' => Limits::CONTENT_IMAGE_MIMES,
    ];
@endphp

<!-- Judul ID / EN + slug (otomatis dari judul ID) -->
@include('pages.cms.partials.title-slug-fields', ['maxTitleLength' => Limits::MAX_TITLE_LENGTH])

@isset($afterTitle)
    @include($afterTitle)
@endisset

<!-- Konten ID / EN -->
@foreach ($languages as $lang => $langLabel)
    <div @isset($hideWhen) x-show="!({{ $hideWhen }})" @endisset>
        <label for="content_{{ $lang }}" class="{{ $labelClass }}">
            Konten ({{ $langLabel }})@if ($lang === 'id')<span class="text-error-500">*</span>@endif
        </label>
        <x-form.rich-editor id="content_{{ $lang }}" name="content[{{ $lang }}]" :image-upload="$contentImageUpload"
            :value="old('content.'.$lang, $model->content[$lang] ?? '')" :invalid="$errors->has('content.'.$lang)" />
        @if ($lang === 'id')
            <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                Gambar bisa disisipkan lewat tombol "Gambar", paste, atau drag &amp; drop
                ({{ implode(', ', array_map('strtoupper', Limits::CONTENT_IMAGE_EXTENSIONS)) }}, maks {{ Limits::MAX_CONTENT_IMAGE_KB / 1024 }} MB).
            </p>
        @endif
        @if ($lang === 'en')
            <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">Jika versi English kosong, website memakai teks Indonesia.</p>
        @endif
        @error("content.$lang")
            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
        @enderror
    </div>
@endforeach

<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
    <!-- Gambar utama -->
    <div x-data="{
        previewUrl: @js($model->image_url),
        remove: @js((bool) old('remove_image')),
        pick(event) {
            const file = event.target.files[0];
            if (!file) return;
            if (this.previewUrl?.startsWith('blob:')) URL.revokeObjectURL(this.previewUrl);
            this.previewUrl = URL.createObjectURL(file);
            this.remove = false;
        }
    }">
        <label for="image" class="{{ $labelClass }}">Gambar</label>
        <div x-show="previewUrl && !remove" class="mb-3 overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900">
            <img :src="previewUrl" alt="Preview" class="max-h-64 w-full object-contain" />
        </div>
        <input type="file" id="image" name="image" @change="pick" accept="{{ implode(',', Limits::IMAGE_MIMES) }}"
            class="focus:border-ring-brand-300 shadow-theme-xs h-11 w-full overflow-hidden rounded-lg border {{ $borderClass('image') }} bg-transparent text-sm text-gray-500 transition-colors file:mr-5 file:border-collapse file:cursor-pointer file:rounded-l-lg file:border-0 file:border-r file:border-solid file:border-gray-200 file:bg-gray-50 file:py-3 file:pr-3 file:pl-3.5 file:text-sm file:text-gray-700 placeholder:text-gray-400 hover:file:bg-gray-100 focus:outline-hidden dark:bg-gray-900 dark:text-gray-400 dark:file:border-gray-800 dark:file:bg-white/[0.03] dark:file:text-gray-400" />
        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
            JPG, PNG, WEBP (maks {{ Limits::MAX_IMAGE_KB / 1024 }} MB).
            @if ($model->image)
                Kosongkan jika tidak ingin mengganti gambar.
            @endif
        </p>
        @if ($model->image)
            <label class="mt-2 flex cursor-pointer items-center gap-2 text-theme-xs text-gray-600 dark:text-gray-400">
                <input type="checkbox" name="remove_image" value="1" x-model="remove" class="accent-error-500 h-4 w-4" />
                Hapus gambar saat ini
            </label>
        @endif
        @error('image')
            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
        @enderror
    </div>

    <!-- Penulis -->
    <div x-data="{ type: @js($authorType) }" @isset($hideWhen) x-show="!({{ $hideWhen }})" @endisset>
        <span class="{{ $labelClass }}">Penulis<span class="text-error-500">*</span></span>
        <div class="flex flex-wrap items-center gap-6">
            <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                <input type="radio" name="author_type" value="{{ $authorUser }}" x-model="type" class="accent-brand-500 h-4 w-4" />
                Admin
            </label>
            <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                <input type="radio" name="author_type" value="{{ $authorMember }}" x-model="type" class="accent-brand-500 h-4 w-4" />
                Member
            </label>
        </div>

        <p x-show="type === @js($authorUser)" class="mt-3 text-sm text-gray-700 dark:text-gray-300">
            Ditulis oleh admin: <strong>{{ $adminName }}</strong>
        </p>

        <div x-show="type === @js($authorMember)" class="relative mt-3"
            x-data="memberPicker(@js(['url' => $memberOptionsUrl, 'selected' => $selectedMember]))"
            @click.outside="open = false">
            <input type="hidden" name="author_member_id" :value="selected?.id ?? ''" :disabled="type !== @js($authorMember)">

            <div x-show="selected" class="flex items-center justify-between gap-3 rounded-lg border border-gray-300 px-4 py-2.5 dark:border-gray-700">
                <span class="text-sm text-gray-800 dark:text-white/90" x-text="selected?.label"></span>
                <button type="button" @click="clear()" class="text-theme-xs text-error-600 hover:underline">Ganti</button>
            </div>

            <div x-show="!selected">
                <input type="search" x-ref="search" x-model="query" @input="search()" @focus="fetch()"
                    placeholder="Cari nama, email, atau No. lambung (#7)..." autocomplete="off"
                    class="{{ $inputClass }} {{ $borderClass('author_member_id') }}" />
                <div x-show="open" class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-800 dark:bg-gray-900">
                    <template x-for="member in results" :key="member.id">
                        <button type="button" @click="choose(member)" x-text="member.label"
                            class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.05]"></button>
                    </template>
                    <p x-show="!loading && results.length === 0" class="px-4 py-2 text-sm text-gray-500">Member tidak ditemukan.</p>
                </div>
                <p x-show="loading" class="mt-1.5 text-theme-xs text-gray-500">Mencari...</p>
                <p x-show="failed" class="text-error-500 mt-1.5 text-theme-xs">Gagal memuat data member. Coba lagi.</p>
            </div>
        </div>
        @error('author_member_id')
            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
        @enderror
        @error('author_type')
            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
        @enderror
    </div>
</div>

@if ($isEdit)
    <p class="text-theme-xs text-gray-500 dark:text-gray-400">
        Dibuat oleh {{ $model->creator?->name ?? '-' }} · {{ $model->created_at?->format('d M Y H:i') }}<br>
        Terakhir diubah oleh {{ $model->updater?->name ?? '-' }} · {{ $model->updated_at?->format('d M Y H:i') }}
    </p>
@endif
