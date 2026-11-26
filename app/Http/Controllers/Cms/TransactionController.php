<?php

namespace App\Http\Controllers\Cms;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberDue;
use App\Models\Payment;
use App\Models\User;
use App\Payments\PaymentReconciler;
use App\Services\TransactionReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan transaksi: QRIS YODU + iuran yang dicatat admin (tanpa import data lama), dengan ringkasan, ekspor CSV, dan
 * rekonsiliasi per transaksi QRIS ke API cek status YODU (izin transactions.*).
 */
class TransactionController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private TransactionReport $report) {}

    public function index(Request $request): View
    {
        Gate::authorize('transactions.view');

        $filters = $this->filters($request);
        $page = $this->report->query($filters)
            ->orderByDesc('tx_at')->orderByDesc('row_id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return view('pages.cms.transactions.index', [
            'title' => 'Laporan Transaksi',
            'filters' => $filters,
            'rows' => $page,
            'details' => $this->details(collect($page->items())),
            'summary' => $this->report->summary($filters),
        ]);
    }

    /** CSV (UTF-8 + BOM supaya rapi di Excel), mengikuti filter yang aktif. Dibaca per potongan (hemat memori). */
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('transactions.export');

        $filters = $this->filters($request);
        $name = 'transaksi-'.now(TransactionReport::TZ)->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($filters) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Tanggal (WIB)', 'Sumber', 'Status', 'No. Lambung', 'Nama', 'Referensi', 'Ref. YODU', 'Metode / Channel', 'Nominal', 'Neto', 'MDR', 'Dicatat oleh', 'Cek YODU', 'Catatan cek']);

            $this->report->query($filters)->orderByDesc('tx_at')->orderByDesc('row_id')
                ->chunk(500, function (Collection $rows) use ($out) {
                    $d = $this->details($rows);
                    foreach ($rows as $row) {
                        $member = $d['members'][$row->member_id] ?? null;
                        $payment = $row->source === 'qris' ? ($d['payments'][$row->row_id] ?? null) : null;
                        fputcsv($out, array_map([self::class, 'csvSafe'], [
                            self::wib($row->tx_at),
                            self::rowLabel($row),
                            $row->status,
                            $member?->noLambungLabel(),
                            $member?->nama_lengkap,
                            $row->ref,
                            $row->gateway_ref,
                            $row->method,
                            $row->amount,
                            $row->net_amount,
                            $row->mdr_amount,
                            $row->recorded_by ? ($d['admins'][$row->recorded_by] ?? 'Admin') : '',
                            $payment?->reconcile_status,
                            $payment?->reconcile_note,
                        ]));
                    }
                });

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Cek status satu transaksi QRIS ke YODU (hanya baca) dan simpan hasil perbandingannya. */
    public function reconcile(Request $request, Payment $payment, PaymentReconciler $reconciler): JsonResponse
    {
        Gate::authorize('transactions.verify');

        return response()->json($reconciler->check($payment, $this->admin($request)) + [
            'checked_at' => now(TransactionReport::TZ)->format('d M Y H:i'),
        ]);
    }

    /** YODU sudah LUNAS tapi sistem belum → terapkan lewat alur pembayaran resmi. */
    public function sync(Request $request, Payment $payment, PaymentReconciler $reconciler): JsonResponse
    {
        Gate::authorize('transactions.verify');

        $paid = $reconciler->sync($payment, $this->admin($request));
        $payment->refresh();

        return response()->json([
            'paid' => $paid,
            'status' => $payment->reconcile_status,
            'note' => $paid ? 'Pembayaran disinkronkan: iuran member sudah lunas.' : ($payment->failure_reason ?: $payment->reconcile_note),
        ], $paid ? 200 : 422);
    }

    /** @return array{from: ?string, to: ?string, source: ?string, status: string, search: ?string} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'source' => ['nullable', Rule::in(TransactionReport::SOURCES)],
            'status' => ['nullable', Rule::in(['all', Payment::SUCCESS, Payment::PENDING, Payment::EXPIRED, Payment::FAILED, Payment::CANCELLED, Payment::REFUNDED])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return [
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'source' => $data['source'] ?? null,
            'status' => $data['status'] ?? Payment::SUCCESS,
            'search' => $data['search'] ?? null,
        ];
    }

    /**
     * Data pelengkap untuk baris satu halaman: member, pembayaran QRIS (+ tagihan yang dilunasi), iuran admin,
     * nama admin — masing-masing satu query.
     *
     * @return array{members: Collection, payments: Collection, dues: Collection, admins: Collection}
     */
    private function details(Collection $rows): array
    {
        $qrisIds = $rows->where('source', 'qris')->pluck('row_id')->all();
        $dueIds = $rows->where('source', '!=', 'qris')->pluck('row_id')->all();

        return [
            'members' => Member::withTrashed()->whereIn('id', $rows->pluck('member_id')->unique())
                ->get(['id', 'no_lambung', 'nama_lengkap', 'deleted_at'])->keyBy('id'),
            'payments' => Payment::query()->whereIn('id', $qrisIds)
                ->with(['dues' => fn ($q) => $q->select(['member_dues.id', 'member_dues.invoice_number', 'member_dues.period_label'])])
                ->get(['id', 'gateway', 'failure_reason', 'reconciled_at', 'reconcile_status', 'reconcile_note', 'expired_at', 'status'])
                ->keyBy('id'),
            'dues' => MemberDue::query()->whereIn('id', $dueIds)
                ->get(['id', 'period_label', 'notes'])->keyBy('id'),
            'admins' => User::query()->whereIn('id', $rows->pluck('recorded_by')->filter()->unique())->pluck('name', 'id'),
        ];
    }

    public static function sourceLabel(string $source): string
    {
        return [
            'registration' => 'QRIS Pendaftaran',
            'dues' => 'QRIS Iuran',
            'admin' => 'Dicatat admin',
        ][$source] ?? $source;
    }

    /** Label baris: QRIS menurut tujuannya (pendaftaran / iuran), selain itu sumbernya. */
    public static function rowLabel(object $row): string
    {
        return self::sourceLabel($row->source === 'qris' ? ($row->purpose ?: 'dues') : $row->source);
    }

    public static function wib(?string $value): string
    {
        return $value ? Carbon::parse($value, config('app.timezone'))->timezone(TransactionReport::TZ)->format('d M Y H:i') : '-';
    }

    /** Cegah formula injection di Excel (nilai diawali = + - @). */
    public static function csvSafe(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private function admin(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
