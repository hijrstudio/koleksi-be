{{--
    Field khusus Berita EV (berita dari media lain): ringkasan ID/EN, link, media, penulis & tanggal berita.
    Tampil hanya saat kategori Berita EV dipilih (x-show memakai `category` dari form berita). Variabel: $news.
--}}
@php
    use App\Http\Requests\Cms\NewsRequest as Limits;

    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $textareaClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $borderClass = fn (string $field) => $errors->has($field) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $required = '<span class="text-error-500">*</span>';
@endphp

<div x-show="category === @js(\App\Enums\NewsCategory::EvNews->value)" x-cloak
    class="space-y-6 rounded-xl border border-dashed border-gray-300 p-4 sm:p-5 dark:border-gray-700">
    <p class="text-sm text-gray-600 dark:text-gray-400">
        Berita EV berupa tautan ke berita di media lain: di website, kartunya dibuka di tab baru ke link berita.
        Konten & penulis KOLEKSI tidak dipakai.
    </p>

    <!-- Ringkasan ID / EN -->
    @foreach (['id' => 'Indonesia', 'en' => 'English'] as $lang => $langLabel)
        <div>
            <label for="summary_{{ $lang }}" class="{{ $labelClass }}">Ringkasan ({{ $langLabel }})@if ($lang === 'id'){!! $required !!}@endif</label>
            <textarea id="summary_{{ $lang }}" name="summary[{{ $lang }}]" rows="3" maxlength="{{ Limits::MAX_SUMMARY_LENGTH }}"
                placeholder="Ringkasan singkat isi berita"
                class="{{ $textareaClass }} {{ $borderClass('summary.'.$lang) }}">{{ old('summary.'.$lang, $news->summary[$lang] ?? '') }}</textarea>
            @if ($lang === 'en')
                <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">Jika versi English kosong, website memakai teks Indonesia.</p>
            @endif
            @error('summary.'.$lang)
                <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
            @enderror
        </div>
    @endforeach

    <!-- Link berita -->
    <div>
        <label for="source_url" class="{{ $labelClass }}">Link berita{!! $required !!}</label>
        <input type="text" id="source_url" name="source_url" inputmode="url" maxlength="{{ Limits::MAX_SOURCE_URL_LENGTH }}"
            value="{{ old('source_url', $news->source_url) }}" placeholder="https://www.kompas.com/..."
            class="{{ $inputClass }} {{ $borderClass('source_url') }}" />
        @error('source_url')
            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
        @enderror
    </div>

    <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
        <!-- Media -->
        <div>
            <label for="source_name" class="{{ $labelClass }}">Media{!! $required !!}</label>
            <input type="text" id="source_name" name="source_name" maxlength="{{ Limits::MAX_SOURCE_NAME_LENGTH }}"
                value="{{ old('source_name', $news->source_name) }}" placeholder="Kompas.com"
                class="{{ $inputClass }} {{ $borderClass('source_name') }}" />
            @error('source_name')
                <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
            @enderror
        </div>

        <!-- Penulis di media tsb -->
        <div>
            <label for="source_author" class="{{ $labelClass }}">Penulis</label>
            <input type="text" id="source_author" name="source_author" maxlength="{{ Limits::MAX_SOURCE_NAME_LENGTH }}"
                value="{{ old('source_author', $news->source_author) }}" placeholder="Nama penulis di media tsb"
                class="{{ $inputClass }} {{ $borderClass('source_author') }}" />
            <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">Tampil sebagai "Oleh". Kosong = nama media.</p>
            @error('source_author')
                <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
            @enderror
        </div>

        <!-- Tanggal berita -->
        <div>
            <label for="source_published_on" class="{{ $labelClass }}">Tanggal berita{!! $required !!}</label>
            <input type="date" id="source_published_on" name="source_published_on" max="{{ now()->toDateString() }}"
                value="{{ old('source_published_on', $news->source_published_on?->toDateString()) }}"
                class="{{ $inputClass }} {{ $borderClass('source_published_on') }}" />
            @error('source_published_on')
                <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
            @enderror
        </div>
    </div>
</div>
