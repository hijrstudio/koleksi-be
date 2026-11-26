<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Payment;
use App\Services\TransactionReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Dashboard CMS: jumlah anggota (aktif / tidak aktif / pendaftar menunggu bayar), pemasukan bulan ini, dan
 * transaksi lunas terbaru. Setiap bagian hanya tampil untuk admin yang punya izinnya (members.view /
 * transactions.view). Angka di-cache 1 menit supaya dashboard tetap ringan walau dibuka banyak admin.
 */
class DashboardController extends Controller
{
    private const RECENT = 8;

    public function __construct(private TransactionReport $report) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $canMembers = $user->can('members.view');
        $canTransactions = $user->can('transactions.view');
        $now = now(TransactionReport::TZ);

        $members = $canMembers ? Cache::remember('cms:dashboard:members', now()->addMinute(), fn () => [
            'active' => Member::query()->registered()->active()->count(),
            'inactive' => Member::query()->registered()->inactive()->count(),
            'awaiting' => Member::query()->registration(Member::REG_AWAITING_PAYMENT)->count(),
        ]) : null;

        $month = $canTransactions ? Cache::remember('cms:dashboard:month:'.$now->format('Y-m'), now()->addMinute(), fn () => $this->report->summary([
            'from' => $now->copy()->startOfMonth()->toDateString(),
            'to' => $now->toDateString(),
            'status' => Payment::SUCCESS,
        ])) : null;

        $recent = collect();
        $details = null;
        if ($canTransactions) {
            $recent = collect($this->report->query(['status' => Payment::SUCCESS])
                ->orderByDesc('tx_at')->orderByDesc('row_id')->limit(self::RECENT)->get());
            $details = [
                'members' => Member::withTrashed()->whereIn('id', $recent->pluck('member_id')->unique())
                    ->get(['id', 'no_lambung', 'nama_lengkap', 'deleted_at'])->keyBy('id'),
            ];
        }

        return view('pages.dashboard.index', [
            'title' => 'Dashboard',
            'members' => $members,
            'month' => $month,
            'monthLabel' => $now->locale('id')->translatedFormat('F Y'),
            'recent' => $recent,
            'details' => $details,
        ]);
    }
}
