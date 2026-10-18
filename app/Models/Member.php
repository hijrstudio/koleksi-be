<?php

namespace App\Models;

use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class Member extends Authenticatable
{
    /** @use HasFactory<MemberFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    /** Disk & avatar default untuk foto profil (storage/app/public/members/user.png). */
    public const PHOTO_DISK = 'public';

    public const DEFAULT_PHOTO = 'members/user.png';

    /** Status dihitung dari iuran */
    public const MODE_AUTO = 'auto';

    /** Status ditetapkan admin CMS */
    public const MODE_MANUAL = 'manual';

    /** Pendaftaran lewat website (lihat App\Services\MemberRegistrationService). */
    public const REG_UNVERIFIED = 'unverified';   // e-mail belum diverifikasi

    public const REG_PENDING = 'pending';         // menunggu review pengurus

    public const REG_APPROVED = 'approved';       // anggota resmi (default data lama / import)

    public const REG_REJECTED = 'rejected';

    public const REGISTRATION_STATUSES = [self::REG_UNVERIFIED, self::REG_PENDING, self::REG_APPROVED, self::REG_REJECTED];

    /** Pendaftaran yang boleh "ditimpa" pendaftaran baru dengan e-mail / No. HP / kendaraan yang sama. */
    public const REG_STALE = [self::REG_UNVERIFIED, self::REG_REJECTED];

    /**
     * status / status_mode / active_until sengaja tidak fillable: hanya diubah lewat MembershipService.
     * Kolom pendaftaran (registration_status, reviewed_*) hanya diubah lewat MemberRegistrationService.
     */
    protected $fillable = [
        'no_lambung', 'nama_lengkap', 'nama_panggilan', 'photo_profile', 'email', 'no_hp',
        'tgl_lahir', 'jenis_kelamin', 'alamat', 'city_code', 'district_code',
        'village_code', 'tgl_bergabung', 'has_vehicle', 'notes', 'password',
        'must_change_password', 'profile_completed_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'tgl_lahir' => 'date',
            'tgl_bergabung' => 'date',
            'active_until' => 'date',
            'paid_until' => 'date',
            'has_vehicle' => 'boolean',
            'must_change_password' => 'boolean',
            'profile_completed_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'registered_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** URL foto profil; avatar default kalau member belum upload foto. */
    protected function photoUrl(): Attribute
    {
        return Attribute::get(fn () => Storage::disk(self::PHOTO_DISK)->url($this->photo_profile ?: self::DEFAULT_PHOTO));
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(Regency::class, 'city_code');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_code');
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class, 'village_code');
    }

    public function dues(): HasMany
    {
        return $this->hasMany(MemberDue::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(MemberVehicle::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(MemberStatusLog::class);
    }

    public function renewalReminders(): HasMany
    {
        return $this->hasMany(MemberRenewalReminder::class);
    }

    /**
     * Patokan periode keanggotaan: tanggal bergabung.
     * Kalau kosong (data lama), pakai tanggal data dibuat.
     */
    public function membershipAnchor(): Carbon
    {
        return ($this->tgl_bergabung ?? $this->created_at ?? today())->copy()->startOfDay();
    }

    /** Tanggal daftar ulang jatuh tempo = sehari setelah periode lunas terakhir berakhir. */
    public function renewalDate(): ?Carbon
    {
        return $this->paid_until?->copy()->addDay();
    }

    /** Sisa hari menuju jatuh tempo daftar ulang (negatif = sudah lewat). */
    public function daysUntilRenewal(?Carbon $date = null): ?int
    {
        $renewal = $this->renewalDate();

        return $renewal ? (int) ($date ?? today())->diffInDays($renewal, false) : null;
    }

    /** Kendaraan yang ditampilkan/diedit di profil: kendaraan utama, atau yang pertama didaftarkan. */
    public function primaryVehicle(): ?MemberVehicle
    {
        return $this->vehicles()->orderByDesc('is_primary')->orderBy('id')->first();
    }

    /**
     * Profil dianggap lengkap kalau data pribadi & wilayah terisi, dan kalau punya kendaraan,
     * kendaraannya sudah didaftarkan. Dipakai untuk banner "lengkapi profil" di dasbor.
     */
    public function isProfileComplete(): bool
    {
        $filled = collect(['nama_lengkap', 'no_hp', 'tgl_lahir', 'jenis_kelamin', 'alamat', 'city_code', 'district_code', 'village_code'])
            ->every(fn (string $field) => filled($this->{$field}));

        return $filled && (! $this->has_vehicle || $this->vehicles()->exists());
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isRegistrationApproved(): bool
    {
        return $this->registration_status === self::REG_APPROVED;
    }

    public function isAwaitingReview(): bool
    {
        return $this->registration_status === self::REG_PENDING;
    }

    public function isManualStatus(): bool
    {
        return $this->status_mode === self::MODE_MANUAL;
    }

    /** "#007"; "—" untuk pendaftar yang belum disetujui (belum punya no. lambung). */
    public function noLambungLabel(): string
    {
        return $this->no_lambung === null ? '—' : '#'.str_pad((string) $this->no_lambung, 3, '0', STR_PAD_LEFT);
    }

    /** Anggota resmi — pendaftar yang belum/tidak disetujui tidak ikut daftar member, login, dsb. */
    public function scopeRegistered(Builder $q): void
    {
        $q->where('registration_status', self::REG_APPROVED);
    }

    public function scopeRegistration(Builder $q, string $status): void
    {
        $q->where('registration_status', $status);
    }

    public function scopeActive(Builder $q): void
    {
        $q->where('status', self::STATUS_ACTIVE);
    }

    public function scopeInactive(Builder $q): void
    {
        $q->where('status', self::STATUS_INACTIVE);
    }

    /**
     * Filter daftar ulang (relatif terhadap $date, default hari ini):
     *   due_soon → jatuh tempo dalam N hari ke depan (N = tahap pengingat terjauh, default 30)
     *   grace    → sudah lewat jatuh tempo tapi masih dalam masa tenggang
     */
    public function scopeRenewal(Builder $q, string $type, ?Carbon $date = null): void
    {
        $date ??= today();
        $window = max(config('membership.reminder_days')) ?: 30;

        match ($type) {
            // jatuh tempo (paid_until + 1) di antara hari ini .. hari ini + window
            'due_soon' => $q->whereDate('paid_until', '>=', $date->copy()->subDay())
                ->whereDate('paid_until', '<', $date->copy()->addDays($window)),
            'grace' => $q->whereDate('paid_until', '<', $date->copy()->subDay())
                ->whereDate('active_until', '>=', $date),
            default => null,
        };
    }

    /**
     * Cari nama / panggilan / email / No. HP / No. lambung.
     *   "#7"   → hanya No. lambung 7
     *   "7"    → No. lambung 7 (+ nama/email yang mengandung "7")
     *   "0811" → No. HP mengandung 0811 (minimal 4 digit, supaya tidak terlalu luas)
     */
    public function scopeSearch(Builder $q, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        if (preg_match('/^#\s*(\d+)$/', $term, $m)) {
            $q->where('no_lambung', (int) $m[1]);

            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $digits = preg_replace('/\D+/', '', $term);

        $q->where(function (Builder $q) use ($term, $like, $digits) {
            $q->where('nama_lengkap', 'like', $like)
                ->orWhere('nama_panggilan', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->when(strlen($digits) >= 4, fn ($q) => $q->orWhere('no_hp', 'like', "%{$digits}%"))
                ->when(ctype_digit($term), fn ($q) => $q->orWhere('no_lambung', (int) $term));
        });
    }
}
