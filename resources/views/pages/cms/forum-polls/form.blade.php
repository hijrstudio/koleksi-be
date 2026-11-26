@extends('layouts.app')

@php
    use App\Http\Controllers\Cms\ForumPollController;
    use App\Models\ForumPoll;

    $isEdit = $poll->exists;
    $tz = ForumPollController::TZ;
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90';
    $border = fn (string $f) => $errors->has($f) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $type = old('type', $poll->type);
    $status = old('status', $poll->status);
    $startsAt = old('starts_at', $poll->starts_at?->timezone($tz)->format('Y-m-d\TH:i'));
    $endsAt = old('ends_at', $poll->ends_at?->timezone($tz)->format('Y-m-d\TH:i'));
    $options = array_values((array) old('options', $isEdit ? $poll->options->pluck('label')->all() : ['', '']));
    $options = array_pad($options, ForumPoll::MIN_OPTIONS, '');
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    @include('pages.cms.forum-discussions.partials.flash')

    <form method="POST" action="{{ $isEdit ? route('forum-polls.update', $poll) : route('forum-polls.store') }}"
        class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="space-y-6 px-5 py-6 sm:px-6">
            @unless ($isEdit)
                <p class="text-theme-sm text-gray-500 dark:text-gray-400">Polling dari CMS tampil di website atas nama Anda dengan label Moderator. Member hanya bisa memilih.</p>
            @endunless

            <div>
                <span class="{{ $labelClass }}">Jenis<span class="text-error-500">*</span></span>
                @if ($locked)
                    <p class="text-sm text-gray-800 dark:text-white/90">{{ $poll->isVoting() ? 'Voting' : 'Polling' }}
                        <span class="text-theme-xs text-gray-500 dark:text-gray-400">(tidak bisa diubah karena sudah ada suara)</span></p>
                @else
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        @foreach ([
                            ForumPoll::VOTING => ['Voting', 'Hasil disembunyikan sampai selesai. Member memilih sekali dan tidak bisa mengubah. Wajib ada waktu selesai.'],
                            ForumPoll::POLLING => ['Polling', 'Hasil persen langsung terlihat. Member boleh mengganti pilihan selama polling berjalan.'],
                        ] as $value => [$label, $hint])
                            <label class="flex cursor-pointer gap-3 rounded-xl border border-gray-200 p-4 has-checked:border-brand-500 dark:border-gray-700">
                                <input type="radio" name="type" value="{{ $value }}" @checked($type === $value) class="accent-brand-500 mt-0.5 h-4 w-4" />
                                <span>
                                    <span class="block text-sm font-medium text-gray-800 dark:text-white/90">{{ $label }}</span>
                                    <span class="mt-1 block text-theme-xs text-gray-500 dark:text-gray-400">{{ $hint }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                @endif
                @error('type')
                    <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="title" class="{{ $labelClass }}">Pertanyaan / Judul<span class="text-error-500">*</span></label>
                <input type="text" id="title" name="title" required maxlength="{{ ForumPoll::TITLE_MAX }}"
                    value="{{ old('title', $poll->title) }}" class="{{ $inputClass }} h-11 {{ $border('title') }}" />
                @error('title')
                    <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <span class="{{ $labelClass }}">Opsi<span class="text-error-500">*</span></span>
                @if ($locked)
                    <ul class="list-inside list-disc text-sm text-gray-800 dark:text-white/90">
                        @foreach ($poll->options as $option)
                            <li>{{ $option->label }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">Opsi dikunci karena sudah ada {{ number_format($poll->votes_count) }} suara.</p>
                @else
                    <div x-data="{ options: @js($options), max: {{ ForumPoll::MAX_OPTIONS }}, min: {{ ForumPoll::MIN_OPTIONS }} }" class="space-y-3">
                        <template x-for="(option, i) in options" :key="i">
                            <div class="flex items-center gap-2">
                                <input type="text" name="options[]" x-model="options[i]" maxlength="{{ ForumPoll::OPTION_MAX }}"
                                    :placeholder="'Opsi ' + (i + 1)" class="{{ $inputClass }} h-11 {{ $border('options') }}" />
                                <button type="button" x-show="options.length > min" @click="options.splice(i, 1)"
                                    class="h-11 shrink-0 rounded-lg border border-gray-300 px-3 text-sm text-gray-500 hover:text-error-600 dark:border-gray-700 dark:text-gray-400"
                                    aria-label="Hapus opsi">✕</button>
                            </div>
                        </template>
                        <button type="button" x-show="options.length < max" @click="options.push('')"
                            class="text-brand-500 hover:text-brand-600 text-sm font-medium">+ Tambah opsi</button>
                    </div>
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ ForumPoll::MIN_OPTIONS }}–{{ ForumPoll::MAX_OPTIONS }} opsi. Setelah ada suara, jenis &amp; opsi tidak bisa diubah lagi.</p>
                @endif
                @error('options')
                    <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                @enderror
                @error('options.*')
                    <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                <div>
                    <label for="starts_at" class="{{ $labelClass }}">Mulai (WIB)</label>
                    <input type="datetime-local" id="starts_at" name="starts_at" value="{{ $startsAt }}" class="{{ $inputClass }} h-11 {{ $border('starts_at') }}" />
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">Kosongkan untuk mulai sekarang.</p>
                    @error('starts_at')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="ends_at" class="{{ $labelClass }}">Selesai (WIB)</label>
                    <input type="datetime-local" id="ends_at" name="ends_at" value="{{ $endsAt }}" class="{{ $inputClass }} h-11 {{ $border('ends_at') }}" />
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">Wajib untuk voting. Polling boleh kosong (ditutup manual).</p>
                    @error('ends_at')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <span class="{{ $labelClass }}">Status</span>
                    <div class="flex h-11 flex-wrap items-center gap-6">
                        @foreach ([ForumPoll::PUBLISHED => 'Tampil', ForumPoll::HIDDEN => 'Disembunyikan'] as $value => $label)
                            <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                                <input type="radio" name="status" value="{{ $value }}" @checked($status === $value) class="accent-brand-500 h-4 w-4" />
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
            <a href="{{ $isEdit ? route('forum-polls.show', $poll) : route('forum-polls.index') }}"
                class="shadow-theme-xs inline-flex h-11 items-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">Batal</a>
            <button type="submit" class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 items-center rounded-lg px-5 text-sm font-medium text-white">
                {{ $isEdit ? 'Simpan Perubahan' : 'Terbitkan' }}
            </button>
        </div>
    </form>
@endsection
