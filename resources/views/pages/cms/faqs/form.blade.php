@extends('layouts.app')

@php
    use App\Http\Requests\Cms\FaqRequest;

    $isEdit = $faq->exists;
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $borderClass = fn (string $field) => $errors->has($field) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    $currentStatus = old('status', $faq->status?->value);
    $languages = ['id' => 'Indonesia', 'en' => 'English'];
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <form method="POST" action="{{ $isEdit ? route('faqs.update', $faq) : route('faqs.store') }}"
        class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="space-y-6 px-5 py-6 sm:px-6">
            <!-- Pertanyaan ID / EN -->
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                @foreach ($languages as $lang => $langLabel)
                    <div>
                        <label for="question_{{ $lang }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Pertanyaan ({{ $langLabel }})@if ($lang === 'id')<span class="text-error-500">*</span>@endif
                        </label>
                        <input type="text" id="question_{{ $lang }}" name="question[{{ $lang }}]"
                            maxlength="{{ FaqRequest::MAX_QUESTION_LENGTH }}" @required($lang === 'id')
                            value="{{ old("question.$lang", $faq->question[$lang] ?? '') }}"
                            placeholder="{{ $lang === 'id' ? 'Apa kepanjangan dari KOLEKSI?' : 'What does KOLEKSI stand for?' }}"
                            class="{{ $inputClass }} {{ $borderClass("question.$lang") }}" />
                        @error("question.$lang")
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
            </div>

            <!-- Jawaban ID / EN -->
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                @foreach ($languages as $lang => $langLabel)
                    <div>
                        <label for="answer_{{ $lang }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Jawaban ({{ $langLabel }})@if ($lang === 'id')<span class="text-error-500">*</span>@endif
                        </label>
                        <x-form.rich-editor id="answer_{{ $lang }}" name="answer[{{ $lang }}]"
                            :value="old('answer.'.$lang, $faq->answer[$lang] ?? '')" :invalid="$errors->has('answer.'.$lang)" />
                        @error("answer.$lang")
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
            </div>
            <p class="-mt-3 text-theme-xs text-gray-500 dark:text-gray-400">Jika versi English kosong, website memakai teks Indonesia.</p>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <!-- Urutan -->
                <div>
                    <label for="sort_order" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Urutan</label>
                    <input type="number" id="sort_order" name="sort_order" min="0" max="{{ FaqRequest::MAX_SORT_ORDER }}"
                        value="{{ old('sort_order', $faq->sort_order) }}"
                        class="{{ $inputClass }} {{ $borderClass('sort_order') }}" />
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">Angka kecil tampil lebih dulu. Kosongkan untuk menaruh di urutan terakhir.</p>
                    @error('sort_order')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Status -->
                <div>
                    <span class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        Status<span class="text-error-500">*</span>
                    </span>
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

            @if ($isEdit)
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                    Dibuat oleh {{ $faq->creator?->name ?? '-' }} · {{ $faq->created_at?->format('d M Y H:i') }}<br>
                    Terakhir diubah oleh {{ $faq->updater?->name ?? '-' }} · {{ $faq->updated_at?->format('d M Y H:i') }}
                </p>
            @endif
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
            <a href="{{ route('faqs.index') }}"
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
