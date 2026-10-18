{{-- Upload satu file (gambar / dokumen) dengan preview file saat ini dan opsi hapus --}}
@php
    $currentUrl = $aboutUs->fileUrl($field);
@endphp

<div x-data="{
    previewUrl: @js($isImage ? $currentUrl : null),
    remove: @js((bool) old("remove_$field")),
    pick(event) {
        const file = event.target.files[0];
        if (!file) return;
        this.remove = false;
        if (!@js($isImage)) return;
        if (this.previewUrl?.startsWith('blob:')) URL.revokeObjectURL(this.previewUrl);
        this.previewUrl = URL.createObjectURL(file);
    }
}">
    <label for="{{ $field }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">{{ $label }}</label>

    @if ($isImage)
        <div x-show="previewUrl && !remove" class="mb-3 overflow-hidden rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-gray-900">
            <img :src="previewUrl" alt="Preview {{ $label }}" class="max-h-72 w-full object-contain" />
        </div>
    @endif

    @if ($currentUrl)
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-800"
            :class="remove ? 'opacity-50' : ''">
            <a href="{{ $currentUrl }}" target="_blank" rel="noopener noreferrer"
                class="text-brand-500 truncate text-sm hover:underline">Lihat file saat ini ({{ strtoupper(pathinfo($aboutUs->{$field}, PATHINFO_EXTENSION)) }})</a>
            @if ($canUpdate)
                <label class="flex cursor-pointer items-center gap-2 text-theme-xs text-gray-600 dark:text-gray-400">
                    <input type="checkbox" name="remove_{{ $field }}" value="1" x-model="remove" class="accent-error-500 h-4 w-4" />
                    Hapus file
                </label>
            @endif
        </div>
    @endif

    <input type="file" id="{{ $field }}" name="{{ $field }}" accept="{{ $accept }}" @change="pick"
        class="focus:border-ring-brand-300 shadow-theme-xs h-11 w-full overflow-hidden rounded-lg border {{ $errors->has($field) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700' }} bg-transparent text-sm text-gray-500 transition-colors file:mr-5 file:border-collapse file:cursor-pointer file:rounded-l-lg file:border-0 file:border-r file:border-solid file:border-gray-200 file:bg-gray-50 file:py-3 file:pr-3 file:pl-3.5 file:text-sm file:text-gray-700 placeholder:text-gray-400 hover:file:bg-gray-100 focus:outline-hidden dark:bg-gray-900 dark:text-gray-400 dark:file:border-gray-800 dark:file:bg-white/[0.03] dark:file:text-gray-400" />
    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
        {{ $hint }}
        @if ($currentUrl)
            Kosongkan jika tidak ingin mengganti file.
        @endif
    </p>
    @error($field)
        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
    @enderror
</div>
