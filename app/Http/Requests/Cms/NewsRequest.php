<?php

namespace App\Http\Requests\Cms;

use App\Enums\NewsCategory;
use App\Models\News;
use Illuminate\Validation\Rule;

/**
 * News Release & Liputan: artikel KOLEKSI (konten + penulis admin/member).
 * Berita EV (NewsCategory::isExternal()): tautan ke berita di media lain — judul, ringkasan, link, nama media,
 * penulis (teks bebas) dan tanggal berita; tanpa konten. Penulis internal tetap admin yang login (tidak tampil).
 */
class NewsRequest extends AuthoredContentRequest
{
    public const MAX_SUMMARY_LENGTH = 1000;

    public const MAX_SOURCE_URL_LENGTH = 500;

    public const MAX_SOURCE_NAME_LENGTH = 150;

    protected function modelClass(): string
    {
        return News::class;
    }

    protected function routeParameter(): string
    {
        return 'news';
    }

    /** Kategori yang dipilih adalah berita dari media lain. */
    public function isExternal(): bool
    {
        return NewsCategory::tryFrom((string) $this->input('category'))?->isExternal() ?? false;
    }

    /**
     * Link tanpa skema (mis. "kompas.com/...") dilengkapi https:// agar admin tidak perlu mengetiknya.
     */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $url = $this->input('source_url');

        if (is_string($url) && ($url = trim($url)) !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $this->merge(['source_url' => 'https://'.$url]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        if (! $this->isExternal()) {
            return $rules;
        }

        // Berita EV: konten & pilihan penulis tidak dipakai (field-nya disembunyikan di form)
        foreach (['content', 'content.id', 'content.en', 'author_type', 'author_member_id'] as $field) {
            $rules[$field] = ['exclude'];
        }

        return [
            ...$rules,
            'summary' => ['required', 'array:id,en'],
            'summary.id' => ['required', 'string', 'max:'.self::MAX_SUMMARY_LENGTH],
            'summary.en' => ['nullable', 'string', 'max:'.self::MAX_SUMMARY_LENGTH],
            // Hanya http/https: mencegah link javascript:, data:, dll. di website
            'source_url' => ['required', 'string', 'max:'.self::MAX_SOURCE_URL_LENGTH, 'url:http,https'],
            'source_name' => ['required', 'string', 'max:'.self::MAX_SOURCE_NAME_LENGTH],
            'source_author' => ['nullable', 'string', 'max:'.self::MAX_SOURCE_NAME_LENGTH],
            'source_published_on' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    protected function extraRules(): array
    {
        return [
            'category' => ['required', Rule::enum(NewsCategory::class)],
        ];
    }

    /**
     * Kolom sumber selalu ikut disimpan: kosong untuk News Release & Liputan, supaya data lama
     * tidak tertinggal saat kategori diganti.
     */
    protected function extraData(): array
    {
        $external = $this->isExternal();
        $text = fn (string $field) => $external && filled($this->validated($field)) ? trim($this->validated($field)) : null;

        return [
            'category' => $this->validated('category'),
            'summary' => $external ? [
                'id' => trim($this->validated('summary.id')),
                'en' => $text('summary.en'),
            ] : null,
            'source_url' => $text('source_url'),
            'source_name' => $text('source_name'),
            'source_author' => $text('source_author'),
            'source_published_on' => $external ? $this->validated('source_published_on') : null,
        ];
    }

    /**
     * Berita EV tidak punya konten.
     *
     * @return array<string, mixed>
     */
    public function contentData(): array
    {
        if (! $this->isExternal()) {
            return parent::contentData();
        }

        return [
            ...$this->extraData(),
            'title' => [
                'id' => trim($this->validated('title.id')),
                'en' => filled($this->validated('title.en')) ? trim($this->validated('title.en')) : null,
            ],
            'content' => null,
            'status' => $this->validated('status'),
        ];
    }

    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'category' => 'kategori',
            'summary.id' => 'ringkasan (ID)',
            'summary.en' => 'ringkasan (EN)',
            'source_url' => 'link berita',
            'source_name' => 'media',
            'source_author' => 'penulis',
            'source_published_on' => 'tanggal berita',
        ];
    }
}
