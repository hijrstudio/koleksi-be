@props([
    'name',
    'id' => null,
    'value' => null,
    'disabled' => false,
    'invalid' => false,
    // ['url' => ..., 'maxKb' => ..., 'mimes' => [...]] untuk mengaktifkan sisip gambar; null = tanpa gambar
    'imageUpload' => null,
])

@php
    $tools = [
        ['key' => 'bold', 'label' => 'B', 'title' => 'Tebal', 'class' => 'font-bold', 'command' => 'toggleBold'],
        ['key' => 'italic', 'label' => 'I', 'title' => 'Miring', 'class' => 'italic', 'command' => 'toggleItalic'],
        ['key' => 'underline', 'label' => 'U', 'title' => 'Garis bawah', 'class' => 'underline', 'command' => 'toggleUnderline'],
        ['key' => 'strike', 'label' => 'S', 'title' => 'Coret', 'class' => 'line-through', 'command' => 'toggleStrike'],
        ['key' => 'h2', 'label' => 'H2', 'title' => 'Judul', 'class' => 'font-semibold', 'command' => 'toggleHeading', 'args' => ['level' => 2]],
        ['key' => 'h3', 'label' => 'H3', 'title' => 'Sub judul', 'class' => 'font-semibold', 'command' => 'toggleHeading', 'args' => ['level' => 3]],
        ['key' => 'bulletList', 'label' => '• List', 'title' => 'Daftar berbutir', 'class' => '', 'command' => 'toggleBulletList'],
        ['key' => 'orderedList', 'label' => '1. List', 'title' => 'Daftar bernomor', 'class' => '', 'command' => 'toggleOrderedList'],
        ['key' => 'blockquote', 'label' => '❝', 'title' => 'Kutipan', 'class' => '', 'command' => 'toggleBlockquote'],
    ];
    $buttonClass = 'inline-flex h-8 min-w-8 items-center justify-center rounded-md px-2 text-sm text-gray-700 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/[0.05]';
    $activeClass = 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400';
@endphp

<div x-data="richEditor(@js(['content' => $value ?? '', 'editable' => ! $disabled, 'imageUpload' => $imageUpload]))"
    {{ $attributes->class([
        'overflow-hidden rounded-lg border bg-transparent dark:bg-gray-900',
        'border-error-500' => $invalid,
        'border-gray-300 dark:border-gray-700 focus-within:border-brand-300 dark:focus-within:border-brand-800' => ! $invalid,
    ]) }}>
    @unless ($disabled)
        <div class="flex flex-wrap items-center gap-1 border-b border-gray-200 bg-gray-50 px-2 py-1.5 dark:border-gray-800 dark:bg-white/[0.03]">
            @foreach ($tools as $tool)
                <button type="button" title="{{ $tool['title'] }}" :disabled="!ready"
                    @click="run(@js($tool['command']){{ isset($tool['args']) ? ', '.\Illuminate\Support\Js::from($tool['args']) : '' }})"
                    :class="active[@js($tool['key'])] ? @js($activeClass) : ''"
                    class="{{ $buttonClass }} {{ $tool['class'] }}">{{ $tool['label'] }}</button>
            @endforeach
            <button type="button" title="Link" :disabled="!ready" @click="setLink()"
                :class="active.link ? @js($activeClass) : ''" class="{{ $buttonClass }}">Link</button>
            @if ($imageUpload)
                <button type="button" title="Sisipkan gambar (bisa juga paste / drag & drop)" :disabled="!ready" @click="pickImage()"
                    class="{{ $buttonClass }}">Gambar</button>
                <button type="button" title="Teks alternatif gambar terpilih" x-show="active.image" @click="setImageAlt()"
                    class="{{ $buttonClass }}">Alt</button>
                <input type="file" x-ref="imageInput" class="hidden" multiple
                    accept="{{ implode(',', $imageUpload['mimes']) }}" @change="onImagePicked($event)">
            @endif
            <span class="mx-1 h-5 w-px bg-gray-200 dark:bg-gray-700"></span>
            <button type="button" title="Undo" :disabled="!ready" @click="run('undo')" class="{{ $buttonClass }}">↶</button>
            <button type="button" title="Redo" :disabled="!ready" @click="run('redo')" class="{{ $buttonClass }}">↷</button>
        </div>
    @endunless

    <div x-ref="editor" @if ($id) id="{{ $id }}" @endif></div>
    @if ($imageUpload && ! $disabled)
        <div x-show="uploading || uploadError" class="border-t border-gray-200 px-4 py-2 text-theme-xs dark:border-gray-800">
            <p x-show="uploading" class="text-gray-500 dark:text-gray-400">Mengupload gambar...</p>
            <p x-show="uploadError" x-text="uploadError" class="text-error-500"></p>
        </div>
    @endif
    <input type="hidden" name="{{ $name }}" :value="html" value="{{ $value }}">
</div>
