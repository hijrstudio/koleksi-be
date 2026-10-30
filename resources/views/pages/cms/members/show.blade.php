@extends('layouts.app')

@php
    $cardClass = 'rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]';
    $dateOrDash = fn ($date) => $date?->translatedFormat('d M Y') ?? '—';

    $profile = [
        'No. Lambung' => $member->noLambungLabel(),
        'Nama Lengkap' => $member->nama_lengkap,
        'Nama Panggilan' => $member->nama_panggilan ?? '—',
        'Email' => $member->email ?? '—',
        'No. HP' => $member->no_hp ?? '—',
        'Tgl Bergabung' => $dateOrDash($member->tgl_bergabung),
        'Tgl Lahir' => $dateOrDash($member->tgl_lahir),
        'Jenis Kelamin' => ['L' => 'Laki-laki', 'P' => 'Perempuan'][$member->jenis_kelamin] ?? '—',
        'Alamat' => $member->alamat ?? '—',
        'Profil' => $member->profile_completed_at ? 'Lengkap' : 'Belum dilengkapi',
        'Login terakhir' => $member->last_login_at?->translatedFormat('d M Y H:i') ?? 'Belum pernah',
    ];

    $dueStatus = [
        'paid' => ['Lunas', 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500'],
        'waived' => ['Dibebaskan', 'bg-blue-light-50 text-blue-light-600 dark:bg-blue-light-500/15 dark:text-blue-light-500'],
        'unpaid' => ['Belum bayar', 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400'],
    ];

    $statusLabel = ['active' => 'Aktif', 'inactive' => 'Tidak Aktif'];
    $currentChoice = old('status', $member->isManualStatus() ? $member->status : 'auto');
@endphp

@section('content')
    <x-common.page-breadcrumb pageTitle="Detail Member" />

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        {{-- Halaman, pencarian & filter list dipulihkan otomatis oleh DataTables (stateSave) --}}
        <a href="{{ route('members.index') }}"
            class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">
            ← Kembali ke daftar member
        </a>
        <div class="flex items-center gap-2">
            @can('update', $member)
                <a href="{{ route('members.edit', $member) }}"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                    Edit No. Lambung
                </a>
            @endcan
            @can('delete', $member)
                <form method="POST" action="{{ route('members.destroy', $member) }}"
                    x-data @submit.prevent="if (confirm('Hapus member {{ e($member->nama_lengkap) }} ({{ $member->noLambungLabel() }})?\n\nMember tidak bisa login lagi dan hilang dari daftar. Riwayat iuran tetap tersimpan, No. lambungnya tidak dipakai ulang.')) $el.submit()">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                        class="border-error-300 text-error-600 hover:bg-error-50 dark:border-error-500/40 dark:text-error-500 dark:hover:bg-error-500/10 rounded-lg border px-3 py-1.5 text-theme-sm font-medium">
                        Hapus Member
                    </button>
                </form>
            @endcan
        </div>
    </div>

    @if (session('success'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
            class="border-success-500 bg-success-50 dark:border-success-500/30 dark:bg-success-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ session('success') }}</p>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <!-- Profil -->
        <div class="{{ $cardClass }} xl:col-span-2">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $member->nama_lengkap }}</h3>
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $member->noLambungLabel() }}</p>
                </div>
                <x-cms.member-status-badge :member="$member" />
            </div>
            <dl class="grid grid-cols-1 gap-x-6 gap-y-4 px-5 py-5 sm:grid-cols-2">
                @foreach ($profile as $label => $value)
                    <div>
                        <dt class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="mt-0.5 text-theme-sm text-gray-800 dark:text-white/90">{{ $value }}</dd>
                    </div>
                @endforeach
                @if ($member->notes)
                    <div class="sm:col-span-2">
                        <dt class="text-theme-xs text-gray-500 dark:text-gray-400">Catatan admin</dt>
                        <dd class="mt-0.5 whitespace-pre-line text-theme-sm text-gray-800 dark:text-white/90">{{ $member->notes }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        <!-- Status keanggotaan -->
        <div class="{{ $cardClass }}">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Status Keanggotaan</h3>
            </div>
            <div class="space-y-4 px-5 py-5">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">Status</p>
                        <p class="mt-0.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $statusLabel[$member->status] }}</p>
                    </div>
                    <div>
                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">Mode</p>
                        <p class="mt-0.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                            {{ $member->isManualStatus() ? 'Manual (admin)' : 'Otomatis (iuran)' }}
                        </p>
                    </div>
                    <div class="col-span-2">
                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                            Periode berjalan {{ $member->tgl_bergabung ? '' : '(patokan: tanggal data dibuat, tgl bergabung kosong)' }}
                        </p>
                        <p class="mt-0.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                            {{ $currentTerm->label() }} · {{ $dateOrDash($currentTerm->start) }} – {{ $dateOrDash($currentTerm->end) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">Jatuh tempo daftar ulang</p>
                        <p class="mt-0.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $dateOrDash($member->renewalDate()) }}</p>
                        @if (($daysLeft = $member->daysUntilRenewal()) !== null)
                            <p class="text-theme-xs {{ $daysLeft < 0 ? 'text-error-600 dark:text-error-500' : ($daysLeft <= 30 ? 'text-warning-600 dark:text-orange-400' : 'text-gray-500 dark:text-gray-400') }}">
                                {{ $daysLeft < 0 ? 'Lewat '.abs($daysLeft).' hari' : ($daysLeft === 0 ? 'Hari ini' : "{$daysLeft} hari lagi") }}
                                · periode {{ $renewalTerm->label() }}
                            </p>
                        @endif
                    </div>
                    <div>
                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">Aktif s/d (+{{ config('membership.grace_days') }} hari tenggang)</p>
                        <p class="mt-0.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $dateOrDash($member->active_until) }}</p>
                    </div>
                </div>

                @can('updateStatus', $member)
                    <form method="POST" action="{{ route('members.status.update', $member) }}"
                        class="space-y-4 border-t border-gray-100 pt-4 dark:border-gray-800"
                        x-data="{ choice: @js($currentChoice) }"
                        @submit="if (!confirm('Simpan perubahan status member ini?')) $event.preventDefault()">
                        @csrf
                        @method('PUT')

                        <div>
                            <span class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-400">Ubah status</span>
                            <div class="space-y-2">
                                @foreach (['active' => 'Aktif (manual)', 'inactive' => 'Tidak Aktif (manual)', 'auto' => 'Otomatis — ikuti iuran'] as $value => $label)
                                    <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                                        <input type="radio" name="status" value="{{ $value }}" x-model="choice" class="accent-brand-500 h-4 w-4" />
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                            @error('status')
                                <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="reason" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                Alasan<span x-show="choice !== 'auto'" class="text-error-500">*</span>
                            </label>
                            <textarea id="reason" name="reason" rows="3" maxlength="500" :required="choice !== 'auto'"
                                placeholder="mis. Mengundurkan diri, pengurus, pelanggaran aturan..."
                                class="shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border @error('reason') border-error-500 @else border-gray-300 dark:border-gray-700 @enderror bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30">{{ old('reason') }}</textarea>
                            @error('reason')
                                <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                            @enderror
                            <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                                Status manual tidak akan berubah otomatis saat iuran dibayar / habis, sampai dikembalikan ke mode otomatis.
                            </p>
                        </div>

                        <button type="submit"
                            class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 w-full items-center justify-center rounded-lg px-4 text-sm font-medium text-white transition">
                            Simpan Status
                        </button>
                    </form>
                @endcan
            </div>
        </div>

        <!-- Riwayat iuran -->
        <div class="{{ $cardClass }} xl:col-span-2">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Riwayat Iuran</h3>
            </div>
            <div class="max-w-full overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            @foreach (['Periode', 'Invoice', 'Nominal', 'Status', 'Dibayar', 'Catatan'] as $heading)
                                <th class="whitespace-nowrap px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($member->dues as $due)
                            <tr>
                                <td class="whitespace-nowrap px-5 py-3">
                                    <p class="text-theme-sm text-gray-800 dark:text-white/90">{{ $due->period_label }}</p>
                                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $dateOrDash($due->period_start) }} – {{ $dateOrDash($due->period_end) }}</p>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ $due->invoice_number }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">Rp {{ number_format($due->amount, 0, ',', '.') }}</td>
                                <td class="whitespace-nowrap px-5 py-3">
                                    <span class="rounded-full px-2.5 py-0.5 text-theme-xs font-medium {{ $dueStatus[$due->status][1] }}">{{ $dueStatus[$due->status][0] }}</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ $due->paid_at?->translatedFormat('d M Y') ?? '—' }}</td>
                                <td class="px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ $due->notes ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-6 text-center text-theme-sm text-gray-500 dark:text-gray-400">Belum ada data iuran.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Riwayat status -->
        <div class="{{ $cardClass }}">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Riwayat Status</h3>
            </div>
            <ol class="space-y-4 px-5 py-5">
                @forelse ($member->statusLogs as $log)
                    <li class="border-l-2 border-gray-200 pl-3 dark:border-gray-700">
                        <p class="text-theme-sm text-gray-800 dark:text-white/90">
                            {{ $log->from_status ? $statusLabel[$log->from_status].' → ' : '' }}{{ $statusLabel[$log->to_status] }}
                            <span class="text-theme-xs text-gray-500">({{ $log->mode === 'manual' ? 'manual' : 'otomatis' }})</span>
                        </p>
                        @if ($log->reason)
                            <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $log->reason }}</p>
                        @endif
                        <p class="text-theme-xs text-gray-400">
                            {{ $log->changer?->name ?? 'Sistem' }} · {{ $log->created_at?->translatedFormat('d M Y H:i') }}
                        </p>
                    </li>
                @empty
                    <li class="text-theme-sm text-gray-500 dark:text-gray-400">Belum ada riwayat.</li>
                @endforelse
            </ol>
        </div>

        <!-- Notifikasi daftar ulang yang terkirim ke member -->
        <div class="{{ $cardClass }} xl:col-span-3">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Notifikasi ke Member</h3>
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                    Otomatis: H-{{ implode(', H-', array_filter(config('membership.reminder_days'))) }}, hari jatuh tempo, & saat keanggotaan berakhir
                </p>
            </div>
            <div class="max-w-full overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            @foreach (['Dikirim', 'Jenis', 'Untuk periode berakhir', 'Kanal'] as $heading)
                                <th class="whitespace-nowrap px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($member->renewalReminders as $reminder)
                            <tr>
                                <td class="whitespace-nowrap px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ $reminder->sent_at?->translatedFormat('d M Y H:i') }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-theme-sm text-gray-800 dark:text-white/90">{{ $reminder->stageLabel() }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ $dateOrDash($reminder->period_end) }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ collect($reminder->channels)->map(fn ($c) => ['database' => 'Aplikasi', 'mail' => 'Email'][$c] ?? $c)->join(', ') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-6 text-center text-theme-sm text-gray-500 dark:text-gray-400">Belum ada notifikasi terkirim.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Kendaraan -->
        <div class="{{ $cardClass }} xl:col-span-3">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Kendaraan</h3>
            </div>
            <div class="max-w-full overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            @foreach (['No. Plat', 'Kendaraan', 'Tahun', 'Warna', 'VIN', 'Dokumen', 'Verifikasi'] as $heading)
                                <th class="whitespace-nowrap px-5 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($member->vehicles as $vehicle)
                            <tr>
                                <td class="whitespace-nowrap px-5 py-3 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ $vehicle->plat_display }}
                                    @if ($vehicle->is_primary)
                                        <span class="text-brand-500 text-theme-xs">(utama)</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ $vehicle->displayName() ?: '—' }}</td>
                                <td class="px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ $vehicle->tahun ?? '—' }}</td>
                                <td class="px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ $vehicle->warna ?? '—' }}</td>
                                <td class="px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ $vehicle->vin ?? '—' }}</td>
                                <td class="px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">
                                    @forelse ($vehicle->documents as $document)
                                        <a href="{{ route('member-documents.show', $document) }}" target="_blank" rel="noopener"
                                            class="text-brand-500 block whitespace-nowrap hover:underline">{{ strtoupper($document->type) }} · {{ \Illuminate\Support\Str::limit($document->original_name, 24) }}</a>
                                    @empty
                                        —
                                    @endforelse
                                </td>
                                <td class="px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ ucfirst($vehicle->verification_status) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-6 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    Member belum menambahkan kendaraan.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
