@extends('layouts.app')

@php
    use App\Http\Requests\Cms\ManagementPeriodRequest;

    $isEdit = $period->exists;
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $borderClass = fn (string $field) => $errors->has($field) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    $okBorder = 'border-gray-300 dark:border-gray-700';
    $currentStatus = old('status', $period->status?->value);
    $iconButton = 'inline-flex h-8 w-8 items-center justify-center rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 disabled:opacity-40 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.03]';
    $groupHints = [
        'penasihat' => 'Ketua, wakil ketua, sekretaris, bendahara, dan jabatan inti lainnya.',
        'bidang' => 'Pengurus per bidang, mis. Bidang Edukasi dan Literasi, Koordinator Wilayah.',
    ];
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    @if ($errors->any())
        <div class="border-error-500 bg-error-50 dark:border-error-500/30 dark:bg-error-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">
                Data belum tersimpan. Periksa kembali isian yang ditandai.
                Foto yang baru dipilih perlu dipilih ulang.
            </p>
        </div>
    @endif

    <form method="POST" enctype="multipart/form-data" class="space-y-6"
        action="{{ $isEdit ? route('management-periods.update', $period) : route('management-periods.store') }}"
        x-data="managementForm(@js(['rows' => $rows, 'errors' => $errors->getMessages(), 'maxUploads' => (int) ini_get('max_file_uploads') ?: 20]))"
        @submit="submit($event)">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <!-- Periode -->
        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="border-b border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
                <h3 class="text-base font-medium text-gray-800 dark:text-white/90">Periode</h3>
            </div>
            <div class="grid grid-cols-1 gap-6 px-5 py-6 sm:px-6 md:grid-cols-3">
                @foreach (['start_year' => 'Tahun mulai', 'end_year' => 'Tahun selesai'] as $field => $label)
                    <div>
                        <label for="{{ $field }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            {{ $label }}<span class="text-error-500">*</span>
                        </label>
                        <input type="number" id="{{ $field }}" name="{{ $field }}" required
                            min="{{ ManagementPeriodRequest::MIN_YEAR }}" max="{{ ManagementPeriodRequest::MAX_YEAR }}"
                            value="{{ old($field, $period->{$field}) }}" placeholder="{{ $field === 'start_year' ? '2024' : '2029' }}"
                            class="{{ $inputClass }} {{ $borderClass($field) }}" />
                        @error($field)
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach

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
        </div>

        <!-- Pengurus per grup -->
        @foreach ($groups as $group)
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:border-gray-800">
                    <div>
                        <h3 class="text-base font-medium text-gray-800 dark:text-white/90">
                            {{ $group->label() }}
                            <span class="text-theme-sm font-normal text-gray-500" x-text="'(' + groups.{{ $group->value }}.length + ')'"></span>
                        </h3>
                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $groupHints[$group->value] }} Urutan baris = urutan tampil di website.</p>
                    </div>
                    <button type="button" @click="add(@js($group->value))"
                        class="shadow-theme-xs inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                        + Tambah {{ $group->label() }}
                    </button>
                </div>

                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    <template x-for="(row, index) in groups.{{ $group->value }}" :key="row.key">
                        <div class="flex flex-col gap-5 px-5 py-5 sm:px-6 lg:flex-row lg:items-start">
                            <input type="hidden" :name="`members[${row.key}][group]`" value="{{ $group->value }}">
                            <template x-if="row.id">
                                <input type="hidden" :name="`members[${row.key}][id]`" :value="row.id">
                            </template>

                            <!-- Foto -->
                            <div class="flex shrink-0 items-center gap-4 lg:w-56 lg:flex-col lg:items-start">
                                <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-full border border-gray-200 bg-gray-100 dark:border-gray-800 dark:bg-gray-800">
                                    <template x-if="photo(row)">
                                        <img :src="photo(row)" alt="" class="h-full w-full object-cover" />
                                    </template>
                                    <template x-if="!photo(row)">
                                        <svg class="h-8 w-8 text-gray-400" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.5"/><path d="M5 19.5C5 16.46 8.13 14 12 14C15.87 14 19 16.46 19 19.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                    </template>
                                </div>
                                <div class="min-w-0 space-y-1.5">
                                    <input type="file" :name="`members[${row.key}][photo]`" @change="pick(row, $event)"
                                        accept="{{ implode(',', ManagementPeriodRequest::PHOTO_MIMES) }}"
                                        class="block w-full text-theme-xs text-gray-500 file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-theme-xs file:text-gray-700 hover:file:bg-gray-200 dark:text-gray-400 dark:file:bg-white/[0.05] dark:file:text-gray-300" />
                                    <template x-if="row.photo_url && !row.preview">
                                        <label class="flex cursor-pointer items-center gap-2 text-theme-xs text-gray-600 dark:text-gray-400">
                                            <input type="checkbox" :name="`members[${row.key}][remove_photo]`" value="1" x-model="row.remove_photo" class="accent-error-500 h-4 w-4" />
                                            Hapus foto
                                        </label>
                                    </template>
                                    <p class="text-error-500 text-theme-xs" x-show="error(row, 'photo')" x-text="error(row, 'photo')"></p>
                                </div>
                            </div>

                            <!-- Nama & jabatan -->
                            <div class="grid flex-1 grid-cols-1 gap-4 md:grid-cols-3">
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Nama<span class="text-error-500">*</span></label>
                                    <input type="text" :name="`members[${row.key}][name]`" x-model="row.name" maxlength="150" required
                                        class="{{ $inputClass }}" :class="error(row, 'name') ? 'border-error-500' : @js($okBorder)" />
                                    <p class="text-error-500 mt-1.5 text-theme-xs" x-show="error(row, 'name')" x-text="error(row, 'name')"></p>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Jabatan (Indonesia)<span class="text-error-500">*</span></label>
                                    <input type="text" :name="`members[${row.key}][position][id]`" x-model="row.position.id" maxlength="150" required
                                        class="{{ $inputClass }}" :class="error(row, 'position.id') ? 'border-error-500' : @js($okBorder)" />
                                    <p class="text-error-500 mt-1.5 text-theme-xs" x-show="error(row, 'position.id')" x-text="error(row, 'position.id')"></p>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Jabatan (English)</label>
                                    <input type="text" :name="`members[${row.key}][position][en]`" x-model="row.position.en" maxlength="150"
                                        placeholder="Kosong = pakai teks Indonesia"
                                        class="{{ $inputClass }}" :class="error(row, 'position.en') ? 'border-error-500' : @js($okBorder)" />
                                    <p class="text-error-500 mt-1.5 text-theme-xs" x-show="error(row, 'position.en')" x-text="error(row, 'position.en')"></p>
                                </div>
                            </div>

                            <!-- Aksi baris -->
                            <div class="flex shrink-0 items-center gap-2 lg:pt-7">
                                <button type="button" title="Naik" @click="move(@js($group->value), index, -1)" :disabled="index === 0" class="{{ $iconButton }}">↑</button>
                                <button type="button" title="Turun" @click="move(@js($group->value), index, 1)" :disabled="index === groups.{{ $group->value }}.length - 1" class="{{ $iconButton }}">↓</button>
                                <button type="button" title="Hapus" @click="remove(@js($group->value), index)"
                                    class="border-error-300 text-error-600 hover:bg-error-50 dark:border-error-500/40 dark:text-error-500 dark:hover:bg-error-500/10 inline-flex h-8 w-8 items-center justify-center rounded-lg border">✕</button>
                            </div>
                        </div>
                    </template>

                    <p x-show="groups.{{ $group->value }}.length === 0" class="px-5 py-6 text-center text-theme-sm text-gray-500 sm:px-6 dark:text-gray-400">
                        Belum ada {{ strtolower($group->label()) }}.
                    </p>
                </div>
            </div>
        @endforeach

        <div class="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="text-theme-xs text-gray-500 dark:text-gray-400">
                <p>Foto: JPG, PNG, WEBP (maks {{ ManagementPeriodRequest::MAX_PHOTO_KB / 1024 }} MB), sebaiknya persegi.</p>
                @error('members')
                    <p class="text-error-500">{{ $message }}</p>
                @enderror
                @if ($isEdit)
                    <p class="mt-1">
                        Dibuat oleh {{ $period->creator?->name ?? '-' }} · {{ $period->created_at?->format('d M Y H:i') }} ·
                        Terakhir diubah oleh {{ $period->updater?->name ?? '-' }} · {{ $period->updated_at?->format('d M Y H:i') }}
                    </p>
                @endif
            </div>

            <div class="flex items-center gap-3">
                <a href="{{ route('management-periods.index') }}"
                    class="shadow-theme-xs inline-flex h-11 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                    Batal
                </a>
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 items-center justify-center rounded-lg px-5 text-sm font-medium text-white transition">
                    {{ $isEdit ? 'Simpan Perubahan' : 'Simpan' }}
                </button>
            </div>
        </div>
    </form>
@endsection
