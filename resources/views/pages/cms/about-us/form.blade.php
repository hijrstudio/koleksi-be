@extends('layouts.app')

@php
    use App\Http\Requests\Cms\AboutUsRequest;

    $labels = AboutUsRequest::LABELS;
    $docAccept = '.pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    $sections = [
        ['title' => 'Ringkasan', 'fields' => [['summary', 'rich', true]], 'files' => ['image']],
        ['title' => 'Profil Organisasi', 'fields' => [['profil_organisasi', 'rich', false]], 'files' => []],
        ['title' => 'Visi & Misi', 'fields' => [['visi', 'plain', false], ['misi', 'rich', false]], 'files' => []],
        ['title' => 'Keanggotaan', 'fields' => [['syarat_anggota', 'rich', false], ['daftar_ulang_anggota', 'rich', false]], 'files' => []],
        ['title' => 'Dokumen', 'fields' => [], 'files' => ['aturan_ad_art', 'sponsor_doc', 'mitra_doc']],
    ];

    $fileFields = [
        'image' => [
            'accept' => implode(',', AboutUsRequest::IMAGE_MIMES),
            'hint' => 'JPG, PNG, WEBP (maks '.(AboutUsRequest::MAX_IMAGE_KB / 1024).' MB).',
        ],
        'aturan_ad_art' => [
            'accept' => '.pdf,application/pdf',
            'hint' => 'PDF (maks '.(AboutUsRequest::MAX_DOC_KB / 1024).' MB).',
        ],
        'sponsor_doc' => [
            'accept' => $docAccept,
            'hint' => 'PDF, DOC, DOCX (maks '.(AboutUsRequest::MAX_DOC_KB / 1024).' MB).',
        ],
        'mitra_doc' => [
            'accept' => $docAccept,
            'hint' => 'PDF, DOC, DOCX (maks '.(AboutUsRequest::MAX_DOC_KB / 1024).' MB).',
        ],
    ];
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    @if (session('success'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
            class="border-success-500 bg-success-50 dark:border-success-500/30 dark:bg-success-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ session('success') }}</p>
        </div>
    @endif

    @if ($errors->any())
        <div class="border-error-500 bg-error-50 dark:border-error-500/30 dark:bg-error-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">Data belum tersimpan. Periksa kembali isian yang ditandai.</p>
        </div>
    @endif

    @unless ($aboutUs->exists)
        <div class="border-warning-500 bg-warning-50 dark:border-warning-500/30 dark:bg-warning-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">Konten Tentang Kami belum pernah diisi. Lengkapi form di bawah lalu simpan.</p>
        </div>
    @endunless

    <form method="POST" enctype="multipart/form-data" action="{{ route('about-us.update') }}" class="space-y-6">
        @csrf
        @method('PUT')

        <fieldset @disabled(! $canUpdate) class="space-y-6">
            @foreach ($sections as $section)
                <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="border-b border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
                        <h3 class="text-base font-medium text-gray-800 dark:text-white/90">{{ $section['title'] }}</h3>
                    </div>

                    <div class="space-y-6 px-5 py-6 sm:px-6">
                        @foreach ($section['fields'] as [$field, $type, $required])
                            @include('pages.cms.about-us.partials.translatable-field', [
                                'field' => $field,
                                'label' => $labels[$field],
                                'type' => $type,
                                'required' => $required,
                            ])
                        @endforeach

                        @if ($section['files'])
                            <div class="grid grid-cols-1 gap-6 {{ count($section['files']) > 1 ? 'lg:grid-cols-3' : '' }}">
                                @foreach ($section['files'] as $field)
                                    @include('pages.cms.about-us.partials.file-field', [
                                        'field' => $field,
                                        'label' => $labels[$field],
                                        'isImage' => $field === 'image',
                                        ...$fileFields[$field],
                                    ])
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </fieldset>

        <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                @if ($aboutUs->exists)
                    Dibuat oleh {{ $aboutUs->creator?->name ?? '-' }} · {{ $aboutUs->created_at?->format('d M Y H:i') }}<br>
                    Terakhir diubah oleh {{ $aboutUs->updater?->name ?? '-' }} · {{ $aboutUs->updated_at?->format('d M Y H:i') }}
                @else
                    Belum pernah disimpan.
                @endif
            </p>

            @if ($canUpdate)
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 items-center justify-center rounded-lg px-5 text-sm font-medium text-white transition">
                    Simpan
                </button>
            @endif
        </div>
    </form>
@endsection
