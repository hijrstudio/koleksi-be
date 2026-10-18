<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberVehicleDocument;
use App\Services\MemberRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Review pendaftaran member baru: antrian per status → detail (data pribadi, kendaraan, dokumen) → setujui / tolak.
 * Pendaftar yang disetujui pindah ke Daftar Member (MemberController).
 */
class MemberRegistrationController extends Controller
{
    /** Tab di halaman antrian; "pending" (menunggu review) jadi default. */
    private const TABS = [
        Member::REG_PENDING => 'Menunggu Review',
        Member::REG_UNVERIFIED => 'Belum Verifikasi E-mail',
        Member::REG_REJECTED => 'Ditolak',
        Member::REG_APPROVED => 'Disetujui',
    ];

    public function __construct(private MemberRegistrationService $registrations) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewRegistrations', Member::class);

        $params = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(self::TABS))],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $params['status'] ?? Member::REG_PENDING;

        $registrations = Member::query()
            ->registration($status)
            // Tab "Disetujui" hanya berisi yang masuk lewat form daftar (bukan data import)
            ->when($status === Member::REG_APPROVED, fn ($q) => $q->whereNotNull('registered_at'))
            ->search($params['q'] ?? null)
            ->with(['vehicles' => fn ($q) => $q->select('id', 'member_id', 'plat_kode', 'plat_nomor', 'plat_seri', 'vehicle_brand_id', 'vehicle_model_id', 'brand_other', 'model_other', 'is_primary')
                ->with('brand:id,name', 'vehicleModel:id,name'), 'reviewer:id,name'])
            // Antrian review: yang paling lama menunggu di atas; tab lain: terbaru di atas
            ->orderBy($status === Member::REG_PENDING ? 'email_verified_at' : 'registered_at', $status === Member::REG_PENDING ? 'asc' : 'desc')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        $counts = Member::query()
            ->whereIn('registration_status', [Member::REG_PENDING, Member::REG_UNVERIFIED])
            ->selectRaw('registration_status, COUNT(*) AS total')
            ->groupBy('registration_status')
            ->pluck('total', 'registration_status');

        return view('pages.cms.member-registrations.index', [
            'title' => 'Pendaftaran Member',
            'tabs' => self::TABS,
            'status' => $status,
            'counts' => $counts,
            'registrations' => $registrations,
        ]);
    }

    public function show(Member $member): View
    {
        Gate::authorize('viewRegistrations', Member::class);

        $member->load([
            'city:code,name', 'district:code,name', 'village:code,name',
            'vehicles' => fn ($q) => $q->with('brand:id,name', 'vehicleModel:id,name', 'documents')->orderByDesc('is_primary'),
            'reviewer:id,name',
        ]);

        return view('pages.cms.member-registrations.show', [
            'title' => 'Review Pendaftaran',
            'member' => $member,
            'tabs' => self::TABS,
        ]);
    }

    public function approve(Request $request, Member $member): RedirectResponse
    {
        Gate::authorize('review', $member);

        $member = $this->registrations->approve($member, $request->user());

        return redirect()->route('member-registrations.show', $member)
            ->with('success', "Pendaftaran disetujui sebagai {$member->noLambungLabel()}. E-mail pembayaran iuran pertama sudah dikirim ke {$member->email}.");
    }

    public function reject(Request $request, Member $member): RedirectResponse
    {
        Gate::authorize('review', $member);

        $reason = $request->validate(
            ['reason' => ['required', 'string', 'min:5', 'max:500']],
            [],
            ['reason' => 'alasan penolakan'],
        )['reason'];

        $this->registrations->reject($member, $request->user(), $reason);

        return redirect()->route('member-registrations.show', $member)
            ->with('success', 'Pendaftaran ditolak. Pendaftar sudah diberi tahu lewat e-mail.');
    }

    /**
     * Buka dokumen bukti kendaraan (disk privat). Dilayani inline + nosniff dengan MIME tersimpan;
     * upload hanya menerima JPG/PNG/WEBP/PDF (dicek dari isi file).
     */
    public function document(MemberVehicleDocument $document): StreamedResponse
    {
        Gate::authorize('viewRegistrations', Member::class);

        $disk = Storage::disk($document->disk);
        abort_unless($disk->exists($document->path), 404);

        return $disk->response($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }
}
