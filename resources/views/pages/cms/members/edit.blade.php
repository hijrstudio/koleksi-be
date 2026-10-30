@extends('layouts.app')

@php
    use App\Http\Controllers\Cms\MemberController;
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <div class="mb-4">
        <a href="{{ route('members.show', $member) }}"
            class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">
            ← Kembali ke detail member
        </a>
    </div>

    <form method="POST" action="{{ route('members.update', $member) }}"
        class="max-w-2xl rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @csrf
        @method('PUT')

        <div class="border-b border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $member->nama_lengkap }}</h3>
            <p class="text-theme-sm text-gray-500 dark:text-gray-400">No. lambung saat ini: {{ $member->noLambungLabel() }}</p>
        </div>

        <div class="space-y-2 px-5 py-6 sm:px-6">
            <label for="no_lambung" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                No. Lambung<span class="text-error-500">*</span>
            </label>
            <input type="number" id="no_lambung" name="no_lambung" required min="1" max="{{ MemberController::MAX_NO_LAMBUNG }}"
                value="{{ old('no_lambung', $member->no_lambung) }}"
                class="shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 h-11 w-48 rounded-lg border bg-transparent px-4 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 {{ $errors->has('no_lambung') ? 'border-error-500' : 'border-gray-300 dark:border-gray-700' }}" />
            @error('no_lambung')
                <p class="text-error-500 text-theme-xs">{{ $message }}</p>
            @enderror
            <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                Nomor harus belum dipakai anggota lain (termasuk anggota yang sudah dihapus). Nomor kosong berikutnya untuk
                anggota baru: #{{ str_pad((string) $nextNoLambung, 3, '0', STR_PAD_LEFT) }}. Nomor invoice iuran member ini
                ikut disesuaikan.
            </p>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
            <a href="{{ route('members.show', $member) }}"
                class="shadow-theme-xs inline-flex h-11 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                Batal
            </a>
            <button type="submit"
                class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 items-center justify-center rounded-lg px-5 text-sm font-medium text-white transition">
                Simpan
            </button>
        </div>
    </form>
@endsection
