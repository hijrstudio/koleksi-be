<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\UpdateMemberStatusRequest;
use App\Models\Member;
use App\Models\MemberVehicleDocument;
use App\Models\Setting;
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

    public function edit(Member $member, NoLambungAllocator $noLambung): View
    {
        Gate::authorize('update', $member);
        abort_unless($member->isRegistrationApproved(), 404);

        return view('pages.cms.members.edit', [
            'title' => 'Edit Member',
            'member' => $member,
            'nextNoLambung' => $noLambung->next(),
        ]);
    }

    /**
     * Ubah No. lambung. Ditolak kalau sudah dipakai anggota lain (termasuk yang dihapus); nomor invoice iuran
     * member ikut disesuaikan.
     */
    public function update(Request $request, Member $member, NoLambungAllocator $noLambung, MembershipService $membership): RedirectResponse
    {
        Gate::authorize('update', $member);
        abort_unless($member->isRegistrationApproved(), 404);

        $number = (int) $request->validate([
            'no_lambung' => ['required', 'integer', 'min:1', 'max:'.self::MAX_NO_LAMBUNG],
        ], [], ['no_lambung' => 'No. lambung'])['no_lambung'];

        if ($number === $member->no_lambung) {
            return redirect()->route('members.show', $member)->with('success', 'No. lambung tidak berubah.');
        }

        $old = $member->noLambungLabel();
        $noLambung->change($member, $number, fn () => DB::transaction(function () use ($member, $number, $membership, $old, $request) {
            $member->forceFill(['no_lambung' => $number])->save();
            $membership->renumberInvoices($member);
            $member->statusLogs()->create([
                'from_status' => $member->status,
                'to_status' => $member->status,
                'mode' => $member->status_mode,
                'reason' => "No. lambung diubah dari {$old} menjadi {$member->noLambungLabel()}",
                'changed_by' => $request->user()->id,
            ]);
        }));

        return redirect()->route('members.show', $member)->with('success', "No. lambung diubah menjadi {$member->noLambungLabel()}.");
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
