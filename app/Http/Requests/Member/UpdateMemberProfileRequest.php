<?php

namespace App\Http\Requests\Member;

use App\Models\Member;
use App\Models\MemberVehicle;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Ubah profil oleh member sendiri (multipart, karena ada upload dokumen kendaraan).
 * E-mail & password TIDAK diubah di sini (alur terpisah dengan verifikasi).
 */
class UpdateMemberProfileRequest extends FormRequest
{
    public const VIN_LENGTH = 17;

    public const MAX_DOCUMENTS = 5;

    public const MAX_DOCUMENT_KB = 10240; // 10 MB

    public const MAX_PHOTO_KB = 2048; // 2 MB

    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    protected function prepareForValidation(): void
    {
        $upper = fn (string $key, string $pattern) => $this->filled($key)
            ? strtoupper(preg_replace($pattern, '', (string) $this->input($key)))
            : null;

        $phone = PhoneNumber::normalize($this->input('no_hp'));

        $this->merge([
            // Nomor yang tidak bisa dinormalisasi dibiarkan apa adanya supaya gagal di rule regex
            'no_hp' => $phone ?: $this->input('no_hp'),
            'vin' => $upper('vin', '/\s+/'),
            'plat_kode' => $upper('plat_kode', '/\s+/'),
            'plat_nomor' => $this->filled('plat_nomor') ? preg_replace('/\s+/', '', (string) $this->input('plat_nomor')) : null,
            'plat_seri' => $upper('plat_seri', '/\s+/'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $member = $this->member();
        $vehicleId = $this->vehicle()?->id;

        return [
            'nama_lengkap' => ['required', 'string', 'min:2', 'max:255'],
            'nama_panggilan' => ['nullable', 'string', 'max:60'],
            'tgl_lahir' => ['nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before:today'],
            'jenis_kelamin' => ['nullable', Rule::in(['L', 'P'])],
            'no_hp' => ['required', 'string', 'regex:/^08\d{7,12}$/', Rule::unique('members', 'no_hp')->ignore($member->id)],
            'alamat' => ['nullable', 'string', 'max:500'],

            // Foto profil (opsional). SVG/GIF ditolak; gambar di-encode ulang ke WebP 512px (App\Support\ProfilePhoto).
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_PHOTO_KB,
                'dimensions:min_width=200,min_height=200,max_width=6000,max_height=6000'],
            'remove_photo' => ['nullable', 'boolean'],

            // Wilayah bertingkat: kecamatan harus di kota terpilih, kelurahan di kecamatan terpilih
            'city_code' => ['nullable', 'string', Rule::exists('regencies', 'code')],
            'district_code' => ['nullable', 'string', Rule::exists('districts', 'code')->where('regency_code', (string) $this->input('city_code'))],
            'village_code' => ['nullable', 'string', Rule::exists('villages', 'code')->where('district_code', (string) $this->input('district_code'))],

            'has_vehicle' => ['required', 'boolean'],

            // Kendaraan — hanya divalidasi (dan disimpan) kalau has_vehicle = ya
            'vin' => ['exclude_unless:has_vehicle,1,true', 'required', 'string', 'size:'.self::VIN_LENGTH, 'regex:/^[A-Z0-9]+$/',
                Rule::unique('member_vehicles', 'vin')->ignore($vehicleId)],
            'plat_kode' => ['exclude_unless:has_vehicle,1,true', 'required', 'regex:/^[A-Z]{1,2}$/'],
            'plat_nomor' => ['exclude_unless:has_vehicle,1,true', 'required', 'regex:/^\d{1,4}$/'],
            'plat_seri' => ['exclude_unless:has_vehicle,1,true', 'nullable', 'regex:/^[A-Z]{1,3}$/'],
            'plat_status' => ['exclude_unless:has_vehicle,1,true', 'required', Rule::in(['permanen', 'sementara'])],
            // Merek/tipe yang dinonaktifkan di CMS tetap boleh dipertahankan oleh member yang sudah memakainya,
            // tapi tidak bisa dipilih baru.
            'vehicle_brand_id' => ['exclude_unless:has_vehicle,1,true', 'nullable', 'integer',
                Rule::exists('vehicle_brands', 'id')->where($this->activeOrCurrent($this->vehicle()?->vehicle_brand_id))],
            'vehicle_model_id' => ['exclude_unless:has_vehicle,1,true', 'nullable', 'integer',
                Rule::exists('vehicle_models', 'id')
                    ->where('vehicle_brand_id', (int) $this->input('vehicle_brand_id'))
                    ->where($this->activeOrCurrent($this->vehicle()?->vehicle_model_id))],
            'tahun' => ['exclude_unless:has_vehicle,1,true', 'nullable', 'integer', 'between:1990,'.(now()->year + 1)],
            'documents' => ['exclude_unless:has_vehicle,1,true', 'array', 'max:'.self::MAX_DOCUMENTS],
            'documents.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.self::MAX_DOCUMENT_KB],
            'remove_document_ids' => ['exclude_unless:has_vehicle,1,true', 'array'],
            'remove_document_ids.*' => ['integer'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $this->boolean('has_vehicle') || $validator->errors()->isNotEmpty()) {
                return;
            }

            $vehicle = $this->vehicle();

            // No. plat unik (gabungan kode + nomor + seri)
            $platFull = $this->input('plat_kode').$this->input('plat_nomor').$this->input('plat_seri');
            $taken = MemberVehicle::where('plat_full', $platFull)
                ->when($vehicle, fn ($q) => $q->whereKeyNot($vehicle->id))
                ->exists();
            if ($taken) {
                $validator->errors()->add('plat_nomor', 'No. plat sudah terdaftar atas kendaraan lain.');
            }

            // Minimal 1 dokumen bukti setelah penghapusan & upload baru
            $remaining = $vehicle
                ? $vehicle->documents()->whereNotIn('id', $this->removeDocumentIds())->count()
                : 0;
            if ($remaining + count($this->file('documents', [])) === 0) {
                $validator->errors()->add('documents', 'Unggah minimal satu dokumen bukti (STNK, SPK, atau Invoice).');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => ':Attribute wajib diisi.',
            'nama_lengkap.min' => 'Nama lengkap minimal 2 karakter.',
            'max' => ':Attribute terlalu panjang.',
            'tgl_lahir.date_format' => 'Format tanggal lahir DD / MM / YYYY.',
            'tgl_lahir.after' => 'Tanggal lahir tidak valid.',
            'tgl_lahir.before' => 'Tanggal lahir harus sebelum hari ini.',
            'no_hp.regex' => 'Format No. HP tidak valid (contoh: 081234567890).',
            'no_hp.unique' => 'No. HP sudah dipakai anggota lain.',
            'exists' => ':Attribute tidak valid.',
            'in' => ':Attribute tidak valid.',
            'vin.size' => 'No. rangka harus '.self::VIN_LENGTH.' karakter.',
            'vin.regex' => 'No. rangka hanya boleh huruf dan angka.',
            'vin.unique' => 'No. rangka sudah terdaftar atas kendaraan lain.',
            'plat_kode.regex' => 'Kode wilayah plat 1–2 huruf.',
            'plat_nomor.regex' => 'Nomor plat 1–4 angka.',
            'plat_seri.regex' => 'Huruf belakang plat 1–3 huruf.',
            'tahun.between' => 'Tahun harus antara 1990 dan '.(now()->year + 1).'.',
            'photo.image' => 'Foto profil harus berupa gambar.',
            'photo.mimes' => 'Foto profil harus berupa JPG, PNG, atau WEBP.',
            'photo.max' => 'Ukuran foto profil maksimal 2 MB.',
            'photo.dimensions' => 'Ukuran foto profil minimal 200×200 piksel (maks. 6000×6000).',
            'photo.uploaded' => 'Foto profil gagal diunggah.',
            'documents.max' => 'Maksimal '.self::MAX_DOCUMENTS.' dokumen.',
            'documents.*.mimes' => 'Dokumen harus berupa JPG, PNG, WEBP, atau PDF.',
            'documents.*.max' => 'Ukuran dokumen maksimal 10 MB.',
            'documents.*.file' => 'Dokumen gagal diunggah.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nama_lengkap' => 'nama lengkap',
            'nama_panggilan' => 'nama panggilan',
            'jenis_kelamin' => 'jenis kelamin',
            'no_hp' => 'No. HP',
            'alamat' => 'alamat',
            'city_code' => 'kota',
            'district_code' => 'kecamatan',
            'village_code' => 'kelurahan',
            'has_vehicle' => 'kepemilikan kendaraan',
            'vin' => 'no. rangka',
            'plat_kode' => 'kode wilayah plat',
            'plat_nomor' => 'nomor plat',
            'plat_status' => 'status no. plat',
            'vehicle_brand_id' => 'merek',
            'vehicle_model_id' => 'tipe',
        ];
    }

    public function member(): Member
    {
        return $this->user();
    }

    /** Kendaraan yang diedit dari form: kendaraan utama, atau yang pertama. */
    public function vehicle(): ?MemberVehicle
    {
        return once(fn () => $this->member()->primaryVehicle());
    }

    /** Kondisi exists: aktif, atau id yang sedang dipakai kendaraan member. */
    private function activeOrCurrent(?int $currentId): \Closure
    {
        return fn ($query) => $query->where('is_active', true)
            ->when($currentId, fn ($q) => $q->orWhere('id', $currentId));
    }

    /** @return list<int> */
    public function removeDocumentIds(): array
    {
        return array_map('intval', (array) $this->input('remove_document_ids', []));
    }
}
