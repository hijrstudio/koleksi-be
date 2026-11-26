<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberVehicle;
use App\Models\Regency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Direktori anggota forum (hanya untuk member yang login).
 *
 *   GET /forum/members?search=&city=&page=   anggota resmi, urut No. lambung
 *   GET /forum/members/cities                kota yang diisi anggota (satu entri per kota)
 *
 * Yang dikirim HANYA: No. lambung, nama, foto, merek kendaraan utama, kota. Email, No. HP, alamat, plat, VIN,
 * dan id internal tidak pernah ikut. Pencarian hanya nama & No. lambung (bukan scope Member::search yang juga
 * mencari email / No. HP — itu bisa dipakai menebak pemilik sebuah nomor).
 */
class MemberDirectoryController extends Controller
{
    private const PER_PAGE = 12;

    private const CITIES_CACHE = 'forum:directory:cities';

    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'regex:/^\d{2}\.\d{2}$/'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $search = trim((string) ($params['search'] ?? ''));

        $members = Member::query()
            ->registered()
            ->whereNotNull('no_lambung')
            ->when($params['city'] ?? null, fn (Builder $q, string $code) => $q->where('city_code', $code))
            ->when($search !== '', fn (Builder $q) => $this->search($q, $search))
            ->with([
                'city:code,name',
                // Kendaraan utama (atau yang pertama) untuk merek; hanya kolom yang perlu
                'vehicles' => fn ($q) => $q->select(['id', 'member_id', 'vehicle_brand_id', 'brand_other', 'is_primary'])
                    ->orderByDesc('is_primary')->orderBy('id'),
                'vehicles.brand:id,name',
            ])
            ->orderBy('no_lambung')
            ->paginate(self::PER_PAGE, ['id', 'no_lambung', 'nama_lengkap', 'nama_panggilan', 'photo_profile', 'city_code']);

        return response()->json([
            'data' => $members->getCollection()->map(fn (Member $m) => [
                'no_lambung' => $m->noLambungLabel(),
                'name' => $m->nama_panggilan ?: $m->nama_lengkap,
                'avatar' => $m->photo_url,
                'vehicle_brand' => $this->brand($m->vehicles->first()),
                'city' => $m->city?->name,
            ])->values(),
            'meta' => ['current_page' => $members->currentPage(), 'last_page' => $members->lastPage(), 'total' => $members->total()],
        ]);
    }

    public function cities(): JsonResponse
    {
        $cities = Cache::remember(self::CITIES_CACHE, now()->addMinutes(10), fn () => Regency::query()
            ->whereIn('code', Member::query()->registered()->whereNotNull('no_lambung')->whereNotNull('city_code')->select('city_code'))
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn (Regency $r) => ['code' => $r->code, 'name' => $r->name])
            ->all());

        return response()->json(['data' => $cities]);
    }

    /** "#7" / "007" → No. lambung; teks lain → nama lengkap / panggilan. */
    private function search(Builder $q, string $term): void
    {
        if (preg_match('/^#?\s*(\d{1,6})$/', $term, $m)) {
            $q->where('no_lambung', (int) $m[1]);

            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $q->where(fn (Builder $w) => $w->where('nama_lengkap', 'like', $like)->orWhere('nama_panggilan', 'like', $like));
    }

    private function brand(?MemberVehicle $vehicle): ?string
    {
        return $vehicle ? ($vehicle->brand?->name ?? $vehicle->brand_other) : null;
    }
}
