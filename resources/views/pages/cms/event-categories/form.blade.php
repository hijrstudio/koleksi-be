@extends('layouts.app')

@php
    use App\Http\Requests\Cms\EventCategoryRequest;

    $isEdit = $category->exists;
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $borderClass = fn (string $field) => $errors->has($field) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <form method="POST" action="{{ $isEdit ? route('event-categories.update', $category) : route('event-categories.store') }}"
        class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="space-y-6 px-5 py-6 sm:px-6">
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                @foreach (['id' => 'Indonesia', 'en' => 'English'] as $lang => $langLabel)
                    <div>
                        <label for="name_{{ $lang }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Nama kategori ({{ $langLabel }})@if ($lang === 'id')<span class="text-error-500">*</span>@endif
                        </label>
                        <input type="text" id="name_{{ $lang }}" name="name[{{ $lang }}]"
                            maxlength="{{ EventCategoryRequest::MAX_NAME_LENGTH }}" @required($lang === 'id')
                            value="{{ old("name.$lang", $category->name[$lang] ?? '') }}"
                            placeholder="{{ $lang === 'id' ? 'Touring' : 'Touring (kosong = pakai teks Indonesia)' }}"
                            class="{{ $inputClass }} {{ $borderClass("name.$lang") }}" />
                        @error("name.$lang")
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
            </div>

            <div>
                @include('pages.cms.partials.status-field', ['model' => $category])
                <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">Event di kategori draft tidak tampil di website.</p>
            </div>

            @if ($isEdit)
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                    Dibuat oleh {{ $category->creator?->name ?? '-' }} · {{ $category->created_at?->format('d M Y H:i') }}<br>
                    Terakhir diubah oleh {{ $category->updater?->name ?? '-' }} · {{ $category->updated_at?->format('d M Y H:i') }}
                </p>
            @endif
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
            <a href="{{ route('event-categories.index') }}"
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
