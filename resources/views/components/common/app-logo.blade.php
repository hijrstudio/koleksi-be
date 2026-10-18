@props([
    'size' => 40,
    'showText' => true,
    'textClass' => 'text-xl text-gray-900 dark:text-white',
])

{{-- Logo Koleksi + teks "KoleksiAdmin". Logo dibungkus kotak putih supaya huruf K abu-abu tetap terbaca di background gelap. --}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-3']) }}>
    <span class="flex shrink-0 items-center justify-center rounded-lg bg-white p-0.5"
        style="width: {{ $size }}px; height: {{ $size }}px;">
        <img src="{{ asset('images/logo/logo-koleksi-256.png') }}" alt="Koleksi" class="h-full w-full object-contain" />
    </span>
    @if ($showText)
        <span class="whitespace-nowrap font-bold tracking-tight {{ $textClass }}">
            Koleksi<span class="text-brand-500">Admin</span>
        </span>
    @endif
</span>
