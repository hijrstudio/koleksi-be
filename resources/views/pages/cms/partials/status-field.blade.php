{{-- Radio status draft / published + info tanggal pertama dipublish. Variabel: $model, $statuses --}}
<div>
    <span class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Status<span class="text-error-500">*</span></span>
    <div class="flex h-11 flex-wrap items-center gap-6">
        @foreach ($statuses as $status)
            <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                <input type="radio" name="status" value="{{ $status->value }}" @checked(old('status', $model->status?->value) === $status->value)
                    class="accent-brand-500 h-4 w-4" />
                {{ $status->label() }}
            </label>
        @endforeach
    </div>
    @if ($model->published_at)
        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">Pertama dipublish {{ $model->published_at->format('d M Y H:i') }}.</p>
    @endif
    @error('status')
        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
    @enderror
</div>
