<?php

namespace App\Http\Requests\Cms;

use App\Enums\ContentStatus;
use App\Models\Member;
use App\Support\ContentImages;
use App\Support\RichTextSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validasi bersama untuk konten bertulis (artikel panduan, berita, ...):
 * title & content bilingual, slug unik, gambar utama, status, dan penulis member / admin.
 *
 * Subclass cukup menentukan model, nama parameter route, dan field tambahan.
 */
abstract class AuthoredContentRequest extends FormRequest
{
    public const MAX_TITLE_LENGTH = 255;

    /** Panjang maksimal HTML konten (setelah disanitasi) per bahasa */
    public const MAX_CONTENT_LENGTH = 100_000;

    public const MAX_IMAGE_KB = 5 * 1024;

    /** SVG sengaja tidak diizinkan (bisa berisi script / XSS) */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** Gambar di dalam konten (rich editor) */
    public const MAX_CONTENT_IMAGE_KB = ContentImages::MAX_KB;

    public const CONTENT_IMAGE_MIMES = ContentImages::MIMES;

    public const CONTENT_IMAGE_EXTENSIONS = ContentImages::EXTENSIONS;

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** Nama parameter route model, mis. "guide_article" */
    abstract protected function routeParameter(): string;

    /**
     * Rules tambahan khusus modul (mis. kategori).
     *
     * @return array<string, mixed>
     */
    abstract protected function extraRules(): array;

    /**
     * Data tambahan khusus modul untuk disimpan.
     *
     * @return array<string, mixed>
     */
    abstract protected function extraData(): array;

    public function authorize(): bool
    {
        $model = $this->editedModel();

        return $model
            ? $this->user()->can('update', $model)
            : $this->user()->can('create', $this->modelClass());
    }

    /**
     * Data yang sedang diedit (null saat create).
     */
    protected function editedModel(): ?Model
    {
        $model = $this->route($this->routeParameter());
        $class = $this->modelClass();

        return $model instanceof $class ? $model : null;
    }

    /**
     * Konten disanitasi sebelum validasi, sehingga aturan required/max berlaku pada HTML yang benar-benar disimpan.
     */
    protected function prepareForValidation(): void
    {
        $this->prepareSlug();

        $content = $this->input('content');

        if (! is_array($content)) {
            return; // dibiarkan, akan ditolak oleh rule "array"
        }

        $sanitizer = app(RichTextSanitizer::class);

        foreach (['id', 'en'] as $locale) {
            if (is_string($content[$locale] ?? null)) {
                $content[$locale] = $sanitizer->clean($content[$locale], allowImages: true);
            }
        }

        $this->merge(['content' => $content]);
    }

    /**
     * Slug dinormalisasi (huruf kecil, tanda hubung). Slug yang masih otomatis dari judul (slug_auto)
     * diberi akhiran -2, -3, ... bila bentrok; slug ketikan manual divalidasi unik agar user sadar.
     * Slug kosong: dibuat dari judul saat create, slug lama dipertahankan saat edit.
     */
    private function prepareSlug(): void
    {
        $slug = $this->input('slug');

        if (! is_string($slug)) {
            return; // null dibiarkan; selain string akan ditolak oleh rule "string"
        }

        $slug = trim(Str::slug($slug), '-');

        if ($slug !== '' && $this->boolean('slug_auto')) {
            $slug = $this->modelClass()::uniqueSlug($slug, $this->editedModel()?->getKey());
        }

        $this->merge(['slug' => $slug === '' ? null : $slug]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $class = $this->modelClass();

        return [
            ...$this->extraRules(),
            // Bahasa Indonesia wajib; EN boleh kosong (fallback ke ID di website)
            'title' => ['required', 'array:id,en'],
            'title.id' => ['required', 'string', 'max:'.self::MAX_TITLE_LENGTH],
            'title.en' => ['nullable', 'string', 'max:'.self::MAX_TITLE_LENGTH],
            'slug' => [
                'nullable', 'string', 'max:'.$class::MAX_SLUG_LENGTH, 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique((new $class)->getTable(), 'slug')->ignore($this->editedModel()),
            ],
            'slug_auto' => ['nullable', 'boolean'],
            'content' => ['required', 'array:id,en'],
            'content.id' => ['required', 'string', 'max:'.self::MAX_CONTENT_LENGTH],
            'content.en' => ['nullable', 'string', 'max:'.self::MAX_CONTENT_LENGTH],
            'image' => [
                'nullable', 'file',
                'mimetypes:'.implode(',', self::IMAGE_MIMES),
                'extensions:'.implode(',', self::IMAGE_EXTENSIONS),
                'max:'.self::MAX_IMAGE_KB,
            ],
            'remove_image' => ['nullable', 'boolean'],
            'status' => ['required', Rule::enum(ContentStatus::class)],

            'author_type' => ['required', Rule::in([$class::AUTHOR_USER, $class::AUTHOR_MEMBER])],
            // Hanya dibaca bila penulisnya member; member yang sudah dihapus / pendaftar yang belum disetujui tidak bisa dipilih
            'author_member_id' => [
                'exclude_unless:author_type,'.$class::AUTHOR_MEMBER,
                'required', 'integer',
                Rule::exists('members', 'id')->whereNull('deleted_at')->where('registration_status', Member::REG_APPROVED),
            ],
        ];
    }

    /**
     * Data siap simpan (tanpa slug, gambar & penulis yang diatur controller).
     *
     * @return array<string, mixed>
     */
    public function contentData(): array
    {
        $translations = fn (string $field) => [
            'id' => trim($this->validated("$field.id")),
            'en' => filled($this->validated("$field.en")) ? trim($this->validated("$field.en")) : null,
        ];

        return [
            ...$this->extraData(),
            'title' => $translations('title'),
            'content' => $translations('content'),
            'status' => $this->validated('status'),
        ];
    }

    public function attributes(): array
    {
        return [
            'title.id' => 'judul (ID)',
            'title.en' => 'judul (EN)',
            'content.id' => 'konten (ID)',
            'content.en' => 'konten (EN)',
            'slug' => 'slug',
            'image' => 'gambar',
            'status' => 'status',
            'author_type' => 'penulis',
            'author_member_id' => 'member penulis',
        ];
    }

    public function messages(): array
    {
        return [
            'slug.unique' => 'Slug ini sudah dipakai data lain.',
            'slug.regex' => 'Slug hanya boleh huruf kecil, angka, dan tanda hubung (-).',
        ];
    }
}
