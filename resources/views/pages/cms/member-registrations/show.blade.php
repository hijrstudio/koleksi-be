@extends('layouts.app')

@php
    $cardClass = 'rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]';
    $dateOrDash = fn ($date) => $date?->translatedFormat('d M Y') ?? '—';
    $dateTime = fn ($date) => $date?->translatedFormat('d M Y H:i') ?? '—';

    $statusBadge = [
        'unverified' => ['Belum verifikasi e-mail', 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400'],
        'pending' => ['Menunggu review', 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400'],
        'approved' => ['Disetujui', 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500'],
        'rejected' => ['Ditolak', 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500'],
    ][$member->registration_status];

    $profile = [
        'Nama Lengkap' => $member->nama_lengkap,
        'Nama Panggilan' => $member->nama_panggilan ?? '—',
        'E-mail' => $member->email,
        'No. HP' => $member->no_hp ?? '—',
        'Tgl Lahir' => $dateOrDash($member->tgl_lahir),
        'Jenis Kelamin' => ['L' => 'Laki-laki', 'P' => 'Perempuan'][$member->jenis_kelamin] ?? '—',
        'Alamat' => $member->alamat ?? '—',
        'Wilayah' => collect([$member->village?->name, $member->district?->name, $member->city?->name])->filter()->join(', ') ?: '—',
        'Tahu KOLEKSI dari' => $member->heard_from ?? '—',
        'Didaftarkan' => $dateTime($member->registered_at),
        'E-mail diverifikasi' => $dateTime($member->email_verified_at),
        'IP pendaftaran' => $member->registration_ip ?? '—',
    ];

    $fee = 'Rp'.number_format((int) config('membership.annual_fee'), 0, ',', '.');
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
@endphp

@section('content')
    <x-common.page-breadcrumb pageTitle="Review Pendaftaran" />

    <div class="mb-4">
        <a href="{{ route('member-registrations.index', ['status' => $member->registration_status]) }}"
            class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">
            <span class="rtl:rotate-180">←</span> Kembali ke {{ $tabs[$member->registration_status] ?? 'daftar pendaftaran' }}
        </a>
    </div>

    @if (session('success'))
        <div class="border-success-500 bg-success-50 dark:border-success-500/30 dark:bg-success-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ session('success') }}</p>
        </div>
    @endif
    @error('registration')
        <div class="border-error-500 bg-error-50 dark:border-error-500/30 dark:bg-error-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ $message }}</p>
        </div>
    @enderror

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <!-- Data pendaftar -->
        <div class="{{ $cardClass }} xl:col-span-2">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $member->nama_lengkap }}</h3>
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                        {{ $member->no_lambung ? $member->noLambungLabel() : 'Belum punya No. lambung' }}
                    </p>
                </div>
                <span class="rounded-full px-2.5 py-0.5 text-theme-xs font-medium {{ $statusBadge[1] }}">{{ $statusBadge[0] }}</span>
            </div>
            <dl class="grid grid-cols-1 gap-x-6 gap-y-4 px-5 py-5 sm:grid-cols-2">
                @foreach ($profile as $label => $value)
                    <div>
                        <dt class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="mt-0.5 break-words text-theme-sm text-gray-800 dark:text-white/90">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        <!-- Keputusan -->
        <div class="{{ $cardClass }}">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Keputusan</h3>
            </div>
            <div class="space-y-5 px-5 py-5">
                @if ($member->isAwaitingReview())
                    @can('review', $member)
                        <form method="POST" action="{{ route('member-registrations.approve', $member) }}"
                            @submit="if (!confirm({{ Js::from('Setujui pendaftaran '.$member->nama_lengkap.'? No. lambung akan diberikan dan e-mail pembayaran iuran dikirim.') }})) $event.preventDefault()">
                            @csrf
                            <p class="mb-3 text-theme-sm text-gray-500 dark:text-gray-400">
                                Pendaftar mendapat No. lambung berikutnya dan e-mail untuk aktivasi dengan membayar iuran {{ $fee }} (QRIS di dasbor anggota).
                                Kendaraan & dokumen ikut ditandai terverifikasi.
                            </p>
                            <button type="submit"
                                class="bg-success-500 hover:bg-success-600 shadow-theme-xs inline-flex h-11 w-full items-center justify-center rounded-lg px-4 text-sm font-medium text-white transition">
                                Setujui Pendaftaran
                            </button>
                        </form>

                        <form method="POST" action="{{ route('member-registrations.reject', $member) }}"
                            class="space-y-3 border-t border-gray-100 pt-5 dark:border-gray-800"
                            @submit="if (!confirm('Tolak pendaftaran ini? Pendaftar akan menerima e-mail berisi alasan penolakan.')) $event.preventDefault()">
                            @csrf
                            <label for="reason" class="block text-sm font-medium text-gray-700 dark:text-gray-400">
                                Alasan penolakan<span class="text-error-500">*</span>
                            </label>
                            <textarea id="reason" name="reason" rows="3" minlength="5" maxlength="500" required
                                placeholder="mis. Dokumen STNK tidak terbaca, mohon unggah ulang."
                                class="{{ $inputClass }} @error('reason') border-error-500 @else border-gray-300 dark:border-gray-700 @enderror">{{ old('reason') }}</textarea>
                            @error('reason')
                                <p class="text-error-500 text-theme-xs">{{ $message }}</p>
                            @enderror
                            <p class="text-theme-xs text-gray-500 dark:text-gray-400">Alasan dikirim ke pendaftar lewat e-mail. Pendaftar bisa mendaftar ulang.</p>
                            <button type="submit"
                                class="border-error-500 text-error-600 hover:bg-error-50 dark:hover:bg-error-500/15 inline-flex h-11 w-full items-center justify-center rounded-lg border px-4 text-sm font-medium transition">
                                Tolak Pendaftaran
                            </button>
                        </form>
                    @else
                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">Anda tidak memiliki akses untuk menyetujui / menolak pendaftaran.</p>
                    @endcan
                @elseif ($member->registration_status === 'unverified')
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                        Pendaftar belum memverifikasi e-mail. Pendaftaran bisa direview setelah e-mail diverifikasi.
                        Data dihapus otomatis bila tidak diverifikasi dalam {{ config('membership.registration.unverified_ttl_days') }} hari.
                    </p>
                @else
                    <div class="space-y-3 text-theme-sm">
                        <p class="text-gray-800 dark:text-white/90">
                            {{ $member->isRegistrationApproved() ? 'Disetujui' : 'Ditolak' }}
                            oleh {{ $member->reviewer?->name ?? '—' }} · {{ $dateTime($member->reviewed_at) }}
                        </p>
                        @if ($member->review_note)
                            <p class="text-gray-500 dark:text-gray-400">Alasan: {{ $member->review_note }}</p>
                        @endif
                        @if ($member->isRegistrationApproved())
                            <p class="text-gray-500 dark:text-gray-400">
                                Status keanggotaan: <x-cms.member-status-badge :member="$member" />
                            </p>
                            <a href="{{ route('members.show', $member) }}" class="text-brand-500 hover:text-brand-600 font-medium">Lihat di Daftar Member →</a>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        <!-- Kendaraan & dokumen -->
        <div class="{{ $cardClass }} xl:col-span-3">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Kendaraan & Dokumen</h3>
            </div>
            @forelse ($member->vehicles as $vehicle)
                <div class="grid grid-cols-1 gap-6 border-b border-gray-100 px-5 py-5 last:border-b-0 lg:grid-cols-2 dark:border-gray-800">
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-4">
                        @foreach ([
                            'No. Plat' => $vehicle->plat_display.' ('.$vehicle->plat_status.')',
                            'Kendaraan' => $vehicle->displayName() ?: '—',
                            'Tahun' => $vehicle->tahun ?? '—',
                            'No. Rangka (VIN)' => $vehicle->vin,
                            'Verifikasi' => ucfirst($vehicle->verification_status),
                        ] as $label => $value)
                            <div>
                                <dt class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                <dd class="mt-0.5 break-all text-theme-sm text-gray-800 dark:text-white/90">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <div>
                        <p class="mb-2 text-theme-xs text-gray-500 dark:text-gray-400">Bukti dokumen (STNK, SPK, Invoice)</p>
                        <ul class="space-y-2">
                            @forelse ($vehicle->documents as $document)
                                <li>
                                    <a href="{{ route('member-documents.show', $document) }}" target="_blank" rel="noopener"
                                        class="hover:border-brand-300 dark:hover:border-brand-800 flex items-center justify-between gap-3 rounded-lg border border-gray-200 px-4 py-2.5 text-theme-sm text-gray-800 transition dark:border-gray-800 dark:text-white/90">
                                        <span class="truncate">{{ $document->original_name }}</span>
                                        <span class="shrink-0 text-theme-xs text-gray-500 dark:text-gray-400">
                                            {{ $document->mime_type === 'application/pdf' ? 'PDF' : 'Gambar' }} · {{ number_format($document->size / 1024, 0, ',', '.') }} KB
                                        </span>
                                    </a>
                                </li>
                            @empty
                                <li class="text-theme-sm text-gray-500 dark:text-gray-400">Tidak ada dokumen.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            @empty
                <p class="px-5 py-6 text-center text-theme-sm text-gray-500 dark:text-gray-400">Pendaftar tidak memiliki kendaraan.</p>
            @endforelse
        </div>
    </div>
@endsection
