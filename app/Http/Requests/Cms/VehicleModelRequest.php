<?php

namespace App\Http\Requests\Cms;

use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class VehicleModelRequest extends FormRequest
{
    public const MAX_NAME_LENGTH = 80;

    /** Batas wajar spesifikasi mobil listrik (juga muat di kolom decimal(5,1) / smallint). */
    public const MAX_BATTERY_KWH = 300;

    public const MAX_AC_KW = 50;

    public const MAX_DC_KW = 1000;

    public const MAX_RANGE_KM = 2000;

    /** Baterai, AC & jangkauan wajib diisi bersamaan; DC opsional (kosong = tidak mendukung DC). */
    private const REQUIRED_SPECS = ['battery_kwh', 'ac_kw', 'range_km'];

    public function authorize(): bool
    {
        $model = $this->vehicleModel();

        return $model
            ? $this->user()->can('update', $model)
            : $this->user()->can('create', VehicleModel::class);
    }

    /**
     * Rapikan nama & terima koma desimal ala Indonesia ("72,6" -> "72.6").
     */
    protected function prepareForValidation(): void
    {
        $data = [];

        if (is_string($name = $this->input('name'))) {
            $data['name'] = preg_replace('/\s+/u', ' ', trim($name));
        }

        foreach (VehicleModel::SPEC_COLUMNS as $field) {
            if (is_string($value = $this->input($field))) {
                $data[$field] = str_replace(',', '.', trim($value));
            }
        }

        $this->merge($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $specsWith = 'required_with:'.implode(',', VehicleModel::SPEC_COLUMNS);

        return [
            'vehicle_brand_id' => ['required', 'integer', Rule::exists(VehicleBrand::class, 'id')],
            'name' => [
                'required', 'string', 'max:'.self::MAX_NAME_LENGTH,
                Rule::unique(VehicleModel::class, 'name')
                    ->where('vehicle_brand_id', (int) $this->input('vehicle_brand_id'))
                    ->ignore($this->vehicleModel()),
            ],
            'is_active' => ['required', 'boolean'],
            'battery_kwh' => ['nullable', $specsWith, 'numeric', 'decimal:0,1', 'gt:0', 'max:'.self::MAX_BATTERY_KWH],
            'ac_kw' => ['nullable', $specsWith, 'numeric', 'decimal:0,1', 'gt:0', 'max:'.self::MAX_AC_KW],
            'dc_kw' => ['nullable', 'numeric', 'decimal:0,1', 'gt:0', 'max:'.self::MAX_DC_KW],
            'range_km' => ['nullable', $specsWith, 'integer', 'min:1', 'max:'.self::MAX_RANGE_KM],
        ];
    }

    /**
     * Tipe yang sudah dipakai kendaraan member tidak boleh dipindah ke merek lain:
     * member_vehicles menyimpan merek & tipe, jadi keduanya bisa jadi tidak cocok.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $model = $this->vehicleModel();

                if (! $model || $validator->errors()->has('vehicle_brand_id')) {
                    return;
                }

                if ((int) $this->input('vehicle_brand_id') !== $model->vehicle_brand_id && $model->memberVehicles()->exists()) {
                    $validator->errors()->add('vehicle_brand_id', 'Merek tidak bisa diubah karena tipe ini sudah dipakai kendaraan member.');
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function modelData(): array
    {
        $data = [
            'vehicle_brand_id' => (int) $this->validated('vehicle_brand_id'),
            'name' => $this->validated('name'),
            'is_active' => (bool) $this->validated('is_active'),
        ];

        foreach (VehicleModel::SPEC_COLUMNS as $field) {
            $value = $this->validated($field);
            $data[$field] = $value === null || $value === '' ? null : $value;
        }

        return $data;
    }

    public function attributes(): array
    {
        return [
            'vehicle_brand_id' => 'merek',
            'name' => 'tipe',
            'is_active' => 'status',
            'battery_kwh' => 'baterai (kWh)',
            'ac_kw' => 'AC maks. (kW)',
            'dc_kw' => 'DC maks. (kW)',
            'range_km' => 'jangkauan (km)',
        ];
    }

    public function messages(): array
    {
        return array_fill_keys(
            array_map(fn ($f) => "{$f}.required_with", self::REQUIRED_SPECS),
            'Baterai, AC maks. & jangkauan wajib diisi lengkap bila salah satu spesifikasi diisi.',
        );
    }

    private function vehicleModel(): ?VehicleModel
    {
        $model = $this->route('vehicle_model');

        return $model instanceof VehicleModel ? $model : null;
    }
}
