@extends('layouts.app')

@php
    use App\Http\Requests\Cms\GuideRegulationRequest;

    $isEdit = $regulation->exists;
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $fileClass = 'focus:border-ring-brand-300 shadow-theme-xs h-11 w-full overflow-hidden rounded-lg border bg-transparent text-sm text-gray-500 transition-colors file:mr-5 file:border-collapse file:cursor-pointer file:rounded-l-lg file:border-0 file:border-r file:border-solid file:border-gray-200 file:bg-gray-50 file:py-3 file:pr-3 file:pl-3.5 file:text-sm file:text-gray-700 placeholder:text-gray-400 hover:file:bg-gray-100 focus:outline-hidden dark:bg-gray-900 dark:text-gray-400 dark:file:border-gray-800 dark:file:bg-white/[0.03] dark:file:text-gray-400';
    $borderClass = fn (string $field) => $errors->has($field) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $currentStatus = old('status', $regulation->status?->value);
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <form method="POST" enctype="multipart/form-data"
        action="{{ $isEdit ? route('guide-regulations.update', $regulation) : route('guide-regulations.store') }}"
        class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="max-w-3xl space-y-6 px-5 py-6 sm:px-6">
            <!-- Judul -->
            <div>
                <label for="title" class="{{ $labelClass }}">Judul<span class="text-error-500">*</span></label>
                <input type="text" id="title" name="title" maxlength="{{ GuideRegulationRequest::MAX_TITLE_LENGTH }}" required
                    value="{{ old('title', $regulation->title) }}"
                    placeholder="mis. PERPRES NO. 55 TAHUN 2019 Percepatan Program KBLBB"
                    class="{{ $inputClass }} {{ $borderClass('title') }}" />
                <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                    Satu bahasa: tulis nama resmi dokumen. Tampil sama di website versi Indonesia & English.
                </p>
                @error('title')
                    <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                @enderror
            </div>

            <!-- File PDF -->
            <div>
                <label for="file_pdf" class="{{ $labelClass }}">
                    File PDF
                    @unless ($isEdit)
                        <span class="text-error-500">*</span>
                    @endunless
                </label>
                @if ($isEdit)
                    <a href="{{ route('guide-regulations.pdf', $regulation) }}" target="_blank" rel="noopener noreferrer"
                        class="text-brand-500 mb-2 inline-flex items-center gap-1.5 text-theme-sm font-medium hover:underline">
                        Lihat PDF saat ini
                    </a>
                @endif
                <input type="file" id="file_pdf" name="file_pdf" @required(! $isEdit) accept="application/pdf,.pdf"
                    class="{{ $fileClass }} {{ $borderClass('file_pdf') }}" />
                <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                    PDF, maks {{ GuideRegulationRequest::MAX_PDF_KB / 1024 }} MB. Di website dibuka di tab baru saat pengunjung klik "Lihat".
                    @if ($isEdit)
                        Kosongkan jika tidak ingin mengganti file.
                    @endif
                </p>
                @error('file_pdf')
                    <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                @enderror
            </div>

            <!-- Status -->
            <div>
                <span class="{{ $labelClass }}">Status<span class="text-error-500">*</span></span>
                <div class="flex flex-wrap items-center gap-6">
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

            @if ($isEdit)
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                    Dibuat oleh {{ $regulation->creator?->name ?? '-' }} · {{ $regulation->created_at?->format('d M Y H:i') }}<br>
                    Terakhir diubah oleh {{ $regulation->updater?->name ?? '-' }} · {{ $regulation->updated_at?->format('d M Y H:i') }}
                </p>
            @endif
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
            <a href="{{ route('guide-regulations.index') }}"
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
