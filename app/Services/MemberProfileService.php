<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberVehicle;
use App\Models\MemberVehicleDocument;
use App\Support\ProfilePhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Simpan perubahan profil oleh member: data pribadi, foto profil, wilayah, dan kendaraan utama + dokumen buktinya.
 *
 * Kendaraan yang sudah diverifikasi pengurus kembali ke "pending" kalau data identitasnya
 * (MemberVehicle::SENSITIVE) berubah atau ada dokumen baru, supaya diverifikasi ulang.
 */
class MemberProfileService
{
    public const DOCUMENT_DISK = 'local'; // privat, tidak bisa diakses lewat URL publik

    /** Folder foto profil di disk public (Member::PHOTO_DISK). */
    public const PHOTO_DIR = 'members/photos';

    private const PERSONAL = [
        'nama_lengkap', 'nama_panggilan', 'tgl_lahir', 'jenis_kelamin', 'no_hp', 'alamat',
        'city_code', 'district_code', 'village_code',
    ];

    private const VEHICLE = [
        'vin', 'plat_kode', 'plat_nomor', 'plat_seri', 'plat_status', 'vehicle_brand_id', 'vehicle_model_id', 'tahun',
    ];

    /**
     * @param  array<string, mixed>  $data  hasil validasi UpdateMemberProfileRequest
     * @param  list<UploadedFile>  $documents
     * @param  list<int>  $removeDocumentIds
     * @param  UploadedFile|null  $photo  foto profil baru (menggantikan yang lama)
     * @param  bool  $removePhoto  hapus foto profil → kembali ke avatar default
     */
    public function update(
        Member $member,
        array $data,
        array $documents = [],
        array $removeDocumentIds = [],
        ?UploadedFile $photo = null,
        bool $removePhoto = false,
    ): Member {
        $stored = [];
        $oldPhoto = $member->photo_profile;

        // Diproses sebelum transaksi: kalau foto rusak, tidak ada data yang sempat berubah
        $newPhoto = $photo ? $this->storePhoto($member, $photo) : null;

        try {
            DB::transaction(function () use ($member, $data, $documents, $removeDocumentIds, $newPhoto, $removePhoto, &$stored) {
                $member->fill(array_intersect_key($data, array_flip(self::PERSONAL)));
                $member->has_vehicle = (bool) $data['has_vehicle'];
                if ($newPhoto || $removePhoto) {
                    $member->photo_profile = $newPhoto;
                }
                $member->save();

                if ($member->has_vehicle) {
                    $this->saveVehicle($member, $data, $documents, $removeDocumentIds, $stored);
                }
                // has_vehicle = tidak: data kendaraan lama tidak dihapus (riwayat & verifikasi tetap ada).

                $member->profile_completed_at = $member->isProfileComplete()
                    ? ($member->profile_completed_at ?? now())
                    : null;
                $member->save();
            });
        } catch (Throwable $e) {
            // File yang sudah tersimpan tidak ikut di-rollback DB → hapus manual
            Storage::disk(self::DOCUMENT_DISK)->delete($stored);
            if ($newPhoto) {
                Storage::disk(Member::PHOTO_DISK)->delete($newPhoto);
            }

            throw $e;
        }

        // Foto lama baru dihapus setelah perubahan tersimpan
        if (($newPhoto || $removePhoto) && $oldPhoto && $oldPhoto !== Member::DEFAULT_PHOTO) {
            Storage::disk(Member::PHOTO_DISK)->delete($oldPhoto);
        }

        return $member->refresh();
    }

    /** Encode ulang foto (lihat ProfilePhoto) lalu simpan dengan nama acak. Mengembalikan path di disk public. */
    private function storePhoto(Member $member, UploadedFile $photo): string
    {
        try {
            $binary = ProfilePhoto::process($photo);
        } catch (RuntimeException $e) {
            // Lolos validasi tapi gagal di-decode (file rusak / dimanipulasi) → tampil sebagai error field
            throw ValidationException::withMessages(['photo' => $e->getMessage()]);
        }

        $path = sprintf('%s/%d-%s.webp', self::PHOTO_DIR, $member->id, Str::random(24));
        Storage::disk(Member::PHOTO_DISK)->put($path, $binary);

        return $path;
    }

    /**
     * @param  list<UploadedFile>  $documents
     * @param  list<int>  $removeDocumentIds
     * @param  list<string>  $stored  path file yang sudah ditulis (untuk cleanup saat gagal)
     */
    private function saveVehicle(Member $member, array $data, array $documents, array $removeDocumentIds, array &$stored): void
    {
        $vehicle = $member->primaryVehicle() ?? new MemberVehicle(['member_id' => $member->id, 'is_primary' => true]);

        // Field opsional yang dikosongkan di form ikut dikosongkan di DB
        $vehicle->fill(array_merge(array_fill_keys(self::VEHICLE, null), array_intersect_key($data, array_flip(self::VEHICLE))));
        if (! $vehicle->vehicle_brand_id) {
            $vehicle->vehicle_model_id = null;
        }

        $needsReview = ! $vehicle->exists || $vehicle->isDirty(MemberVehicle::SENSITIVE) || $documents !== [];
        if ($needsReview) {
            $vehicle->forceFill([
                'verification_status' => MemberVehicle::PENDING,
                'verified_by' => null,
                'verified_at' => null,
                'rejection_reason' => null,
            ]);
        }

        $vehicle->save();

        foreach ($documents as $file) {
            $path = $file->store("member-vehicles/{$vehicle->id}", self::DOCUMENT_DISK);
            $stored[] = $path;

            $vehicle->documents()->create([
                'type' => 'lainnya',
                'disk' => self::DOCUMENT_DISK,
                'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime_type' => $file->getMimeType() ?? $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);
        }

        // Hanya dokumen milik kendaraan ini yang bisa dihapus. Dihapus per model → file fisiknya ikut terhapus.
        if ($removeDocumentIds !== []) {
            $vehicle->documents()->whereIn('id', $removeDocumentIds)->get()
                ->each(fn (MemberVehicleDocument $doc) => $doc->delete());
        }
    }
}
