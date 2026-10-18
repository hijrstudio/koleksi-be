@extends('layouts.app')

@php
    use App\Enums\ContentStatus;

    $isEdit = $article->exists;
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $borderClass = fn (string $field) => $errors->has($field) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $currentCategory = (int) old('guide_category_id', $article->guide_category_id);
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    @if ($errors->any())
        <div class="border-error-500 bg-error-50 dark:border-error-500/30 dark:bg-error-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">Data belum tersimpan. Periksa kembali isian yang ditandai.</p>
        </div>
    @endif

    <form method="POST" enctype="multipart/form-data"
        action="{{ $isEdit ? route('guide-articles.update', $article) : route('guide-articles.store') }}"
        class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="space-y-6 px-5 py-6 sm:px-6">
            <!-- Kategori & status -->
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div>
                    <label for="guide_category_id" class="{{ $labelClass }}">Kategori<span class="text-error-500">*</span></label>
                    <select id="guide_category_id" name="guide_category_id" required
                        class="{{ $inputClass }} select-chevron {{ $borderClass('guide_category_id') }}">
                        <option value="">Pilih kategori</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected($currentCategory === $category->id)>
                                {{ $category->name['id'] ?? '-' }}{{ $category->status === ContentStatus::Draft ? ' (draft)' : '' }}
                            </option>
                        @endforeach
                    </select>
                    @if ($categories->isEmpty())
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            Belum ada kategori. <a href="{{ route('guide-categories.create') }}" class="text-brand-500 hover:underline">Tambah kategori</a> terlebih dahulu.
                        </p>
                    @endif
                    @error('guide_category_id')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>

                @include('pages.cms.partials.status-field', ['model' => $article])
            </div>

            @include('pages.cms.partials.authored-content-fields', [
                'model' => $article,
                'contentImageUploadUrl' => route('guide-articles.content-images.store'),
                'memberOptionsUrl' => route('guide-articles.member-options'),
                'noun' => 'artikel',
                'slugPlaceholder' => 'cara-merawat-baterai-mobil-listrik',
            ])
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
            <a href="{{ route('guide-articles.index') }}"
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
