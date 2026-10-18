<?php

namespace App\Models;

use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VehicleBrand extends Model
{
    use HasFactory, TracksAuthor;

    public const DISK = 'public';

    public const DIRECTORY = 'vehicle-brands';

    protected $fillable = ['name', 'slug', 'logo_path', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Slug selalu mengikuti nama (dipakai sebagai filter ?brand= di website)
        static::saving(function (self $brand) {
            $brand->slug = Str::slug($brand->name);
        });
    }

    public function models(): HasMany
    {
        return $this->hasMany(VehicleModel::class);
    }

    public function memberVehicles(): HasMany
    {
        return $this->hasMany(MemberVehicle::class);
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->logo_path
            ? Storage::disk(self::DISK)->url($this->logo_path)
            : null);
    }

    public static function nextSort(): int
    {
        return (int) static::query()->where('sort_order', '<', 999)->max('sort_order') + 1;
    }

    public function scopeActive(Builder $q): void
    {
        $q->where('is_active', true);
    }

    public function scopeOrdered(Builder $q): void
    {
        $q->orderBy('sort_order')->orderBy('name');
    }

    public function scopeSearch(Builder $q, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $q->where('name', 'like', '%'.addcslashes($term, '%_\\').'%');
    }

    /**
     * Merek yang punya minimal satu tipe tampil di EV Garage.
     */
    public function scopeInGarage(Builder $q): void
    {
        $q->active()->whereHas('models', fn (Builder $m) => $m->garage());
    }

    /**
     * Masih dipakai kendaraan member (langsung atau lewat salah satu tipenya)?
     */
    public function isUsedByMembers(): bool
    {
        return $this->memberVehicles()->exists()
            || MemberVehicle::query()->whereIn('vehicle_model_id', $this->models()->select('id'))->exists();
    }
}
