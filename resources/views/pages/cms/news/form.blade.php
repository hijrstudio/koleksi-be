@extends('layouts.app')

@php
    $isEdit = $news->exists;
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $borderClass = fn (string $field) => $errors->has($field) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    $currentCategory = old('category', $news->category?->value);
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    @if ($errors->any())
        <div class="border-error-500 bg-error-50 dark:border-error-500/30 dark:bg-error-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">Data belum tersimpan. Periksa kembali isian yang ditandai.</p>
        </div>
    @endif

    <form method="POST" enctype="multipart/form-data" x-data="{ category: @js($currentCategory ?? '') }"
        action="{{ $isEdit ? route('news.update', $news) : route('news.store') }}"
        class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="space-y-6 px-5 py-6 sm:px-6">
            <!-- Kategori & status -->
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div>
                    <label for="category" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Kategori<span class="text-error-500">*</span></label>
                    <select id="category" name="category" required x-model="category" class="{{ $inputClass }} select-chevron {{ $borderClass('category') }}">
                        <option value="">Pilih kategori</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->value }}" @selected($currentCategory === $category->value)>{{ $category->label() }}</option>
                        @endforeach
                    </select>
                    @error('category')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>

                @include('pages.cms.partials.status-field', ['model' => $news])
            </div>

            @include('pages.cms.partials.authored-content-fields', [
                'model' => $news,
                'contentImageUploadUrl' => route('news.content-images.store'),
                'memberOptionsUrl' => route('news.member-options'),
                'noun' => 'berita',
                'slugPlaceholder' => 'koleksi-gelar-touring-ev-jakarta-bandung',
                // Berita EV: link ke media lain — tanpa konten & penulis KOLEKSI
                'afterTitle' => 'pages.cms.news.partials.external-fields',
                'hideWhen' => "category === '".\App\Enums\NewsCategory::EvNews->value."'",
            ])
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
            <a href="{{ route('news.index') }}"
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
