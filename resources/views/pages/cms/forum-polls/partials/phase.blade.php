@php
    [$label, $class] = match ($poll->phase()) {
        App\Models\ForumPoll::PHASE_SCHEDULED => ['Terjadwal', 'bg-blue-light-50 text-blue-light-700 dark:bg-blue-light-500/15 dark:text-blue-light-500'],
        App\Models\ForumPoll::PHASE_OPEN => ['Berjalan', 'bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-orange-400'],
        default => [$poll->closed_at ? 'Ditutup' : 'Selesai', 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400'],
    };
@endphp
<span class="rounded-full px-2.5 py-0.5 text-theme-xs font-medium {{ $class }}">{{ $label }}</span>
