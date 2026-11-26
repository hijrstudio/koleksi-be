<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Merek & tipe kendaraan: pilih dari katalog (vehicle_brand_id / vehicle_model_id) ATAU tulis sendiri kalau belum ada
 * di katalog (brand_other / model_other). Dipakai form daftar, ubah profil member & form member CMS.
 *
 *   merek katalog + tipe katalog → vehicle_brand_id + vehicle_model_id
 *   merek katalog + tipe lain    → vehicle_brand_id + model_other
 *   merek lain                   → brand_other + model_other (tipe dari merek lain pasti belum di katalog)
 *
 * Teks bebas TIDAK pernah masuk katalog otomatis: admin menautkannya ke merek/tipe resmi di CMS
 * (Kendaraan di Luar Katalog).
 */
class VehicleCatalogInput
{
    public const BRAND_MAX = 60;   // = kolom member_vehicles.brand_other

    public const MODEL_MAX = 80;   // = kolom member_vehicles.model_other

    /** Huruf/angka (semua bahasa) + spasi & tanda baca umum nama kendaraan: "BYD Atto 1", "Mercedes-Benz EQA 250+". */
    public const PATTERN = "/^[\pL\pN][\pL\pN .,\-\/()+&']*$/u";

    /** "  byd   Atto 1 " → "byd Atto 1"; kosong → null. */
    public static function clean(mixed $value): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');

        return $text === '' ? null : $text;
    }

    /**
     * Rapikan input sebelum validasi: teks bebas dibersihkan; pilihan katalog menang atas teks bebas
     * (teks yang tertinggal di form diabaikan), dan merek lain selalu berpasangan dengan tipe lain.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $brandOther = self::clean($input['brand_other'] ?? null);
        $modelOther = self::clean($input['model_other'] ?? null);
        $brandId = filled($input['vehicle_brand_id'] ?? null) ? $input['vehicle_brand_id'] : null;
        $modelId = filled($input['vehicle_model_id'] ?? null) ? $input['vehicle_model_id'] : null;

        if ($brandId !== null) {
            $brandOther = null;
        } elseif ($brandOther !== null) {
            $modelId = null;
        }
        if ($modelId !== null) {
            $modelOther = null;
        }

        return [
            'vehicle_brand_id' => $brandId,
            'vehicle_model_id' => $modelId,
            'brand_other' => $brandOther,
            'model_other' => $modelOther,
        ];
    }

    /**
     * Rule teks bebas. $required = merek & tipe wajib (form daftar): pilih dari katalog atau tulis sendiri.
     *
     * $request = FormRequest yang sudah di-normalize() (bukan request() global: itu masih input mentah).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(Request $request, bool $required): array
    {
        $onlyWithVehicle = 'exclude_unless:has_vehicle,1,true';

        return [
            'brand_other' => [$onlyWithVehicle, $required ? 'required_without:vehicle_brand_id' : 'nullable',
                'nullable', 'string', 'max:'.self::BRAND_MAX, 'regex:'.self::PATTERN],
            'model_other' => [$onlyWithVehicle,
                // Merek lain → tipe wajib ditulis; merek katalog (form daftar) → tipe katalog atau ditulis
                Rule::requiredIf(fn () => $request->filled('brand_other')
                    || ($required && $request->filled('vehicle_brand_id') && blank($request->input('vehicle_model_id')))),
                'nullable', 'string', 'max:'.self::MODEL_MAX, 'regex:'.self::PATTERN],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'brand_other.required_without' => 'Pilih merek, atau tulis merek kalau belum ada di daftar.',
            'brand_other.max' => 'Merek maksimal '.self::BRAND_MAX.' karakter.',
            'brand_other.regex' => 'Merek hanya boleh huruf, angka, spasi, dan tanda baca umum.',
            'model_other.required' => 'Tulis tipe kendaraan.',
            'model_other.max' => 'Tipe maksimal '.self::MODEL_MAX.' karakter.',
            'model_other.regex' => 'Tipe hanya boleh huruf, angka, spasi, dan tanda baca umum.',
        ];
    }
}
