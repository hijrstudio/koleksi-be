<?php

namespace App\Http\Resources;

use App\Models\Member;
use App\Models\MemberVehicleDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Data lengkap untuk form "Ubah profil" member.
 *
 * @mixin Member
 */
class MemberEditProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['city:code,name', 'district:code,name', 'village:code,name']);
        $vehicle = $this->primaryVehicle()?->load(['documents', 'brand:id,name,is_active', 'vehicleModel:id,name,is_active']);

        $region = fn ($r) => $r ? ['code' => $r->code, 'name' => $r->name] : null;

        return [
            'nama_lengkap' => $this->nama_lengkap,
            'nama_panggilan' => $this->nama_panggilan,
            'email' => $this->email,
            'photo_url' => $this->photo_url,           // avatar default kalau belum upload
            'has_photo' => filled($this->photo_profile),
            'default_photo_url' => Storage::disk(Member::PHOTO_DISK)->url(Member::DEFAULT_PHOTO),
            'no_hp' => $this->no_hp,
            'tgl_lahir' => $this->tgl_lahir?->toDateString(),
            'jenis_kelamin' => $this->jenis_kelamin,
            'alamat' => $this->alamat,
            'city' => $region($this->city),
            'district' => $region($this->district),
            'village' => $region($this->village),
            'has_vehicle' => $this->has_vehicle,
            'vehicle' => $vehicle ? [
                'id' => $vehicle->id,
                'vin' => $vehicle->vin,
                'plat_kode' => $vehicle->plat_kode,
                'plat_nomor' => $vehicle->plat_nomor,
                'plat_seri' => $vehicle->plat_seri,
                'plat_status' => $vehicle->plat_status,
                'vehicle_brand_id' => $vehicle->vehicle_brand_id,
                'vehicle_model_id' => $vehicle->vehicle_model_id,
                // Nama ikut dikirim: merek/tipe yang dinonaktifkan di CMS tidak ada di dropdown /vehicle-brands,
                // FE menambahkannya sebagai opsi supaya pilihan member tetap tampil.
                'vehicle_brand' => $vehicle->brand ? ['id' => $vehicle->brand->id, 'name' => $vehicle->brand->name, 'is_active' => $vehicle->brand->is_active] : null,
                'vehicle_model' => $vehicle->vehicleModel ? ['id' => $vehicle->vehicleModel->id, 'name' => $vehicle->vehicleModel->name, 'is_active' => $vehicle->vehicleModel->is_active] : null,
                'tahun' => $vehicle->tahun,
                'verification_status' => $vehicle->verification_status,
                'rejection_reason' => $vehicle->rejection_reason,
                'documents' => $vehicle->documents->map(fn (MemberVehicleDocument $d) => [
                    'id' => $d->id,
                    'type' => $d->type,
                    'original_name' => $d->original_name,
                    'mime_type' => $d->mime_type,
                    'size' => $d->size,
                ])->values(),
            ] : null,
            'profile_completed' => $this->profile_completed_at !== null,
        ];
    }
}
