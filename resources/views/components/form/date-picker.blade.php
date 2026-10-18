@props([
    'id' => 'datepicker-' . uniqid(),
    'mode' => 'single', // 'single', 'multiple', 'range', 'time'
    'defaultDate' => null,
    'label' => null,
    'placeholder' => 'Select date',
    'name' => null,
    'dateFormat' => 'Y-m-d', // format yang dikirim ke server
    'altFormat' => null, // format yang ditampilkan ke user, mis. 'j F Y'; null = sama dengan dateFormat
    'locale' => null, // mis. 'id' (Bahasa Indonesia)
    'required' => false,
    'invalid' => false,
])

@php
    // Semua nilai dilewatkan lewat @js (JSON ter-escape), aman walau defaultDate berasal dari input user (old()).
    $config = array_filter([
        'mode' => $mode,
        'static' => true,
        'monthSelectorType' => 'static',
        'dateFormat' => $dateFormat,
        'defaultDate' => $defaultDate ?: null,
        'altInput' => $altFormat !== null,
        'altFormat' => $altFormat,
        'locale' => $locale,
        'allowInput' => false,
    ], fn ($value) => $value !== null);
    $borderClass = $invalid
        ? 'border-error-500 focus:border-error-300 focus:ring-error-500/20'
        : 'border-gray-300 focus:border-brand-300 focus:ring-brand-500/20 dark:border-gray-700 dark:focus:border-brand-800';
@endphp

<div x-data="{
    flatpickrInstance: null,
    init() {
        this.$nextTick(() => {
            this.flatpickrInstance = flatpickr(this.$refs.dateInput, {
                ...@js($config),
                onChange: (selectedDates, dateStr, instance) => {
                    this.$dispatch('date-change', {
                        selectedDates,
                        dateStr,
                        instance
                    });
                }
            });
        });
    },
    destroy() {
        if (this.flatpickrInstance) {
            this.flatpickrInstance.destroy();
            this.flatpickrInstance = null;
        }
    }
}" x-init="init()" x-destroy="destroy()">
    @if($label)
        <label for="{{ $id }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
            {{ $label }}
            @if ($required)
                <span class="text-error-500">*</span>
            @endif
        </label>
    @endif

    <div class="relative custom-datepicker">
        <input
            x-ref="dateInput"
            type="text"
            id="{{ $id }}"
            name="{{ $name }}"
            value="{{ is_string($defaultDate) ? $defaultDate : '' }}"
            placeholder="{{ $placeholder }}"
            @required($required)
            class="h-11 w-full rounded-lg border appearance-none ltr:pl-4 ltr:pr-11 rtl:pr-4 rtl:pl-11 py-2.5 text-sm shadow-theme-xs placeholder:text-gray-400 focus:outline-hidden focus:ring-3 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 bg-transparent text-gray-800 {{ $borderClass }}"
            autocomplete="off"
        />
        <span class="absolute text-gray-500 -translate-y-1/2 pointer-events-none ltr:right-3.5 rtl:left-3.5 top-1/2 dark:text-gray-400">
            <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" class="size-6">
                <path fill-rule="evenodd" clip-rule="evenodd" d="M8 2C8.41421 2 8.75 2.33579 8.75 2.75V3.75H15.25V2.75C15.25 2.33579 15.5858 2 16 2C16.4142 2 16.75 2.33579 16.75 2.75V3.75H18.5C19.7426 3.75 20.75 4.75736 20.75 6V9V19C20.75 20.2426 19.7426 21.25 18.5 21.25H5.5C4.25736 21.25 3.25 20.2426 3.25 19V9V6C3.25 4.75736 4.25736 3.75 5.5 3.75H7.25V2.75C7.25 2.33579 7.58579 2 8 2ZM8 5.25H5.5C5.08579 5.25 4.75 5.58579 4.75 6V8.25H19.25V6C19.25 5.58579 18.9142 5.25 18.5 5.25H16H8ZM19.25 9.75H4.75V19C4.75 19.4142 5.08579 19.75 5.5 19.75H18.5C18.9142 19.75 19.25 19.4142 19.25 19V9.75Z" fill="currentColor"></path>
            </svg>
        </span>
    </div>
</div>
