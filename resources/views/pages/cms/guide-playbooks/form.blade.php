@extends('layouts.app')

@php
    use App\Http\Requests\Cms\GuidePlaybookRequest;

    $isEdit = $playbook->exists;
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $fileClass = 'focus:border-ring-brand-300 shadow-theme-xs h-11 w-full overflow-hidden rounded-lg border bg-transparent text-sm text-gray-500 transition-colors file:mr-5 file:border-collapse file:cursor-pointer file:rounded-l-lg file:border-0 file:border-r file:border-solid file:border-gray-200 file:bg-gray-50 file:py-3 file:pr-3 file:pl-3.5 file:text-sm file:text-gray-700 placeholder:text-gray-400 hover:file:bg-gray-100 focus:outline-hidden dark:bg-gray-900 dark:text-gray-400 dark:file:border-gray-800 dark:file:bg-white/[0.03] dark:file:text-gray-400';
    $borderClass = fn (string $field) => $errors->has($field) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $currentStatus = old('status', $playbook->status?->value);
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <form method="POST" enctype="multipart/form-data"
        action="{{ $isEdit ? route('guide-playbooks.update', $playbook) : route('guide-playbooks.store') }}"
        class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="grid grid-cols-1 gap-6 px-5 py-6 sm:px-6 lg:grid-cols-5">
            <div class="space-y-6 lg:col-span-3">
                <!-- Judul ID / EN -->
                @foreach (['id' => 'Indonesia', 'en' => 'English'] as $lang => $langLabel)
                    <div>
                        <label for="title_{{ $lang }}" class="{{ $labelClass }}">
                            Judul ({{ $langLabel }})@if ($lang === 'id')<span class="text-error-500">*</span>@endif
                        </label>
                        <input type="text" id="title_{{ $lang }}" name="title[{{ $lang }}]"
                            maxlength="{{ GuidePlaybookRequest::MAX_TITLE_LENGTH }}" @required($lang === 'id')
                            value="{{ old("title.$lang", $playbook->title[$lang] ?? '') }}"
                            placeholder="{{ $lang === 'en' ? 'Kosong = pakai teks Indonesia' : '' }}"
                            class="{{ $inputClass }} {{ $borderClass("title.$lang") }}" />
                        @error("title.$lang")
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach

                <!-- File PDF -->
                <div>
                    <label for="file_pdf" class="{{ $labelClass }}">
                        File PDF
                        @unless ($isEdit)
                            <span class="text-error-500">*</span>
                        @endunless
                    </label>
                    @if ($isEdit)
                        <a href="{{ route('guide-playbooks.pdf', $playbook) }}" target="_blank" rel="noopener noreferrer"
                            class="text-brand-500 mb-2 inline-flex items-center gap-1.5 text-theme-sm font-medium hover:underline">
                            Lihat PDF saat ini
                        </a>
                    @endif
                    <input type="file" id="file_pdf" name="file_pdf" @required(! $isEdit) accept="application/pdf,.pdf"
                        class="{{ $fileClass }} {{ $borderClass('file_pdf') }}" />
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                        PDF, maks {{ GuidePlaybookRequest::MAX_PDF_KB / 1024 }} MB. Di website, semua pengunjung bisa membaca;
                        tombol unduh hanya untuk member yang sudah login.
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
                        Dibuat oleh {{ $playbook->creator?->name ?? '-' }} · {{ $playbook->created_at?->format('d M Y H:i') }}<br>
                        Terakhir diubah oleh {{ $playbook->updater?->name ?? '-' }} · {{ $playbook->updated_at?->format('d M Y H:i') }}
                    </p>
                @endif
            </div>

            <!-- Cover -->
            <div class="lg:col-span-2" x-data="{
                previewUrl: @js($playbook->cover_url),
                pick(event) {
                    const file = event.target.files[0];
                    if (!file) return;
                    if (this.previewUrl?.startsWith('blob:')) URL.revokeObjectURL(this.previewUrl);
                    this.previewUrl = URL.createObjectURL(file);
                }
            }">
                <label for="cover_image" class="{{ $labelClass }}">
                    Cover
                    @unless ($isEdit)
                        <span class="text-error-500">*</span>
                    @endunless
                </label>
                <div x-show="previewUrl" class="mb-3 overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900">
                    <img :src="previewUrl" alt="Preview cover" class="aspect-270/320 w-full object-cover" />
                </div>
                <input type="file" id="cover_image" name="cover_image" @change="pick" @required(! $isEdit)
                    accept="{{ implode(',', GuidePlaybookRequest::COVER_MIMES) }}"
                    class="{{ $fileClass }} {{ $borderClass('cover_image') }}" />
                <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                    JPG, PNG, WEBP (maks {{ GuidePlaybookRequest::MAX_COVER_KB / 1024 }} MB). Rasio kartu di website 270:320 (portrait).
                    @if ($isEdit)
                        Kosongkan jika tidak ingin mengganti cover.
                    @endif
                </p>
                @error('cover_image')
                    <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
            <a href="{{ route('guide-playbooks.index') }}"
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
