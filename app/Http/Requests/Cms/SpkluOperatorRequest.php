<?php

namespace App\Http\Requests\Cms;

use App\Enums\ContentStatus;
use App\Models\SpkluOperator;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SpkluOperatorRequest extends FormRequest
{
    public const MAX_OPERATOR_LENGTH = 150;

    public const MAX_BRAND_LENGTH = 100;

    public const MAX_APP_LENGTH = 100;

    public const MAX_URL_LENGTH = 500;

    public const MAX_LOGO_KB = 2 * 1024;

    /**
     * SVG sengaja tidak diizinkan: file SVG bisa berisi script (stored XSS). Gunakan PNG / WEBP transparan.
     */
    public const LOGO_MIMES = ['image/png', 'image/webp', 'image/jpeg'];

    public const LOGO_EXTENSIONS = ['png', 'webp', 'jpg', 'jpeg'];

    /**
     * Link store hanya boleh mengarah ke domain resmi store: mencegah link phishing / redirect ke situs lain.
     */
    public const STORE_HOSTS = [
        'link_playstore' => ['play.google.com'],
        'link_appstore' => ['apps.apple.com'],
    ];

    public function authorize(): bool
    {
        $operator = $this->operator();

        return $operator
            ? $this->user()->can('update', $operator)
            : $this->user()->can('create', SpkluOperator::class);
    }

    /**
     * Rapikan spasi & lengkapi https:// pada link tanpa skema.
     */
    protected function prepareForValidation(): void
    {
        $data = [];

        foreach (['nama_operator', 'brand_spklu', 'aplikasi'] as $field) {
            if (is_string($value = $this->input($field))) {
                $data[$field] = preg_replace('/\s+/u', ' ', trim($value));
            }
        }

        foreach (array_keys(self::STORE_HOSTS) as $field) {
            $url = $this->input($field);

            if (is_string($url) && ($url = trim($url)) !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
                $url = 'https://'.$url;
            }

            $data[$field] = is_string($url) ? $url : $this->input($field);
        }

        $this->merge($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nama_operator' => ['required', 'string', 'max:'.self::MAX_OPERATOR_LENGTH],
            'brand_spklu' => [
                'required', 'string', 'max:'.self::MAX_BRAND_LENGTH,
                Rule::unique(SpkluOperator::class, 'brand_spklu')->ignore($this->operator()),
            ],
            'aplikasi' => ['required', 'string', 'max:'.self::MAX_APP_LENGTH],
            'logo' => [
                'nullable', // opsional: tanpa logo, website menampilkan nama brand
                'file',
                'mimetypes:'.implode(',', self::LOGO_MIMES),
                'extensions:'.implode(',', self::LOGO_EXTENSIONS),
                'max:'.self::MAX_LOGO_KB,
            ],
            'remove_logo' => ['nullable', 'boolean'],
            'link_playstore' => $this->storeUrlRules('link_playstore'),
            'link_appstore' => $this->storeUrlRules('link_appstore'),
            'status' => ['required', Rule::enum(ContentStatus::class)],
        ];
    }

    /**
     * Data siap simpan (tanpa logo).
     *
     * @return array<string, mixed>
     */
    public function operatorData(): array
    {
        return [
            'nama_operator' => $this->validated('nama_operator'),
            'brand_spklu' => $this->validated('brand_spklu'),
            'aplikasi' => $this->validated('aplikasi'),
            'link_playstore' => $this->validated('link_playstore') ?: null,
            'link_appstore' => $this->validated('link_appstore') ?: null,
            'status' => $this->validated('status'),
        ];
    }

    public function removeLogo(): bool
    {
        return (bool) $this->validated('remove_logo') && ! $this->hasFile('logo');
    }

    public function attributes(): array
    {
        return [
            'nama_operator' => 'nama operator',
            'brand_spklu' => 'brand SPKLU',
            'aplikasi' => 'aplikasi',
            'logo' => 'logo',
            'link_playstore' => 'link Play Store',
            'link_appstore' => 'link App Store',
            'status' => 'status',
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function storeUrlRules(string $field): array
    {
        $hosts = self::STORE_HOSTS[$field];

        return [
            'nullable', 'string', 'max:'.self::MAX_URL_LENGTH,
            'url:https', // hanya https: menolak javascript:, data:, http polos, dll.
            function (string $attribute, mixed $value, Closure $fail) use ($field, $hosts) {
                $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));

                if (! in_array($host, $hosts, true)) {
                    $fail(sprintf('%s harus dari %s, mis. https://%s/...', ucfirst($this->attributes()[$field]), implode(' / ', $hosts), $hosts[0]));
                }
            },
        ];
    }

    private function operator(): ?SpkluOperator
    {
        $operator = $this->route('spklu_operator');

        return $operator instanceof SpkluOperator ? $operator : null;
    }
}
