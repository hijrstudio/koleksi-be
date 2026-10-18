<?php

namespace App\Http\Requests\Cms;

use App\Models\VehicleBrand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class VehicleBrandRequest extends FormRequest
{
    public const MAX_NAME_LENGTH = 60;

    public const MAX_SORT = 65_535;

    public const MAX_LOGO_KB = 2 * 1024;

    /**
     * SVG sengaja tidak diizinkan: file SVG bisa berisi script (stored XSS). Gunakan PNG / WEBP transparan.
     */
    public const LOGO_MIMES = ['image/png', 'image/webp', 'image/jpeg'];

    public const LOGO_EXTENSIONS = ['png', 'webp', 'jpg', 'jpeg'];

    public function authorize(): bool
    {
        $brand = $this->brand();

        return $brand
            ? $this->user()->can('update', $brand)
            : $this->user()->can('create', VehicleBrand::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($name = $this->input('name'))) {
            $this->merge(['name' => preg_replace('/\s+/u', ' ', trim($name))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:'.self::MAX_NAME_LENGTH,
                Rule::unique(VehicleBrand::class, 'name')->ignore($this->brand()),
            ],
            'logo' => [
                'nullable', // opsional: tanpa logo, website menampilkan nama merek
                'file',
                'mimetypes:'.implode(',', self::LOGO_MIMES),
                'extensions:'.implode(',', self::LOGO_EXTENSIONS),
                'max:'.self::MAX_LOGO_KB,
            ],
            'remove_logo' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_SORT],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * Slug dibentuk dari nama; nama berbeda bisa menghasilkan slug sama ("Mercedes Benz" / "Mercedes-Benz").
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('name')) {
                    return;
                }

                $slug = Str::slug((string) $this->validated('name'));

                if ($slug === '') {
                    $validator->errors()->add('name', 'Nama merek harus mengandung huruf atau angka.');

                    return;
                }

                $taken = VehicleBrand::query()
                    ->where('slug', $slug)
                    ->when($this->brand(), fn ($q, $brand) => $q->whereKeyNot($brand->id))
                    ->exists();

                if ($taken) {
                    $validator->errors()->add('name', 'Nama merek terlalu mirip dengan merek yang sudah ada.');
                }
            },
        ];
    }

    /**
     * Data siap simpan (tanpa logo). Urutan kosong = taruh di paling akhir.
     *
     * @return array<string, mixed>
     */
    public function brandData(): array
    {
        return [
            'name' => $this->validated('name'),
            'sort_order' => $this->validated('sort_order') ?? $this->brand()?->sort_order ?? VehicleBrand::nextSort(),
            'is_active' => (bool) $this->validated('is_active'),
        ];
    }

    public function removeLogo(): bool
    {
        return (bool) $this->validated('remove_logo') && ! $this->hasFile('logo');
    }

    public function attributes(): array
    {
        return [
            'name' => 'nama merek',
            'logo' => 'logo',
            'sort_order' => 'urutan',
            'is_active' => 'status',
        ];
    }

    private function brand(): ?VehicleBrand
    {
        $brand = $this->route('vehicle_brand');

        return $brand instanceof VehicleBrand ? $brand : null;
    }
}
