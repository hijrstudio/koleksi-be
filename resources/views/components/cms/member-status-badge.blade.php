@props(['member'])

<span class="inline-flex items-center gap-1.5">
    @if ($member->isActive())
        <span class="bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500 rounded-full px-2.5 py-0.5 text-theme-xs font-medium">Aktif</span>
    @else
        <span class="bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500 rounded-full px-2.5 py-0.5 text-theme-xs font-medium">Tidak Aktif</span>
    @endif
    @if ($member->isManualStatus())
        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-theme-xs text-gray-600 dark:bg-gray-800 dark:text-gray-400"
            title="Status ditetapkan manual oleh admin, tidak mengikuti iuran">Manual</span>
    @endif
</span>
