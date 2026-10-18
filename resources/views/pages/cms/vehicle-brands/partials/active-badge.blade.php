{{-- Badge status aktif / nonaktif. Variabel: $active --}}
<span @class([
    'rounded-full px-2.5 py-0.5 text-theme-xs font-medium whitespace-nowrap',
    'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500' => $active,
    'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400' => ! $active,
])>
    {{ $active ? 'Aktif' : 'Nonaktif' }}
</span>
