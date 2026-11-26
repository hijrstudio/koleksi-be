@extends('layouts.app')

@php
    use App\Http\Controllers\Cms\TransactionController as Tx;

    $control = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
    $rupiah = fn ($n) => $n === null ? '—' : 'Rp '.number_format((int) $n, 0, ',', '.');
    $sourceBadge = [
        'registration' => 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-500',
        'dues' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
        'admin' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-orange-400',
    ];
    $statusLabel = ['success' => 'Lunas', 'pending' => 'Menunggu', 'expired' => 'Kedaluwarsa', 'failed' => 'Gagal', 'cancelled' => 'Dibatalkan', 'refunded' => 'Refund'];
    $methodLabel = ['cash' => 'Tunai', 'transfer' => 'Transfer'];
    $canVerify = auth()->user()->can('transactions.verify');
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    @include('pages.cms.forum-discussions.partials.flash')

    {{-- Ringkasan (hanya transaksi lunas sesuai filter) --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['Total lunas', $rupiah($summary['gross']), number_format($summary['count']).' transaksi · QRIS neto '.$rupiah($summary['net']).' · MDR '.$rupiah($summary['mdr'])],
            ['QRIS Pendaftaran', $rupiah($summary['by_source']['registration']['gross']), number_format($summary['by_source']['registration']['count']).' transaksi (anggota baru)'],
            ['QRIS Iuran', $rupiah($summary['by_source']['dues']['gross']), number_format($summary['by_source']['dues']['count']).' transaksi (iuran tahunan)'],
            ['Dicatat admin', $rupiah($summary['by_source']['admin']['gross']), number_format($summary['by_source']['admin']['count']).' transaksi (tunai / transfer)'],
        ] as [$label, $value, $hint])
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <p class="mt-2 text-xl font-semibold text-gray-800 dark:text-white/90">{{ $value }}</p>
                <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
            </div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <form method="GET" action="{{ route('transactions.index') }}" class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 lg:flex-row lg:flex-wrap lg:items-end dark:border-gray-800">
            <label class="flex flex-col gap-1 text-theme-xs text-gray-500 dark:text-gray-400">Dari (WIB)
                <input type="date" name="from" value="{{ $filters['from'] }}" class="{{ $control }}" />
            </label>
            <label class="flex flex-col gap-1 text-theme-xs text-gray-500 dark:text-gray-400">Sampai
                <input type="date" name="to" value="{{ $filters['to'] }}" class="{{ $control }}" />
            </label>
            <label class="flex flex-col gap-1 text-theme-xs text-gray-500 dark:text-gray-400">Sumber
                <select name="source" class="{{ $control }} select-chevron">
                    <option value="">Semua sumber</option>
                    @foreach (App\Services\TransactionReport::SOURCES as $source)
                        <option value="{{ $source }}" @selected($filters['source'] === $source)>{{ Tx::sourceLabel($source) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1 text-theme-xs text-gray-500 dark:text-gray-400">Status QRIS
                <select name="status" class="{{ $control }} select-chevron">
                    <option value="success" @selected($filters['status'] === 'success')>Lunas</option>
                    <option value="all" @selected($filters['status'] === 'all')>Semua status</option>
                    @foreach (['pending', 'expired', 'failed', 'cancelled', 'refunded'] as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabel[$status] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-1 flex-col gap-1 text-theme-xs text-gray-500 dark:text-gray-400">Cari
                <input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Nama, No. lambung, No. referensi / invoice" class="{{ $control }} lg:min-w-64" />
            </label>
            <div class="flex items-center gap-3">
                <button type="submit" class="shadow-theme-xs inline-flex h-11 items-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">Filter</button>
                @if (request()->hasAny(['from', 'to', 'source', 'status', 'search']))
                    <a href="{{ route('transactions.index') }}" class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">Reset</a>
                @endif
                @can('transactions.export')
                    <a href="{{ route('transactions.export', request()->query()) }}"
                        class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 items-center rounded-lg px-4 text-sm font-medium text-white">Ekspor CSV</a>
                @endcan
            </div>
        </form>

        <div class="max-w-full overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        @foreach (['Tanggal (WIB)', 'Member', 'Sumber', 'Referensi', 'Tagihan', 'Nominal', 'Status', 'Cek YODU'] as $heading)
                            <th class="whitespace-nowrap px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($rows as $row)
                        @php
                            $member = $details['members'][$row->member_id] ?? null;
                            $payment = $row->source === 'qris' ? ($details['payments'][$row->row_id] ?? null) : null;
                            $due = $row->source !== 'qris' ? ($details['dues'][$row->row_id] ?? null) : null;
                        @endphp
                        <tr class="align-top">
                            <td class="whitespace-nowrap px-5 py-4 text-theme-sm text-gray-700 dark:text-gray-300">{{ Tx::wib($row->tx_at) }}</td>
                            <td class="px-5 py-4 text-theme-sm">
                                @if ($member)
                                    <a href="{{ route('members.show', $member) }}" class="font-medium text-gray-800 hover:text-brand-500 dark:text-white/90">{{ $member->nama_lengkap }}</a>
                                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $member->noLambungLabel() }}@if ($member->trashed()) · dihapus @endif</p>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                @php($kind = $row->source === 'qris' ? ($row->purpose ?: 'dues') : $row->source)
                                <span class="whitespace-nowrap rounded-full px-2.5 py-0.5 text-theme-xs font-medium {{ $sourceBadge[$kind] }}">{{ Tx::rowLabel($row) }}</span>
                                <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                                    @if ($row->source === 'qris')
                                        {{ $row->method ?: 'QRIS' }}@if ($payment && $payment->gateway !== 'yodu') · {{ $payment->gateway }} @endif
                                    @else
                                        {{ $methodLabel[$row->method] ?? $row->method }}@if ($row->recorded_by) · {{ $details['admins'][$row->recorded_by] ?? 'Admin' }} @endif
                                    @endif
                                </p>
                            </td>
                            <td class="px-5 py-4 font-mono text-theme-xs text-gray-700 dark:text-gray-300">
                                {{ $row->ref }}
                                @if ($row->gateway_ref)
                                    <p class="text-gray-500 dark:text-gray-400" title="Reference No. YODU">YODU: {{ $row->gateway_ref }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-theme-xs text-gray-500 dark:text-gray-400">
                                @if ($payment)
                                    {{ $payment->dues->pluck('period_label')->implode(', ') ?: '—' }}
                                @else
                                    {{ $due?->period_label ?? '—' }}
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-5 py-4 text-theme-sm text-gray-800 dark:text-white/90">
                                {{ $rupiah($row->amount) }}
                                @if ($row->net_amount !== null)
                                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">neto {{ $rupiah($row->net_amount) }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <span class="whitespace-nowrap rounded-full px-2.5 py-0.5 text-theme-xs font-medium {{ $row->status === 'success' ? 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-500' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' }}">
                                    {{ $statusLabel[$row->status] ?? $row->status }}
                                </span>
                                @if ($payment?->failure_reason)
                                    <p class="text-error-600 dark:text-error-500 mt-1 max-w-56 text-theme-xs">{{ $payment->failure_reason }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-theme-xs">
                                @if ($payment)
                                    <div x-data="reconcileRow(@js([
                                        'status' => $payment->reconcile_status,
                                        'note' => $payment->reconcile_note,
                                        'checkedAt' => $payment->reconciled_at?->timezone('Asia/Jakarta')->format('d M Y H:i'),
                                        'canSync' => false,
                                        'checkUrl' => route('transactions.reconcile', $payment),
                                        'syncUrl' => route('transactions.sync', $payment),
                                    ]))" class="min-w-48">
                                        <template x-if="status">
                                            <div>
                                                <span class="rounded-full px-2 py-0.5 font-medium"
                                                    :class="{
                                                        'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-500': status === 'match',
                                                        'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500': status === 'mismatch',
                                                        'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-orange-400': status === 'error',
                                                    }"
                                                    x-text="{ match: 'Cocok', mismatch: 'Tidak cocok', error: 'Gagal cek' }[status]"></span>
                                                <p class="mt-1 text-gray-500 dark:text-gray-400" x-text="note"></p>
                                                <p class="text-gray-400" x-show="checkedAt" x-text="'Dicek ' + checkedAt"></p>
                                            </div>
                                        </template>
                                        @if ($canVerify)
                                            <div class="mt-2 flex flex-wrap gap-2">
                                                <button type="button" @click="check()" :disabled="busy"
                                                    class="rounded-lg border border-gray-300 px-2.5 py-1 font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:text-gray-400">
                                                    <span x-text="busy ? 'Mengecek…' : 'Cek ke YODU'"></span>
                                                </button>
                                                <button type="button" x-show="canSync" x-cloak @click="sync()" :disabled="busy"
                                                    class="bg-brand-500 hover:bg-brand-600 rounded-lg px-2.5 py-1 font-medium text-white disabled:opacity-50">Sinkronkan</button>
                                            </div>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-gray-400" title="Dicatat di luar YODU">Tidak perlu</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">Tidak ada transaksi untuk filter ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">{{ $rows->links() }}</div>
        @endif
    </div>

    <script>
        // Cek ke YODU / Sinkronkan per baris (POST + token CSRF; hasil ditampilkan tanpa memuat ulang halaman)
        function reconcileRow(initial) {
            return {
                ...initial,
                busy: false,
                async post(url) {
                    this.busy = true;
                    try {
                        const res = await fetch(url, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                        });
                        const data = await res.json().catch(() => ({}));
                        if (res.status === 429) throw new Error('Terlalu sering. Tunggu sebentar lalu coba lagi.');
                        if (!res.ok && !data.note) throw new Error(data.message || 'Gagal menghubungi server.');
                        return { ok: res.ok, data };
                    } catch (e) {
                        this.status = 'error';
                        this.note = e.message;
                        return { ok: false, data: {} };
                    } finally {
                        this.busy = false;
                    }
                },
                async check() {
                    const { data } = await this.post(this.checkUrl);
                    if (data.status) {
                        this.status = data.status;
                        this.note = data.note;
                        this.checkedAt = data.checked_at;
                        this.canSync = data.can_sync;
                    }
                },
                async sync() {
                    if (!confirm('YODU menyatakan transaksi ini LUNAS. Terapkan ke sistem (iuran member ditandai lunas)?')) return;
                    const { ok, data } = await this.post(this.syncUrl);
                    if (data.note) {
                        this.status = data.status || (ok ? 'match' : 'mismatch');
                        this.note = data.note;
                        this.canSync = false;
                    }
                    if (ok) window.location.reload();
                },
            };
        }
    </script>
@endsection
