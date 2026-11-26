@extends('layouts.app')

@php
    $tz = App\Http\Controllers\Cms\ForumPollController::TZ;
    $top = $poll->options->max('votes_count');
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    @include('pages.cms.forum-discussions.partials.flash')

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('forum-polls.index') }}" class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">← Kembali ke daftar polling</a>
        <div class="flex flex-wrap items-center gap-2">
            @can('update', $poll)
                @php($closing = $poll->closed_at === null)
                @if ($closing ? $poll->phase() !== App\Models\ForumPoll::PHASE_ENDED : true)
                    <form method="POST" action="{{ route('forum-polls.close', $poll) }}"
                        x-data @submit.prevent="if (confirm(@js($closing ? 'Tutup polling ini sekarang? Member tidak bisa memilih lagi.' : 'Buka lagi polling ini?'))) $el.submit()">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="closed" value="{{ $closing ? 1 : 0 }}" />
                        <button type="submit" class="rounded-lg border border-gray-300 px-3 py-1.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400">
                            {{ $closing ? 'Tutup sekarang' : 'Buka lagi' }}
                        </button>
                    </form>
                @endif
                <a href="{{ route('forum-polls.edit', $poll) }}"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400">Edit</a>
            @endcan
            @can('delete', $poll)
                <form method="POST" action="{{ route('forum-polls.destroy', $poll) }}"
                    x-data @submit.prevent="if (confirm('Hapus polling ini? Polling hilang dari website.')) $el.submit()">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="border-error-300 text-error-600 hover:bg-error-50 dark:border-error-500/40 dark:text-error-500 rounded-lg border px-3 py-1.5 text-theme-sm font-medium">Hapus</button>
                </form>
            @endcan
        </div>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white p-5 sm:p-6 dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="flex flex-wrap items-center gap-2 text-theme-xs">
            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ $poll->isVoting() ? 'Voting' : 'Polling' }}</span>
            @include('pages.cms.forum-polls.partials.phase', ['poll' => $poll])
            <span class="rounded-full px-2.5 py-0.5 {{ $poll->isPublished() ? 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-500' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' }}">
                {{ $poll->isPublished() ? 'Tampil' : 'Disembunyikan' }}
            </span>
        </div>
        <h2 class="mt-3 text-xl font-semibold text-gray-800 dark:text-white/90">{{ $poll->title }}</h2>
        <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
            {{ $poll->user?->name ?? 'Admin' }}
            · {{ $poll->starts_at->timezone($tz)->format('d M Y H:i') }}
            s.d. {{ $poll->ends_at ? $poll->ends_at->timezone($tz)->format('d M Y H:i') : 'ditutup manual' }} WIB
            @if ($poll->closed_at) · ditutup {{ $poll->closed_at->timezone($tz)->format('d M Y H:i') }} @endif
            · {{ number_format($poll->votes_count) }} suara
        </p>
        @if ($poll->isVoting() && ! $poll->resultsVisible())
            <p class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-theme-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">Voting masih berjalan: hasil ini hanya terlihat oleh admin, member baru melihatnya setelah voting selesai.</p>
        @endif

        <ul class="mt-6 space-y-4">
            @foreach ($poll->options as $option)
                @php($percent = $percents[$option->id] ?? 0)
                <li>
                    <div class="flex items-baseline justify-between gap-4 text-sm">
                        <span class="{{ $option->votes_count === $top && $top > 0 ? 'font-semibold text-gray-800 dark:text-white/90' : 'text-gray-700 dark:text-gray-300' }}">{{ $option->label }}</span>
                        <span class="shrink-0 text-gray-500 dark:text-gray-400">{{ number_format($option->votes_count) }} suara · {{ $percent }}%</span>
                    </div>
                    <div class="mt-1.5 h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                        <div class="bg-brand-500 h-full rounded-full" style="width: {{ $percent }}%"></div>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
@endsection
