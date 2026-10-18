@extends('layouts.app')

@php
    $cardClass = 'rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]';
    $dateTime = fn ($date) => $date?->translatedFormat('d M Y H:i') ?? '—';
@endphp

@section('content')
    <x-common.page-breadcrumb pageTitle="Pendaftaran Member" />

    <div class="{{ $cardClass }}">
        <!-- Tab status + pencarian -->
        <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 lg:flex-row lg:items-center lg:justify-between dark:border-gray-800">
            <nav class="flex flex-wrap gap-2" aria-label="Status pendaftaran">
                @foreach ($tabs as $value => $label)
                    <a href="{{ route('member-registrations.index', ['status' => $value]) }}"
                        @if ($status === $value) aria-current="page" @endif
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-theme-sm font-medium transition {{ $status === $value ? 'bg-brand-500 text-white' : 'text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/5' }}">
                        {{ $label }}
                        @if (($counts[$value] ?? 0) > 0)
                            <span class="rounded-full px-1.5 text-theme-xs {{ $status === $value ? 'bg-white/20' : 'bg-orange-50 text-orange-500 dark:bg-orange-500/15' }}">{{ $counts[$value] }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>

            <form method="GET" action="{{ route('member-registrations.index') }}" class="flex gap-2">
                <input type="hidden" name="status" value="{{ $status }}">
                <input type="search" name="q" value="{{ request('q') }}" maxlength="100" placeholder="Cari nama, e-mail, No. HP..."
                    class="shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden lg:w-72 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30">
                <button type="submit" class="bg-brand-500 hover:bg-brand-600 h-11 rounded-lg px-4 text-sm font-medium text-white">Cari</button>
            </form>
        </div>

        <div class="max-w-full overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        @foreach (['Nama', 'Kontak', 'Kendaraan', 'Didaftarkan', $status === 'pending' ? 'E-mail diverifikasi' : 'Direview', ''] as $heading)
                            <th class="whitespace-nowrap px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($registrations as $registration)
                        @php($vehicle = $registration->vehicles->sortByDesc('is_primary')->first())
                        <tr>
                            <td class="px-5 py-3">
                                <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $registration->nama_lengkap }}</p>
                                @if ($registration->no_lambung)
                                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $registration->noLambungLabel() }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">
                                <p>{{ $registration->email }}</p>
                                <p>{{ $registration->no_hp }}</p>
                            </td>
                            <td class="px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">
                                @if ($vehicle)
                                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $vehicle->plat_display }}</p>
                                    <p>{{ $vehicle->displayName() ?: '—' }}</p>
                                @else
                                    Tidak ada
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ $dateTime($registration->registered_at) }}</td>
                            <td class="whitespace-nowrap px-5 py-3 text-theme-sm text-gray-500 dark:text-gray-400">
                                @if ($status === 'pending')
                                    {{ $dateTime($registration->email_verified_at) }}
                                    <p class="text-theme-xs">{{ $registration->email_verified_at?->diffForHumans() }}</p>
                                @else
                                    {{ $dateTime($registration->reviewed_at) }}
                                    @if ($registration->reviewer)
                                        <p class="text-theme-xs">oleh {{ $registration->reviewer->name }}</p>
                                    @endif
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-5 py-3 text-end">
                                <a href="{{ route('member-registrations.show', $registration) }}"
                                    class="text-brand-500 hover:text-brand-600 text-theme-sm font-medium">
                                    {{ $status === 'pending' ? 'Review' : 'Detail' }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                {{ $status === 'pending' ? 'Tidak ada pendaftaran yang menunggu review.' : 'Tidak ada data.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($registrations->hasPages())
            <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                {{ $registrations->links() }}
            </div>
        @endif
    </div>
@endsection
