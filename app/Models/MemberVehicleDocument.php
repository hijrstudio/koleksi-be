<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MemberVehicleDocument extends Model
{
    protected $fillable = ['member_vehicle_id', 'type', 'disk', 'path', 'original_name', 'mime_type', 'size'];

    protected $hidden = ['disk', 'path'];

    protected static function booted(): void
    {
        // Hapus file fisik saat record dihapus
        static::deleted(fn (self $d) => Storage::disk($d->disk)->delete($d->path));
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(MemberVehicle::class, 'member_vehicle_id');
    }
}
