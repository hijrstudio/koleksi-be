@extends('layouts.app')

@php
    use App\Enums\ContentStatus;
    use App\Http\Requests\Cms\EventRequest;

    $isEdit = $event->exists;
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $borderClass = fn (string $field) => $errors->has($field) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $languages = ['id' => 'Indonesia', 'en' => 'English'];
    $currentCategory = (int) old('event_category_id', $event->event_category_id);
    $startDate = old('start_date', $event->start_date?->format('Y-m-d'));
    // Event satu hari: tanggal selesai tidak perlu ditampilkan (kosong = sama dengan tanggal mulai)
    $endDate = old('end_date', $event->end_date && ! $event->end_date->equalTo($event->start_date) ? $event->end_date->format('Y-m-d') : null);
    $gmapValue = old('location_gmap', $event->hasCoordinates() ? "{$event->latitude}, {$event->longitude}" : '');
    $richFields = [
        'description' => ['label' => 'Deskripsi', 'required' => true],
        'registration_info' => ['label' => 'Info pendaftaran', 'required' => false],
    ];
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    @if ($errors->any())
        <div class="border-error-500 bg-error-50 dark:border-error-500/30 dark:bg-error-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">Data belum tersimpan. Periksa kembali isian yang ditandai.</p>
        </div>
    @endif

    <form method="POST" enctype="multipart/form-data" action="{{ $isEdit ? route('events.update', $event) : route('events.store') }}"
        class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="space-y-6 px-5 py-6 sm:px-6">
            <!-- Kategori & status -->
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div>
                    <label for="event_category_id" class="{{ $labelClass }}">Kategori<span class="text-error-500">*</span></label>
                    <select id="event_category_id" name="event_category_id" required
                        class="{{ $inputClass }} select-chevron {{ $borderClass('event_category_id') }}">
                        <option value="">Pilih kategori</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected($currentCategory === $category->id)>
                                {{ $category->name['id'] ?? '-' }}{{ $category->status === ContentStatus::Draft ? ' (draft)' : '' }}
                            </option>
                        @endforeach
                    </select>
                    @if ($categories->isEmpty())
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            Belum ada kategori. <a href="{{ route('event-categories.create') }}" class="text-brand-500 hover:underline">Tambah kategori</a> terlebih dahulu.
                        </p>
                    @endif
                    @error('event_category_id')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>

                @include('pages.cms.partials.status-field', ['model' => $event])
            </div>

            <!-- Judul + slug -->
            @include('pages.cms.partials.title-slug-fields', [
                'model' => $event,
                'noun' => 'event',
                'slugPlaceholder' => 'touring-ev-jakarta-bandung-2026',
                'maxTitleLength' => EventRequest::MAX_TITLE_LENGTH,
            ])

            <!-- Gambar -->
            <div class="max-w-xl" x-data="{
                previewUrl: @js($event->image_url),
                remove: @js((bool) old('remove_image')),
                pick(event) {
                    const file = event.target.files[0];
                    if (!file) return;
                    if (this.previewUrl?.startsWith('blob:')) URL.revokeObjectURL(this.previewUrl);
                    this.previewUrl = URL.createObjectURL(file);
                    this.remove = false;
                }
            }">
                <label for="image" class="{{ $labelClass }}">Gambar / poster</label>
                <div x-show="previewUrl && !remove" class="mb-3 overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900">
                    <img :src="previewUrl" alt="Preview" class="max-h-72 w-full object-contain" />
                </div>
                <input type="file" id="image" name="image" @change="pick" accept="{{ implode(',', EventRequest::IMAGE_MIMES) }}"
                    class="focus:border-ring-brand-300 shadow-theme-xs h-11 w-full overflow-hidden rounded-lg border {{ $borderClass('image') }} bg-transparent text-sm text-gray-500 transition-colors file:mr-5 file:border-collapse file:cursor-pointer file:rounded-l-lg file:border-0 file:border-r file:border-solid file:border-gray-200 file:bg-gray-50 file:py-3 file:pr-3 file:pl-3.5 file:text-sm file:text-gray-700 placeholder:text-gray-400 hover:file:bg-gray-100 focus:outline-hidden dark:bg-gray-900 dark:text-gray-400 dark:file:border-gray-800 dark:file:bg-white/[0.03] dark:file:text-gray-400" />
                <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                    JPG, PNG, WEBP (maks {{ EventRequest::MAX_IMAGE_KB / 1024 }} MB).
                    @if ($event->image)
                        Kosongkan jika tidak ingin mengganti gambar.
                    @endif
                </p>
                @if ($event->image)
                    <label class="mt-2 flex cursor-pointer items-center gap-2 text-theme-xs text-gray-600 dark:text-gray-400">
                        <input type="checkbox" name="remove_image" value="1" x-model="remove" class="accent-error-500 h-4 w-4" />
                        Hapus gambar saat ini
                    </label>
                @endif
                @error('image')
                    <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                @enderror
            </div>

            <!-- Tanggal -->
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div>
                    <x-form.date-picker id="start_date" name="start_date" label="Tanggal mulai" required
                        :defaultDate="$startDate" altFormat="j F Y" locale="id" placeholder="Pilih tanggal"
                        :invalid="$errors->has('start_date')" />
                    @error('start_date')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <x-form.date-picker id="end_date" name="end_date" label="Tanggal selesai"
                        :defaultDate="$endDate" altFormat="j F Y" locale="id" placeholder="Kosongkan bila event 1 hari"
                        :invalid="$errors->has('end_date')" />
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                        Fase di website (akan datang / berlangsung / selesai) otomatis dihitung dari tanggal ini.
                    </p>
                    @error('end_date')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Lokasi + titik Google Maps -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2" x-data="locationField(@js(['value' => $gmapValue]))">
                <div class="space-y-6">
                    <div>
                        <label for="location" class="{{ $labelClass }}">Lokasi<span class="text-error-500">*</span></label>
                        <input type="text" id="location" name="location" required maxlength="{{ EventRequest::MAX_LOCATION_LENGTH }}"
                            value="{{ old('location', $event->location) }}" placeholder="Monas, Jakarta Pusat"
                            class="{{ $inputClass }} {{ $borderClass('location') }}" />
                        @error('location')
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="location_gmap" class="{{ $labelClass }}">Titik Google Maps</label>
                        <input type="text" id="location_gmap" name="location_gmap" x-model="value" maxlength="2000"
                            placeholder="Tempel link Google Maps atau koordinat, mis. -6.175392, 106.827153"
                            class="{{ $inputClass }} {{ $borderClass('location_gmap') }}" :class="invalid ? 'border-error-500' : ''" />
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            Opsional. Buka Google Maps → klik kanan titik lokasi → klik koordinat untuk menyalin, lalu tempel di sini.
                            Bisa juga tempel link dari address bar. Yang disimpan hanya titik koordinatnya.
                        </p>
                        <p x-show="isShortLink && invalid" class="text-error-500 mt-1.5 text-theme-xs">
                            Link pendek (maps.app.goo.gl) tidak bisa dibaca. Buka link tersebut, lalu salin link lengkap dari address bar.
                        </p>
                        <p x-show="invalid && !isShortLink" class="text-error-500 mt-1.5 text-theme-xs">Titik lokasi tidak dikenali.</p>
                        <p x-show="point" class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            Koordinat: <span class="font-mono" x-text="point ? `${point.lat}, ${point.lng}` : ''"></span>
                            · <a :href="mapsUrl" target="_blank" rel="noopener noreferrer" class="text-brand-500 hover:underline">Buka di Google Maps</a>
                        </p>
                        @error('location_gmap')
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div>
                    <span class="{{ $labelClass }}">Preview peta</span>
                    <div class="aspect-video overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900">
                        <template x-if="embedUrl">
                            <iframe :src="embedUrl" class="h-full w-full" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Preview lokasi"></iframe>
                        </template>
                        <div x-show="!embedUrl" class="flex h-full items-center justify-center px-6 text-center text-theme-sm text-gray-400">
                            Peta muncul setelah titik Google Maps diisi.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Deskripsi & info pendaftaran (rich text, ID / EN) -->
            @foreach ($richFields as $field => $config)
                <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    @foreach ($languages as $lang => $langLabel)
                        <div>
                            <label for="{{ $field }}_{{ $lang }}" class="{{ $labelClass }}">
                                {{ $config['label'] }} ({{ $langLabel }})@if ($config['required'] && $lang === 'id')<span class="text-error-500">*</span>@endif
                            </label>
                            <x-form.rich-editor id="{{ $field }}_{{ $lang }}" name="{{ $field }}[{{ $lang }}]"
                                :value="old($field.'.'.$lang, $event->{$field}[$lang] ?? '')" :invalid="$errors->has($field.'.'.$lang)" />
                            @if ($lang === 'en')
                                <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">Jika kosong, website memakai teks Indonesia.</p>
                            @endif
                            @error("$field.$lang")
                                <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
                </div>
            @endforeach

            @if ($isEdit)
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                    Dibuat oleh {{ $event->creator?->name ?? '-' }} · {{ $event->created_at?->format('d M Y H:i') }}<br>
                    Terakhir diubah oleh {{ $event->updater?->name ?? '-' }} · {{ $event->updated_at?->format('d M Y H:i') }}
                </p>
            @endif
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
            <a href="{{ route('events.index') }}"
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
