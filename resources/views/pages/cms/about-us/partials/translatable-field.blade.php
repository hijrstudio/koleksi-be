{{-- Satu field json {"id","en"}: rich text editor atau textarea biasa, ditampilkan per bahasa --}}
@php
    $textareaClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
@endphp

<div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
    @foreach (['id' => 'Indonesia', 'en' => 'English'] as $lang => $langLabel)
        @php
            $key = "$field.$lang";
            $inputId = "{$field}_{$lang}";
            $value = old($key, $aboutUs->{$field}[$lang] ?? '');
            $isRequired = $required && $lang === 'id';
        @endphp

        <div>
            <label for="{{ $inputId }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                {{ $label }} ({{ $langLabel }})@if ($isRequired)<span class="text-error-500">*</span>@endif
            </label>

            @if ($type === 'rich')
                <x-form.rich-editor :id="$inputId" name="{{ $field }}[{{ $lang }}]" :value="$value"
                    :disabled="! $canUpdate" :invalid="$errors->has($key)" />
            @else
                <textarea id="{{ $inputId }}" name="{{ $field }}[{{ $lang }}]" rows="4"
                    maxlength="{{ \App\Http\Requests\Cms\AboutUsRequest::MAX_VISI_LENGTH }}" @required($isRequired)
                    class="{{ $textareaClass }} {{ $errors->has($key) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700' }}">{{ $value }}</textarea>
            @endif

            @if ($lang === 'en')
                <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">Jika kosong, website versi English memakai teks Indonesia.</p>
            @endif
            @error($key)
                <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
            @enderror
        </div>
    @endforeach
</div>
@error($field)
    <p class="text-error-500 -mt-4 text-theme-xs">{{ $message }}</p>
@enderror
