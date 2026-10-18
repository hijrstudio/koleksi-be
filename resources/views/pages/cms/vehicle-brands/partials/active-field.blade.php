{{-- Radio status aktif / nonaktif. Variabel: $active (nilai saat ini), $hint --}}
<div>
    <span class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Status<span class="text-error-500">*</span></span>
    <div class="flex h-11 flex-wrap items-center gap-6">
        @foreach (['1' => 'Aktif', '0' => 'Nonaktif'] as $value => $label)
            <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                <input type="radio" name="is_active" value="{{ $value }}" @checked((string) old('is_active', (int) $active) === (string) $value)
                    class="accent-brand-500 h-4 w-4" />
                {{ $label }}
            </label>
        @endforeach
    </div>
    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
    @error('is_active')
        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
    @enderror
</div>
