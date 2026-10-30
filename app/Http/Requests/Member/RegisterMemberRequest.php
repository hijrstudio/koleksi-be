<?php

namespace App\Http\Requests\Member;

use App\Models\Member;
use App\Models\MemberVehicle;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Pendaftaran member baru dari form /daftar (multipart, ada upload dokumen kendaraan).
 * Dipanggil server Next.js (FRONTEND_API_TOKEN); IP pengunjung diteruskan lewat X-Forwarded-For.
 *
 * Keunikan e-mail / No. HP / VIN / plat mengabaikan pendaftaran usang (belum verifikasi / belum bayar / ditolak) —
 * baris itu dihapus oleh MemberRegistrationService saat pendaftaran baru disimpan.
 *
 * Kendaraan: wajib No. plat, merek, dan tipe; No. rangka (VIN) & dokumen bukti opsional.
 */
class RegisterMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $upper = fn (string $key) => $this->filled($key)
            ? strtoupper(preg_replace('/\s+/', '', (string) $this->input($key)))
            : null;

        $phone = PhoneNumber::normalize($this->input('no_hp'));

        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
            'nama_lengkap' => trim((string) $this->input('nama_lengkap')),
            // Nomor yang tidak bisa dinormalisasi dibiarkan apa adanya supaya gagal di rule regex
            'no_hp' => $phone ?: $this->input('no_hp'),
            'vin' => $upper('vin'),
            'plat_kode' => $upper('plat_kode'),
            'plat_nomor' => $this->filled('plat_nomor') ? preg_replace('/\s+/', '', (string) $this->input('plat_nomor')) : null,
            'plat_seri' => $upper('plat_seri'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $notStale = fn ($q) => $q->whereNotIn('registration_status', Member::REG_STALE);

        return [
            'email' => ['required', 'string', 'max:255', 'email:rfc,strict', Rule::unique('members', 'email')->where($notStale)],
            'password' => [
                'required', 'string', 'confirmed', 'max:'.UpdateMemberPasswordRequest::MAX_LENGTH,
                Password::min(UpdateMemberPasswordRequest::MIN_LENGTH)->mixedCase()->numbers()->uncompromised(),
            ],

            'nama_lengkap' => ['required', 'string', 'min:2', 'max:255'],
            'nama_panggilan' => ['nullable', 'string', 'max:60'],
            'tgl_lahir' => ['nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before:today'],
            'jenis_kelamin' => ['nullable', Rule::in(['L', 'P'])],
            'no_hp' => ['required', 'string', 'regex:/^08\d{7,12}$/', Rule::unique('members', 'no_hp')->where($notStale)],
            'alamat' => ['nullable', 'string', 'max:500'],

            // Wilayah bertingkat: kecamatan harus di kota terpilih, kelurahan di kecamatan terpilih
            'city_code' => ['nullable', 'string', Rule::exists('regencies', 'code')],
            'district_code' => ['nullable', 'string', Rule::exists('districts', 'code')->where('regency_code', (string) $this->input('city_code'))],
            'village_code' => ['nullable', 'string', Rule::exists('villages', 'code')->where('district_code', (string) $this->input('district_code'))],

            'has_vehicle' => ['required', 'boolean'],
            'vin' => ['exclude_unless:has_vehicle,1,true', 'nullable', 'string', 'size:'.UpdateMemberProfileRequest::VIN_LENGTH, 'regex:/^[A-Z0-9]+$/'],
            'plat_kode' => ['exclude_unless:has_vehicle,1,true', 'required', 'regex:/^[A-Z]{1,2}$/'],
            'plat_nomor' => ['exclude_unless:has_vehicle,1,true', 'required', 'regex:/^\d{1,4}$/'],
            'plat_seri' => ['exclude_unless:has_vehicle,1,true', 'nullable', 'regex:/^[A-Z]{1,3}$/'],
            'plat_status' => ['exclude_unless:has_vehicle,1,true', 'required', Rule::in(['permanen', 'sementara'])],
            'vehicle_brand_id' => ['exclude_unless:has_vehicle,1,true', 'required', 'integer',
                Rule::exists('vehicle_brands', 'id')->where('is_active', true)],
            'vehicle_model_id' => ['exclude_unless:has_vehicle,1,true', 'required', 'integer',
                Rule::exists('vehicle_models', 'id')->where('is_active', true)->where('vehicle_brand_id', (int) $this->input('vehicle_brand_id'))],
            'tahun' => ['exclude_unless:has_vehicle,1,true', 'nullable', 'integer', 'between:1990,'.(now()->year + 1)],
            'documents' => ['exclude_unless:has_vehicle,1,true', 'nullable', 'array', 'max:'.UpdateMemberProfileRequest::MAX_DOCUMENTS],
            'documents.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.UpdateMemberProfileRequest::MAX_DOCUMENT_KB],

            'heard_from' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $this->boolean('has_vehicle') || $validator->errors()->isNotEmpty()) {
                return;
            }

            // Kendaraan milik anggota / pendaftar aktif lain (termasuk anggota yang dihapus) tidak boleh didaftarkan ulang
            $taken = fn (string $column, string $value) => MemberVehicle::query()
                ->where($column, $value)
                ->whereHas('member', fn (Builder $q) => $q->withTrashed()->whereNotIn('registration_status', Member::REG_STALE))
                ->exists();

            if ($this->filled('vin') && $taken('vin', (string) $this->input('vin'))) {
                $validator->errors()->add('vin', 'No. rangka sudah terdaftar atas kendaraan lain.');
            }
            if ($taken('plat_full', $this->input('plat_kode').$this->input('plat_nomor').$this->input('plat_seri'))) {
                $validator->errors()->add('plat_nomor', 'No. plat sudah terdaftar atas kendaraan lain.');
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
            'max' => ':Attribute terlalu panjang.',
            'exists' => ':Attribute tidak valid.',
            'in' => ':Attribute tidak valid.',
            'email.email' => 'Format e-mail tidak valid.',
            'email.unique' => 'E-mail sudah terdaftar. Silakan masuk atau gunakan e-mail lain.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak sama.',
            'password.min' => 'Kata sandi minimal :min karakter.',
            'password.mixed' => 'Kata sandi harus mengandung huruf besar dan huruf kecil.',
            'password.numbers' => 'Kata sandi harus mengandung angka.',
            'password.uncompromised' => 'Kata sandi ini pernah bocor di internet. Gunakan kata sandi lain.',
            'nama_lengkap.min' => 'Nama lengkap minimal 2 karakter.',
            'tgl_lahir.date_format' => 'Format tanggal lahir DD / MM / YYYY.',
            'tgl_lahir.after' => 'Tanggal lahir tidak valid.',
            'tgl_lahir.before' => 'Tanggal lahir harus sebelum hari ini.',
            'no_hp.regex' => 'Format No. HP tidak valid (contoh: 081234567890).',
            'no_hp.unique' => 'No. HP sudah dipakai anggota lain.',
            'vin.size' => 'No. rangka harus '.UpdateMemberProfileRequest::VIN_LENGTH.' karakter.',
            'vin.regex' => 'No. rangka hanya boleh huruf dan angka.',
            'plat_kode.regex' => 'Kode wilayah plat 1–2 huruf.',
            'plat_nomor.regex' => 'Nomor plat 1–4 angka.',
            'plat_seri.regex' => 'Huruf belakang plat 1–3 huruf.',
            'tahun.between' => 'Tahun harus antara 1990 dan '.(now()->year + 1).'.',
            'documents.max' => 'Maksimal '.UpdateMemberProfileRequest::MAX_DOCUMENTS.' dokumen.',
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
            'email' => 'e-mail',
            'password' => 'kata sandi',
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
            'heard_from' => 'sumber informasi',
        ];
    }
}
