<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\UpdateMemberStatusRequest;
use App\Models\Member;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

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

    public function index(): View
    {
        Gate::authorize('viewAny', Member::class);

        return view('pages.cms.members.index', [
            'title' => 'Daftar Member',
            'summary' => $this->summary(),
            'pageLengths' => self::PAGE_LENGTHS,
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
            ]),
        ]);
    }

    public function show(Member $member, MembershipService $membership): View|RedirectResponse
    {
        Gate::authorize('view', $member);

        // Pendaftar yang belum disetujui dilihat di halaman review pendaftaran
        if (! $member->isRegistrationApproved()) {
            return redirect()->route('member-registrations.show', $member);
        }

        $member->load([
            'vehicles.brand:id,name',
            'vehicles.vehicleModel:id,name',
            'dues' => fn ($q) => $q->with('recorder:id,name')->orderByDesc('period_start'),
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
