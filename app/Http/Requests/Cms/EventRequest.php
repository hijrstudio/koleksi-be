<?php

namespace App\Http\Requests\Cms;

use App\Enums\ContentStatus;
use App\Models\Event;
use App\Support\GeoCoordinates;
use App\Support\RichTextSanitizer;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EventRequest extends FormRequest
{
    public const MAX_TITLE_LENGTH = 255;

    public const MAX_LOCATION_LENGTH = 255;

    public const MAX_IMAGE_KB = 5 * 1024;

    /** SVG sengaja tidak diizinkan (bisa berisi script / XSS) */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** Panjang maksimal HTML (setelah disanitasi) per bahasa */
    public const MAX_HTML_LENGTH = 50_000;

    /** Field json {"id","en"} yang diisi lewat rich text editor */
    public const RICH_TEXT_FIELDS = ['description', 'registration_info'];

    public function authorize(): bool
    {
        $event = $this->event();

        return $event
            ? $this->user()->can('update', $event)
            : $this->user()->can('create', Event::class);
    }

    /**
     * Rich text disanitasi & slug dinormalisasi sebelum validasi.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];
        $sanitizer = app(RichTextSanitizer::class);

        foreach (self::RICH_TEXT_FIELDS as $field) {
            $value = $this->input($field);

            if (! is_array($value)) {
                continue; // dibiarkan, akan ditolak oleh rule "array"
            }

            foreach (['id', 'en'] as $locale) {
                if (is_string($value[$locale] ?? null)) {
                    $value[$locale] = $sanitizer->clean($value[$locale]);
                }
            }

            $merge[$field] = $value;
        }

        // Slug otomatis (slug_auto) diberi akhiran -2, -3, ... bila bentrok; slug manual divalidasi unik.
        $slug = $this->input('slug');

        if (is_string($slug)) {
            $slug = trim(Str::slug($slug), '-');

            if ($slug !== '' && $this->boolean('slug_auto')) {
                $slug = Event::uniqueSlug($slug, $this->event()?->id);
            }

            $merge['slug'] = $slug === '' ? null : $slug;
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event_category_id' => ['required', 'integer', Rule::exists('event_categories', 'id')],
            // Bahasa Indonesia wajib; EN boleh kosong (fallback ke ID di website)
            'title' => ['required', 'array:id,en'],
            'title.id' => ['required', 'string', 'max:'.self::MAX_TITLE_LENGTH],
            'title.en' => ['nullable', 'string', 'max:'.self::MAX_TITLE_LENGTH],
            'slug' => [
                'nullable', 'string', 'max:'.Event::MAX_SLUG_LENGTH, 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('events', 'slug')->ignore($this->event()),
            ],
            'slug_auto' => ['nullable', 'boolean'],
            'image' => [
                'nullable', 'file',
                'mimetypes:'.implode(',', self::IMAGE_MIMES),
                'extensions:'.implode(',', self::IMAGE_EXTENSIONS),
                'max:'.self::MAX_IMAGE_KB,
            ],
            'remove_image' => ['nullable', 'boolean'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            // Kosong = event satu hari (sama dengan tanggal mulai)
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'description' => ['required', 'array:id,en'],
            'description.id' => ['required', 'string', 'max:'.self::MAX_HTML_LENGTH],
            'description.en' => ['nullable', 'string', 'max:'.self::MAX_HTML_LENGTH],
            'location' => ['required', 'string', 'max:'.self::MAX_LOCATION_LENGTH],
            // Link Google Maps atau koordinat "lat, lng"; yang disimpan hanya titik koordinatnya
            'location_gmap' => [
                'nullable', 'string', 'max:2000',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (GeoCoordinates::parse($value) === null) {
                        $fail('Titik lokasi tidak dikenali. Tempel link Google Maps lengkap (bukan link pendek maps.app.goo.gl) atau koordinat, mis. -6.175392, 106.827153.');
                    }
                },
            ],
            'registration_info' => ['nullable', 'array:id,en'],
            'registration_info.id' => ['nullable', 'string', 'max:'.self::MAX_HTML_LENGTH],
            'registration_info.en' => ['nullable', 'string', 'max:'.self::MAX_HTML_LENGTH],
            'status' => ['required', Rule::enum(ContentStatus::class)],
        ];
    }

    /**
     * Data event siap simpan (tanpa slug).
     *
     * @return array<string, mixed>
     */
    public function eventData(): array
    {
        $translations = function (string $field): ?array {
            $value = [
                'id' => filled($this->validated("$field.id")) ? trim($this->validated("$field.id")) : null,
                'en' => filled($this->validated("$field.en")) ? trim($this->validated("$field.en")) : null,
            ];

            return array_filter($value) === [] ? null : $value;
        };

        $point = GeoCoordinates::parse($this->validated('location_gmap'));

        return [
            'event_category_id' => (int) $this->validated('event_category_id'),
            'title' => $translations('title'),
            'start_date' => $this->validated('start_date'),
            'end_date' => $this->validated('end_date') ?: $this->validated('start_date'),
            'description' => $translations('description'),
            'location' => trim($this->validated('location')),
            'latitude' => $point['lat'] ?? null,
            'longitude' => $point['lng'] ?? null,
            'registration_info' => $translations('registration_info'),
            'status' => $this->validated('status'),
        ];
    }

    public function attributes(): array
    {
        return [
            'event_category_id' => 'kategori',
            'title.id' => 'judul (ID)',
            'title.en' => 'judul (EN)',
            'slug' => 'slug',
            'image' => 'gambar',
            'start_date' => 'tanggal mulai',
            'end_date' => 'tanggal selesai',
            'description.id' => 'deskripsi (ID)',
            'description.en' => 'deskripsi (EN)',
            'location' => 'lokasi',
            'location_gmap' => 'titik Google Maps',
            'registration_info.id' => 'info pendaftaran (ID)',
            'registration_info.en' => 'info pendaftaran (EN)',
            'status' => 'status',
        ];
    }

    public function messages(): array
    {
        return [
            'slug.unique' => 'Slug ini sudah dipakai event lain.',
            'slug.regex' => 'Slug hanya boleh huruf kecil, angka, dan tanda hubung (-).',
            'end_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
        ];
    }

    private function event(): ?Event
    {
        $event = $this->route('event');

        return $event instanceof Event ? $event : null;
    }
}
