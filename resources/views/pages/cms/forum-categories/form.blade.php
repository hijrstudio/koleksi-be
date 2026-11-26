@extends('layouts.app')

@php
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90';
    $border = fn (string $f) => $errors->has($f) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <form method="POST" action="{{ $category->exists ? route('forum-categories.update', $category) : route('forum-categories.store') }}"
        class="max-w-2xl rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @csrf
        @if ($category->exists)
            @method('PUT')
        @endif

        <div class="space-y-6 px-5 py-6 sm:px-6">
            <div>
                <label for="name" class="{{ $labelClass }}">Nama Kategori<span class="text-error-500">*</span></label>
                <input type="text" id="name" name="name" required maxlength="60" value="{{ old('name', $category->name) }}"
                    class="{{ $inputClass }} {{ $border('name') }}" />
                @error('name')
                    <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                @enderror
            </div>
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div>
                    <label for="sort_order" class="{{ $labelClass }}">Urutan<span class="text-error-500">*</span></label>
                    <input type="number" id="sort_order" name="sort_order" required min="0" max="999"
                        value="{{ old('sort_order', $category->sort_order) }}" class="{{ $inputClass }} {{ $border('sort_order') }}" />
                    @error('sort_order')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <span class="{{ $labelClass }}">Status</span>
                    <div class="flex h-11 items-center gap-6">
                        @foreach ([1 => 'Aktif', 0 => 'Nonaktif'] as $value => $label)
                            <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                                <input type="radio" name="is_active" value="{{ $value }}" class="accent-brand-500 h-4 w-4"
                                    @checked((int) old('is_active', (int) $category->is_active) === $value) />
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
            <a href="{{ route('forum-categories.index') }}"
                class="shadow-theme-xs inline-flex h-11 items-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">Batal</a>
            <button type="submit" class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 items-center rounded-lg px-5 text-sm font-medium text-white">Simpan</button>
        </div>
    </form>
@endsection
