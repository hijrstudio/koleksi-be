@extends('layouts.app')

@php
    use App\Models\ForumDiscussion;

    $isEdit = $discussion->exists;
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90';
    $border = fn (string $f) => $errors->has($f) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $radio = fn (string $name, array $options, $current) => collect($options)->map(fn ($label, $value) => compact('label', 'value') + ['checked' => (string) old($name, $current) === (string) $value]);
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <form method="POST" enctype="multipart/form-data"
        action="{{ $isEdit ? route('forum-discussions.update', $discussion) : route('forum-discussions.store') }}"
        class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="space-y-6 px-5 py-6 sm:px-6">
            @unless ($isEdit)
                <p class="text-theme-sm text-gray-500 dark:text-gray-400">Diskusi dari CMS tampil di website atas nama Anda dengan label Moderator.</p>
            @endunless

            <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                <div class="md:col-span-2">
                    <label for="title" class="{{ $labelClass }}">Judul<span class="text-error-500">*</span></label>
                    <input type="text" id="title" name="title" required maxlength="{{ ForumDiscussion::TITLE_MAX }}"
                        value="{{ old('title', $discussion->title) }}" class="{{ $inputClass }} h-11 {{ $border('title') }}" />
                    @error('title')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="category_id" class="{{ $labelClass }}">Kategori<span class="text-error-500">*</span></label>
                    <select id="category_id" name="category_id" required class="{{ $inputClass }} select-chevron h-11 {{ $border('category_id') }}">
                        <option value="">Pilih kategori</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) old('category_id', $discussion->category_id) === (string) $category->id)>
                                {{ $category->name }}{{ $category->is_active ? '' : ' (nonaktif)' }}
                            </option>
                        @endforeach
                    </select>
                    @error('category_id')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div>
                <label for="body" class="{{ $labelClass }}">Isi Diskusi<span class="text-error-500">*</span></label>
                <textarea id="body" name="body" rows="10" required maxlength="{{ ForumDiscussion::BODY_MAX }}"
                    class="{{ $inputClass }} {{ $border('body') }}">{{ old('body', $discussion->body) }}</textarea>
                <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">Teks biasa (tanpa format); baris baru dipertahankan.</p>
                @error('body')
                    <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="image" class="{{ $labelClass }}">Gambar Sampul (opsional)</label>
                @if ($discussion->imageUrl())
                    <img src="{{ $discussion->imageUrl() }}" alt="" class="mb-3 h-32 rounded-lg object-cover" />
                    <label class="mb-3 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                        <input type="checkbox" name="remove_image" value="1" class="accent-brand-500 h-4 w-4" /> Hapus gambar
                    </label>
                @endif
                <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp"
                    class="block text-sm text-gray-700 file:mr-4 file:rounded-lg file:border-0 file:bg-gray-100 file:px-4 file:py-2 dark:text-gray-400 dark:file:bg-gray-800" />
                <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">JPG / PNG / WEBP, maks 2 MB, minimal 300×150 px.</p>
                @error('image')
                    <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                @foreach ([
                    ['status', 'Status', [ForumDiscussion::PUBLISHED => 'Tampil', ForumDiscussion::HIDDEN => 'Disembunyikan'], $discussion->status],
                    ['is_pinned', 'Sematkan di atas', [0 => 'Tidak', 1 => 'Ya'], (int) $discussion->is_pinned],
                    ['is_locked', 'Komentar member', [0 => 'Dibuka', 1 => 'Ditutup'], (int) $discussion->is_locked],
                ] as [$name, $label, $options, $current])
                    <div>
                        <span class="{{ $labelClass }}">{{ $label }}</span>
                        <div class="flex h-11 flex-wrap items-center gap-6">
                            @foreach ($radio($name, $options, $current) as $option)
                                <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                                    <input type="radio" name="{{ $name }}" value="{{ $option['value'] }}" @checked($option['checked']) class="accent-brand-500 h-4 w-4" />
                                    {{ $option['label'] }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
            <a href="{{ $isEdit ? route('forum-discussions.show', $discussion) : route('forum-discussions.index') }}"
                class="shadow-theme-xs inline-flex h-11 items-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">Batal</a>
            <button type="submit" class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 items-center rounded-lg px-5 text-sm font-medium text-white">
                {{ $isEdit ? 'Simpan Perubahan' : 'Terbitkan Diskusi' }}
            </button>
        </div>
    </form>
@endsection
