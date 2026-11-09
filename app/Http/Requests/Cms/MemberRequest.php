<?php

namespace App\Http\Requests\Cms;

use App\Http\Controllers\Cms\MemberController;
use App\Http\Requests\Member\UpdateMemberPasswordRequest;
use App\Http\Requests\Member\UpdateMemberProfileRequest;
use App\Models\Member;
use App\Models\MemberVehicle;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Tambah / edit member oleh admin CMS. Status keanggotaan & foto profil TIDAK diubah di sini (status lewat
 * MembershipService, foto oleh member sendiri). Kata sandi: dikirim link atur kata sandi ke e-mail member, atau
 * dibuatkan admin (sementara: member diingatkan di dasbor untuk menggantinya).
 */
class MemberRequest extends FormRequest
{
    /** Iuran periode pertama saat admin menambah member. */
    public const FIRST_DUE_PAID = 'paid';

    public const FIRST_DUE_UNPAID = 'unpaid';

    public const FIRST_DUE_WAIVED = 'waived';

    public const FIRST_DUE_OPTIONS = [
        self::FIRST_DUE_PAID => 'Lunas (dibayar di luar website)',
        self::FIRST_DUE_UNPAID => 'Belum bayar (bayar sendiri lewat website)',
        self::FIRST_DUE_WAIVED => 'Dibebaskan',
    ];

    /** Kunci = nilai enum member_dues.payment_method. */
    public const PAYMENT_METHODS = ['transfer' => 'Transfer', 'cash' => 'Tunai'];

    public const MAX_NOTES = 1000;

    /** Kata sandi member. */
    public const PASSWORD_KEEP = 'keep';

    public const PASSWORD_EMAIL = 'email';

    public const PASSWORD_ADMIN = 'admin';

    public const PASSWORD_OPTIONS = [
        self::PASSWORD_KEEP => 'Tidak diubah',
        self::PASSWORD_EMAIL => 'Kirim link atur kata sandi ke e-mail member',
        self::PASSWORD_ADMIN => 'Dibuatkan admin (kata sandi sementara, member diminta menggantinya)',
    ];

    /** Pilihan kata sandi yang tersedia: tambah member tanpa "tidak diubah". */
    public static function passwordOptions(bool $creating): array
    {
        return $creating ? array_diff_key(self::PASSWORD_OPTIONS, [self::PASSWORD_KEEP => true]) : self::PASSWORD_OPTIONS;
    }

    public function authorize(): bool
    {
        $member = $this->member();

        return $member ? $this->user()->can('update', $member) : $this->user()->can('create', Member::class);
    }

