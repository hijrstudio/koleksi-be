<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\MemberRequest;
use App\Http\Requests\Cms\UpdateMemberStatusRequest;
use App\Models\District;
use App\Models\Member;
use App\Models\MemberVehicleDocument;
use App\Models\Regency;
use App\Models\Setting;
use App\Models\VehicleBrand;
use App\Models\Village;
use App\Services\MemberAdminService;
use App\Services\MembershipService;
use App\Services\NoLambungAllocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MemberController extends Controller
{
    /**
     * Index kolom DataTables → kolom database yang boleh di-sort.
     * Urutan harus sama dengan `columns` di resources/js/components/datatables/members-table.js.
     */
    private const SORTABLE_COLUMNS = [
        0 => 'no_lambung',
        1 => 'nama_lengkap',
        3 => 'tgl_bergabung',
        4 => 'status',
        5 => 'paid_until',   // kolom "Daftar ulang" (jatuh tempo = paid_until + 1 hari)
    ];

    private const PAGE_LENGTHS = [10, 25, 50, 100];

    /** Batas atas No. lambung yang bisa diisi admin (cukup untuk 5 digit). */
    public const MAX_NO_LAMBUNG = 99999;

    public function index(NoLambungAllocator $noLambung): View
    {
        Gate::authorize('viewAny', Member::class);

        return view('pages.cms.members.index', [
            'title' => 'Daftar Member',
            'summary' => $this->summary(),
            'pageLengths' => self::PAGE_LENGTHS,
            'noLambungStart' => Setting::get(Setting::NO_LAMBUNG_START),
            'nextNoLambung' => $noLambung->next(),
            'highestNoLambung' => $noLambung->highest(),
        ]);
    }

    /**
     * Endpoint server-side processing DataTables.
     * GET /members/data?draw=1&start=0&length=25&search[value]=..&order[0][column]=0&order[0][dir]=asc&status=..&contact=..
     */
    public function data(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Member::class);

        $params = $request->validate([
            'draw' => ['required', 'integer', 'min:0'],
            'start' => ['required', 'integer', 'min:0'],
            'length' => ['required', 'integer', Rule::in(self::PAGE_LENGTHS)],
            'search.value' => ['nullable', 'string', 'max:100'],
            'order.0.column' => ['nullable', 'integer', Rule::in(array_keys(self::SORTABLE_COLUMNS))],
            'order.0.dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'status' => ['nullable', Rule::in(Member::STATUSES)],
            'contact' => ['nullable', Rule::in(['no_email', 'no_phone', 'none'])],
            'renewal' => ['nullable', Rule::in(['due_soon', 'grace'])],
        ]);

        $contact = $params['contact'] ?? null;

        $query = Member::query()
            ->registered()
            ->search(data_get($params, 'search.value'))
            ->when($params['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($params['renewal'] ?? null, fn ($q, $v) => $q->renewal($v))
            ->when($contact === 'no_email', fn ($q) => $q->whereNull('email'))
            ->when($contact === 'no_phone', fn ($q) => $q->whereNull('no_hp'))
            ->when($contact === 'none', fn ($q) => $q->whereNull('email')->whereNull('no_hp'));

        $filtered = (clone $query)->count();

        $members = $query
            ->orderBy(self::SORTABLE_COLUMNS[data_get($params, 'order.0.column', 0)], data_get($params, 'order.0.dir', 'asc'))
            ->orderBy('id')
            ->skip($params['start'])
            ->take($params['length'])
            ->get(['id', 'no_lambung', 'nama_lengkap', 'nama_panggilan', 'email', 'no_hp', 'tgl_bergabung', 'status', 'status_mode', 'paid_until', 'active_until']);

        return response()->json([
            'draw' => (int) $params['draw'],
            'recordsTotal' => Member::query()->registered()->count(),
            'recordsFiltered' => $filtered,
            'data' => $members->map(fn (Member $m) => [
                'id' => $m->id,
                'no_lambung' => $m->noLambungLabel(),
                'nama_lengkap' => $m->nama_lengkap,
                'nama_panggilan' => $m->nama_panggilan,
                'email' => $m->email,
                'no_hp' => $m->no_hp,
                'tgl_bergabung' => $m->tgl_bergabung?->translatedFormat('d M Y'),
                'status' => $m->status,
                'status_manual' => $m->isManualStatus(),
                'renewal_date' => $m->renewalDate()?->translatedFormat('d M Y'),
                'renewal_days_left' => $m->daysUntilRenewal(),
                'active_until' => $m->active_until?->translatedFormat('d M Y'),
                'show_url' => route('members.show', $m),
                'edit_url' => $request->user()->can('update', $m) ? route('members.edit', $m) : null,
                'delete_url' => $request->user()->can('delete', $m) ? route('members.destroy', $m) : null,
            ]),
        ]);
    }

    public function show(Member $member, MembershipService $membership): View|RedirectResponse
    {
        Gate::authorize('view', $member);

        // Pendaftar yang belum membayar iuran pertama bukan anggota (tidak tampil di CMS)
        abort_unless($member->isRegistrationApproved(), 404);

        $member->load([
            'vehicles.brand:id,name',
            'vehicles.vehicleModel:id,name',
            'vehicles.documents:id,member_vehicle_id,type,original_name',
            // Riwayat iuran mulai tahun pemutihan (mis. 2026-2027); periode sebelumnya tidak ditampilkan
            'dues' => fn ($q) => $q->with('recorder:id,name')
                ->when($membership->arrearsCutoffYear(), fn ($q, int $year) => $q->whereYear('period_start', '>=', $year))
                ->orderByDesc('period_start'),
            'statusLogs' => fn ($q) => $q->with('changer:id,name')->latest('id')->limit(20),
            'renewalReminders' => fn ($q) => $q->latest('sent_at')->limit(20),
        ]);

        return view('pages.cms.members.show', [
            'title' => 'Detail Member',
            'member' => $member,
            'currentTerm' => $membership->currentTerm($member),
            'renewalTerm' => $membership->renewalTerm($member),
        ]);
    }

    public function updateStatus(UpdateMemberStatusRequest $request, Member $member, MembershipService $membership): RedirectResponse
    {
        $status = $request->validated('status');
        $reason = $request->validated('reason');

        if ($status === UpdateMemberStatusRequest::AUTO) {
            $membership->useAutoStatus($member, $request->user(), $reason);
        } else {
            $membership->setManualStatus($member, $status, $request->user(), $reason);
        }

        return redirect()->route('members.show', $member)->with('success', 'Status member berhasil diperbarui.');
    }

    public function create(NoLambungAllocator $noLambung): View
    {
        Gate::authorize('create', Member::class);

        return view('pages.cms.members.form', [
            'title' => 'Tambah Member',
            'member' => new Member(['tgl_bergabung' => today(), 'has_vehicle' => false]),
            'vehicle' => null,
            'hasDues' => false,
            ...$this->formOptions($noLambung, null),
        ]);
    }

    public function store(MemberRequest $request, MemberAdminService $members): RedirectResponse
    {
        $member = $members->create($request->validated(), $request->user());

        return redirect()->route('members.show', $member)
            ->with('success', "Member {$member->nama_lengkap} ({$member->noLambungLabel()}) ditambahkan.");
    }

    public function edit(Member $member, NoLambungAllocator $noLambung): View
    {
        Gate::authorize('update', $member);
        abort_unless($member->isRegistrationApproved(), 404);

        return view('pages.cms.members.form', [
            'title' => 'Edit Member',
            'member' => $member,
            'vehicle' => $member->primaryVehicle(),
            'hasDues' => $member->dues()->exists(),
            ...$this->formOptions($noLambung, $member),
        ]);
    }

    /**
     * Ubah data member. No. lambung ditolak kalau sudah dipakai anggota lain (termasuk yang dihapus); nomor invoice
     * iuran member ikut disesuaikan.
     */
    public function update(MemberRequest $request, Member $member, MemberAdminService $members): RedirectResponse
    {
        abort_unless($member->isRegistrationApproved(), 404);

        $members->update($member, $request->validated(), $request->user());

        return redirect()->route('members.show', $member)->with('success', 'Data member berhasil diperbarui.');
    }

    /** Kecamatan di kota terpilih (dropdown bertingkat form member). */
    public function districts(string $regency): JsonResponse
    {
        Gate::authorize('viewAny', Member::class);

        return response()->json([
            'data' => District::where('regency_code', $regency)->orderBy('name')->get(['code', 'name']),
        ]);
    }

    /** Kelurahan di kecamatan terpilih. */
    public function villages(string $district): JsonResponse
    {
        Gate::authorize('viewAny', Member::class);

        return response()->json([
            'data' => Village::where('district_code', $district)->orderBy('name')->get(['code', 'name']),
        ]);
    }

    /**
     * Hapus member (soft delete): hilang dari daftar & tidak bisa login, riwayat iuran/pembayaran tetap tersimpan,
     * dan No. lambungnya tidak dipakai ulang.
     */
    public function destroy(Request $request, Member $member): RedirectResponse
    {
        Gate::authorize('delete', $member);

        DB::transaction(function () use ($member, $request) {
            $member->statusLogs()->create([
                'from_status' => $member->status,
                'to_status' => $member->status,
                'mode' => $member->status_mode,
                'reason' => 'Member dihapus dari CMS',
                'changed_by' => $request->user()->id,
            ]);
            $member->tokens()->delete();   // keluar dari semua sesi login
            $member->delete();
        });

        return redirect()->route('members.index')->with('success', "Member {$member->nama_lengkap} ({$member->noLambungLabel()}) dihapus.");
    }

    /** Nomor awal pencarian No. lambung anggota baru (kosong = nomor tertinggi + 1). */
    public function updateNoLambungStart(Request $request): RedirectResponse
    {
        Gate::authorize('updateSettings', Member::class);

        $start = $request->validate([
            'no_lambung_start' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_NO_LAMBUNG],
        ], [], ['no_lambung_start' => 'nomor awal'])['no_lambung_start'] ?? null;

        Setting::put(Setting::NO_LAMBUNG_START, $start === null ? null : (string) (int) $start);

        return redirect()->route('members.index')->with('success', 'Nomor awal No. lambung anggota baru disimpan.');
    }

    /**
     * Buka dokumen bukti kendaraan (disk privat). Dilayani inline + nosniff dengan MIME tersimpan;
     * upload hanya menerima JPG/PNG/WEBP/PDF (dicek dari isi file).
     */
    public function document(MemberVehicleDocument $document): StreamedResponse
    {
        Gate::authorize('viewAny', Member::class);

        $disk = Storage::disk($document->disk);
        abort_unless($disk->exists($document->path), 404);

        return $disk->response($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    /**
     * Data dropdown form tambah/edit: kota (514, sekali kirim), merek + tipe (aktif + yang sedang dipakai member),
     * kecamatan/kelurahan terpilih (sisanya dimuat saat kota/kecamatan dipilih).
     *
     * @return array<string, mixed>
     */
    private function formOptions(NoLambungAllocator $noLambung, ?Member $member): array
    {
        $vehicle = $member?->primaryVehicle();
        $brandId = (int) old('vehicle_brand_id', $vehicle?->vehicle_brand_id);
        $modelId = (int) old('vehicle_model_id', $vehicle?->vehicle_model_id);
        $cityCode = (string) old('city_code', $member?->city_code);
        $districtCode = (string) old('district_code', $member?->district_code);

        $brands = VehicleBrand::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $brandId))
            ->with(['models' => fn ($q) => $q->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $modelId))
                ->orderBy('name')->select('id', 'vehicle_brand_id', 'name')])
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name']);

        return [
            'nextNoLambung' => $noLambung->next(),
            'regencies' => Regency::query()
                ->join('provinces', 'provinces.code', '=', 'regencies.province_code')
                ->orderBy('regencies.province_code')->orderBy('regencies.name')
                ->get(['regencies.code', 'regencies.name', 'provinces.name as province']),
            'districts' => $cityCode !== '' ? District::where('regency_code', $cityCode)->orderBy('name')->get(['code', 'name']) : [],
            'villages' => $districtCode !== '' ? Village::where('district_code', $districtCode)->orderBy('name')->get(['code', 'name']) : [],
            'brands' => $brands->map(fn (VehicleBrand $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'models' => $b->models->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
            ])->values(),
        ];
    }

    /**
     * Angka untuk kartu ringkasan di atas tabel.
     *
     * @return array<string, int>
     */
    private function summary(): array
    {
        $row = Member::query()->registered()->toBase()->selectRaw("
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive,
            SUM(CASE WHEN email IS NULL AND no_hp IS NULL THEN 1 ELSE 0 END) AS no_contact
        ")->first();

        return [
            ...array_map('intval', (array) $row),
            'due_soon' => Member::query()->registered()->renewal('due_soon')->count(),
            'grace' => Member::query()->registered()->renewal('grace')->count(),
        ];
    }
}
