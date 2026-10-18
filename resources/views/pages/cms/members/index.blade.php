@extends('layouts.app')

@php
    $selectClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 select-chevron h-11 rounded-lg border border-gray-300 bg-transparent pl-4 py-2.5 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';

    $cards = [
        ['label' => 'Total Member', 'value' => $summary['total'], 'filter' => [], 'color' => 'text-gray-800 dark:text-white/90'],
        ['label' => 'Aktif', 'value' => $summary['active'], 'filter' => ['status' => 'active'], 'color' => 'text-success-600 dark:text-success-500'],
        ['label' => 'Tidak Aktif', 'value' => $summary['inactive'], 'filter' => ['status' => 'inactive'], 'color' => 'text-error-600 dark:text-error-500'],
        ['label' => 'Daftar Ulang ≤ 30 Hari', 'value' => $summary['due_soon'], 'filter' => ['renewal' => 'due_soon'], 'color' => 'text-warning-600 dark:text-orange-400'],
        ['label' => 'Masa Tenggang', 'value' => $summary['grace'], 'filter' => ['renewal' => 'grace'], 'color' => 'text-error-600 dark:text-error-500'],
        ['label' => 'Tanpa Email & No. HP', 'value' => $summary['no_contact'], 'filter' => ['contact' => 'none'], 'color' => 'text-gray-600 dark:text-gray-300'],
    ];
@endphp

@section('content')
    <x-common.page-breadcrumb pageTitle="Daftar Member" />

    <!-- Summary cards (klik = filter tabel) -->
    <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
        @foreach ($cards as $card)
            <button type="button" data-member-filter='@json((object) $card['filter'])'
                class="hover:border-brand-300 dark:hover:border-brand-800 rounded-2xl border border-gray-200 bg-white p-5 text-left transition dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                <p class="mt-2 text-2xl font-semibold {{ $card['color'] }}">{{ number_format($card['value']) }}</p>
            </button>
        @endforeach
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]" data-table-wrapper>
        <!-- Filter tambahan -->
        <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center dark:border-gray-800">
            <select id="filterStatus" class="{{ $selectClass }}" aria-label="Filter status">
                <option value="">Semua status</option>
                <option value="active">Aktif</option>
                <option value="inactive">Tidak Aktif</option>
            </select>
            <select id="filterRenewal" class="{{ $selectClass }}" aria-label="Filter daftar ulang">
                <option value="">Semua jatuh tempo</option>
                <option value="due_soon">Daftar ulang ≤ 30 hari</option>
                <option value="grace">Masa tenggang</option>
            </select>
            <select id="filterContact" class="{{ $selectClass }}" aria-label="Filter kontak">
                <option value="">Semua kontak</option>
                <option value="no_email">Tanpa email</option>
                <option value="no_phone">Tanpa No. HP</option>
                <option value="none">Tanpa email & No. HP</option>
            </select>
            <button type="button" id="resetMemberFilters" class="text-sm text-gray-500 hover:text-gray-700 sm:ml-1 dark:text-gray-400">
                Reset filter
            </button>
        </div>

        <div data-table-error class="hidden border-b border-gray-100 bg-error-50 px-5 py-3 text-theme-sm text-error-600 dark:border-gray-800 dark:bg-error-500/15 dark:text-error-500">
            Gagal memuat data. Coba muat ulang halaman.
        </div>

        <table id="membersTable" class="min-w-full"
            data-url="{{ route('members.data') }}"
            data-page-length="25"
            data-length-menu='@json($pageLengths)'>
            <thead>
                <tr>
                    <th>No. Lambung</th>
                    <th>Nama</th>
                    <th>Kontak</th>
                    <th>Tgl Bergabung</th>
                    <th>Status</th>
                    <th>Daftar Ulang</th>
                    <th></th>
                </tr>
            </thead>
        </table>
    </div>
@endsection