    protected function prepareForValidation(): void
    {
        $upper = fn (string $key) => $this->filled($key) ? strtoupper(preg_replace('/\s+/', '', (string) $this->input($key))) : null;
        $phone = PhoneNumber::normalize($this->input('no_hp'));

        $this->merge([
            'email' => $this->filled('email') ? mb_strtolower(trim((string) $this->input('email'))) : null,
            // Nomor yang tidak bisa dinormalisasi dibiarkan apa adanya supaya gagal di rule regex
            'no_hp' => $phone ?: ($this->filled('no_hp') ? $this->input('no_hp') : null),
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
        $member = $this->member();
        $vehicle = $this->vehicle();
        $creating = $member === null;

        return [
            // Kosong saat tambah = nomor kosong berikutnya (NoLambungAllocator). Unik termasuk member yang dihapus.
            'no_lambung' => [$creating ? 'nullable' : 'required', 'integer', 'min:1', 'max:'.MemberController::MAX_NO_LAMBUNG],
            'nama_lengkap' => ['required', 'string', 'min:2', 'max:255'],
            'nama_panggilan' => ['nullable', 'string', 'max:60'],
            // E-mail & No. HP opsional (member lama banyak yang tanpa kontak), tapi unik termasuk member yang dihapus
            'email' => ['nullable', 'string', 'email:rfc', 'max:255', Rule::unique('members', 'email')->ignore($member?->id)],
            'no_hp' => ['nullable', 'string', 'regex:/^08\d{7,12}$/', Rule::unique('members', 'no_hp')->ignore($member?->id)],
            'tgl_lahir' => ['nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before:today'],
            'jenis_kelamin' => ['nullable', Rule::in(['L', 'P'])],
            'alamat' => ['nullable', 'string', 'max:500'],
            'city_code' => ['nullable', 'string', Rule::exists('regencies', 'code')],
            'district_code' => ['nullable', 'string', Rule::exists('districts', 'code')->where('regency_code', (string) $this->input('city_code'))],
            'village_code' => ['nullable', 'string', Rule::exists('villages', 'code')->where('district_code', (string) $this->input('district_code'))],
            // Patokan periode iuran; tidak boleh di masa depan (periode belum mulai)
            'tgl_bergabung' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:'.self::MAX_NOTES],

            'has_vehicle' => ['required', 'boolean'],
            'vin' => ['exclude_unless:has_vehicle,1,true', 'nullable', 'string', 'size:'.UpdateMemberProfileRequest::VIN_LENGTH,
                'regex:/^[A-Z0-9]+$/', Rule::unique('member_vehicles', 'vin')->ignore($vehicle?->id)],
            'plat_kode' => ['exclude_unless:has_vehicle,1,true', 'required', 'regex:/^[A-Z]{1,2}$/'],
            'plat_nomor' => ['exclude_unless:has_vehicle,1,true', 'required', 'regex:/^\d{1,4}$/'],
            'plat_seri' => ['exclude_unless:has_vehicle,1,true', 'nullable', 'regex:/^[A-Z]{1,3}$/'],
            'plat_status' => ['exclude_unless:has_vehicle,1,true', 'required', Rule::in(['permanen', 'sementara'])],
            // Merek/tipe nonaktif boleh dipertahankan kendaraan yang sudah memakainya, tapi tidak bisa dipilih baru
            'vehicle_brand_id' => ['exclude_unless:has_vehicle,1,true', 'nullable', 'integer',
                Rule::exists('vehicle_brands', 'id')->where($this->activeOrCurrent($vehicle?->vehicle_brand_id))],
            'vehicle_model_id' => ['exclude_unless:has_vehicle,1,true', 'nullable', 'integer',
                Rule::exists('vehicle_models', 'id')
                    ->where('vehicle_brand_id', (int) $this->input('vehicle_brand_id'))
                    ->where($this->activeOrCurrent($vehicle?->vehicle_model_id))],
            'tahun' => ['exclude_unless:has_vehicle,1,true', 'nullable', 'integer', 'between:1990,'.(now()->year + 1)],
            'warna' => ['exclude_unless:has_vehicle,1,true', 'nullable', 'string', 'max:30'],

            // Hanya saat tambah member
            'first_due' => [Rule::excludeIf(! $creating), 'required', Rule::in(array_keys(self::FIRST_DUE_OPTIONS))],
            'payment_method' => [Rule::excludeIf(! $creating), 'exclude_unless:first_due,'.self::FIRST_DUE_PAID, 'required',
                Rule::in(array_keys(self::PAYMENT_METHODS))],

            'password_mode' => ['required', Rule::in(array_keys(self::passwordOptions($creating)))],
            // Aturan sama dengan kata sandi buatan member (tanpa cek kebocoran online: kata sandi ini sementara)
            'password' => ['exclude_unless:password_mode,'.self::PASSWORD_ADMIN, 'required', 'string', 'confirmed', 'max:72',
                Password::min(UpdateMemberPasswordRequest::MIN_LENGTH)->mixedCase()->numbers()],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $member = $this->member();

            if ($this->input('password_mode') === self::PASSWORD_EMAIL && blank($this->input('email'))) {
                $validator->errors()->add('password_mode', 'Link atur kata sandi butuh e-mail member. Isi e-mail atau pilih "dibuatkan admin".');
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // Periode iuran dihitung dari tanggal bergabung: kalau sudah ada tagihan, mengubahnya membuat periode
            // tagihan lama tidak lagi cocok dengan periode keanggotaan.
            if ($member && $this->input('tgl_bergabung') !== $member->tgl_bergabung?->toDateString()
                && $member->dues()->exists()) {
                $validator->errors()->add('tgl_bergabung', 'Tanggal bergabung tidak bisa diubah karena member sudah punya tagihan iuran.');
            }

            if ($this->boolean('has_vehicle')) {
                $platFull = $this->input('plat_kode').$this->input('plat_nomor').$this->input('plat_seri');
                $vehicle = $this->vehicle();
                $taken = MemberVehicle::where('plat_full', $platFull)
                    ->when($vehicle, fn ($q) => $q->whereKeyNot($vehicle->id))
                    ->exists();
                if ($taken) {
                    $validator->errors()->add('plat_nomor', 'No. plat sudah terdaftar atas kendaraan lain.');
                }
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
            'no_lambung.min' => 'No. lambung minimal 1.',
            'no_lambung.max' => 'No. lambung maksimal '.MemberController::MAX_NO_LAMBUNG.'.',
            'nama_lengkap.min' => 'Nama lengkap minimal 2 karakter.',
            'email.email' => 'Format e-mail tidak valid.',
            'email.unique' => 'E-mail sudah dipakai member lain (termasuk member yang dihapus).',
            'no_hp.regex' => 'Format No. HP tidak valid (contoh: 081234567890).',
            'no_hp.unique' => 'No. HP sudah dipakai member lain (termasuk member yang dihapus).',
            'tgl_lahir.before' => 'Tanggal lahir harus sebelum hari ini.',
            'tgl_bergabung.before_or_equal' => 'Tanggal bergabung tidak boleh di masa depan.',
            'date_format' => 'Format :attribute tidak valid.',
            'vin.size' => 'No. rangka harus '.UpdateMemberProfileRequest::VIN_LENGTH.' karakter.',
            'vin.regex' => 'No. rangka hanya boleh huruf dan angka.',
            'vin.unique' => 'No. rangka sudah terdaftar atas kendaraan lain.',
            'plat_kode.regex' => 'Kode wilayah plat 1–2 huruf.',
            'plat_nomor.regex' => 'Nomor plat 1–4 angka.',
            'plat_seri.regex' => 'Huruf belakang plat 1–3 huruf.',
            'tahun.between' => 'Tahun harus antara 1990 dan '.(now()->year + 1).'.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak sama.',
            'password.min' => 'Kata sandi minimal :min karakter.',
            'password.mixed' => 'Kata sandi harus mengandung huruf besar dan huruf kecil.',
            'password.numbers' => 'Kata sandi harus mengandung angka.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'no_lambung' => 'No. lambung',
            'nama_lengkap' => 'nama lengkap',
            'nama_panggilan' => 'nama panggilan',
            'no_hp' => 'No. HP',
            'tgl_lahir' => 'tanggal lahir',
            'jenis_kelamin' => 'jenis kelamin',
            'city_code' => 'kota',
            'district_code' => 'kecamatan',
            'village_code' => 'kelurahan',
            'tgl_bergabung' => 'tanggal bergabung',
            'notes' => 'catatan',
            'has_vehicle' => 'kepemilikan kendaraan',
            'vin' => 'no. rangka',
            'plat_kode' => 'kode wilayah plat',
            'plat_nomor' => 'nomor plat',
            'plat_status' => 'status no. plat',
            'vehicle_brand_id' => 'merek',
            'vehicle_model_id' => 'tipe',
            'first_due' => 'iuran periode pertama',
            'payment_method' => 'metode pembayaran',
            'password_mode' => 'kata sandi',
            'password' => 'kata sandi',
        ];
    }

    /** Member yang diedit (null = tambah member). */
    public function member(): ?Member
    {
        $member = $this->route('member');

        return $member instanceof Member ? $member : null;
    }

    /** Kendaraan utama member yang diedit. */
    public function vehicle(): ?MemberVehicle
    {
        return once(fn () => $this->member()?->primaryVehicle());
    }

    /** Kondisi exists: aktif, atau id yang sedang dipakai kendaraan member. */
    private function activeOrCurrent(?int $currentId): \Closure
    {
        return fn ($query) => $query->where('is_active', true)
            ->when($currentId, fn ($q) => $q->orWhere('id', $currentId));
    }
}
