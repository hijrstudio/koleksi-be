@extends('layouts.app')

@php
    use App\Http\Controllers\Cms\TransactionController as Tx;

    $rupiah = fn ($n) => 'Rp '.number_format((int) $n, 0, ',', '.');
    $number = fn ($n) => number_format((int) $n, 0, ',', '.');
    $sourceBadge = [
        'registration' => 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-500',
        'dues' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
        'admin' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-orange-400',
    ];
    $card = 'rounded-2xl border border-gray-200 bg-white p-5 md:p-6 dark:border-gray-800 dark:bg-white/[0.03]';
    $totalMembers = $members ? $members['active'] + $members['inactive'] : 0;
@endphp

@section('content')
    <div class="grid grid-cols-12 gap-4 md:gap-6">
        @if ($members)
            {{-- Anggota resmi: aktif (iuran berlaku) / tidak aktif --}}
            <div class="col-span-12 grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 xl:col-span-8 xl:grid-cols-3">
                @foreach ([
                    ['Anggota aktif', $members['active'], 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500', route('members.index', ['status' => 'active'])],
                    ['Anggota tidak aktif', $members['inactive'], 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500', route('members.index', ['status' => 'inactive'])],
                    ['Pendaftar menunggu bayar', $members['awaiting'], 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-orange-400', null],
                ] as [$label, $value, $tone, $href])
                    <div class="{{ $card }}">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</span>
                            @if ($totalMembers > 0 && $label !== 'Pendaftar menunggu bayar')
                                <span class="rounded-full px-2.5 py-0.5 text-theme-xs font-medium {{ $tone }}">{{ round($value / $totalMembers * 100) }}%</span>
                            @endif
                        </div>
                        <h4 class="mt-3 text-title-sm font-bold text-gray-800 dark:text-white/90">{{ $number($value) }}</h4>
                        @if ($href)
                            <a href="{{ $href }}" class="mt-2 inline-block text-theme-xs font-medium text-brand-500 hover:text-brand-600">Lihat daftar →</a>
                        @else
                            <p class="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">Belum membayar biaya pendaftaran</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($month)
            <div class="col-span-12 {{ $members ? 'xl:col-span-4' : '' }} {{ $card }}">
                <span class="text-sm text-gray-500 dark:text-gray-400">Pemasukan {{ $monthLabel }}</span>
                <h4 class="mt-3 text-title-sm font-bold text-gray-800 dark:text-white/90">{{ $rupiah($month['gross']) }}</h4>
                <dl class="mt-3 space-y-1 text-theme-xs text-gray-500 dark:text-gray-400">
                    @foreach ($month['by_source'] as $source => $row)
                        <div class="flex justify-between gap-3">
                            <dt>{{ Tx::sourceLabel($source) }} ({{ $number($row['count']) }})</dt>
                            <dd class="font-medium text-gray-700 dark:text-gray-300">{{ $rupiah($row['gross']) }}</dd>
                        </div>
                    @endforeach
                    @if ($month['mdr'] > 0)
                        <div class="flex justify-between gap-3 border-t border-gray-100 pt-1 dark:border-gray-800">
                            <dt>Potongan MDR QRIS</dt>
                            <dd>{{ $rupiah($month['mdr']) }}</dd>
                        </div>
                    @endif
                </dl>
            </div>
        @endif

        @if ($details)
            <div class="col-span-12 rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Transaksi terbaru</h3>
                    <a href="{{ route('transactions.index') }}" class="text-theme-sm font-medium text-brand-500 hover:text-brand-600">Lihat semua →</a>
                </div>
                <div class="max-w-full overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                @foreach (['Tanggal (WIB)', 'Member', 'Sumber', 'Referensi', 'Nominal'] as $heading)
                                    <th class="whitespace-nowrap px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($recent as $row)
                                @php
                                    $member = $details['members'][$row->member_id] ?? null;
                                    $kind = $row->source === 'qris' ? ($row->purpose ?: 'dues') : $row->source;
                                @endphp
                                <tr>
                                    <td class="whitespace-nowrap px-5 py-3 text-theme-sm text-gray-700 dark:text-gray-300">{{ Tx::wib($row->tx_at) }}</td>
                                    <td class="px-5 py-3 text-theme-sm">
                                        @if ($member)
                                            <a href="{{ route('members.show', $member) }}" class="font-medium text-gray-800 hover:text-brand-500 dark:text-white/90">{{ $member->nama_lengkap }}</a>
                                            <span class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $member->noLambungLabel() }}</span>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        <span class="whitespace-nowrap rounded-full px-2.5 py-0.5 text-theme-xs font-medium {{ $sourceBadge[$kind] ?? '' }}">{{ Tx::rowLabel($row) }}</span>
                                    </td>
                                    <td class="px-5 py-3 font-mono text-theme-xs text-gray-600 dark:text-gray-400">{{ $row->ref }}</td>
                                    <td class="whitespace-nowrap px-5 py-3 text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $rupiah($row->amount) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-5 py-8 text-center text-theme-sm text-gray-500 dark:text-gray-400">Belum ada transaksi lunas.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if (! $members && ! $month)
            <div class="col-span-12 {{ $card }}">
                <p class="text-theme-sm text-gray-500 dark:text-gray-400">Selamat datang di CMS KOLEKSI. Gunakan menu di samping untuk mengelola konten.</p>
            </div>
        @endif
    </div>
@endsection
