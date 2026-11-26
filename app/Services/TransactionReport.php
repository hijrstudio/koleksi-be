<?php

namespace App\Services;

use App\Models\MemberDue;
use App\Models\Payment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Laporan transaksi CMS: satu daftar gabungan
 *
 *   qris   : pembayaran QRIS (tabel payments, gateway YODU) — purpose: registration (pendaftaran) | dues (iuran)
 *   admin  : iuran yang dicatat lunas oleh admin di CMS (tunai / transfer, recorded_by terisi)
 *
 * Filter sumber: registration / dues (QRIS menurut tujuan) atau admin.
 *
 * Iuran lunas hasil import data lama (Excel, tanpa admin pencatat) tidak ditampilkan.
 * Iuran lunas via QRIS ("online") tidak diambil dari member_dues supaya tidak terhitung dua kali — sudah ada
 * sebagai baris QRIS. Iuran yang dibebaskan (waived / amnesty) bukan transaksi uang, jadi tidak ikut.
 *
 * Semua filter dijalankan di database (UNION + paginate), aman untuk ribuan transaksi.
 */
class TransactionReport
{
    public const SOURCES = ['registration', 'dues', 'admin'];

    public const TZ = 'Asia/Jakarta';

    /**
     * @param  array{from?: ?string, to?: ?string, source?: ?string, status?: ?string, search?: ?string}  $filters
     */
    public function query(array $filters): Builder
    {
        $source = $filters['source'] ?? null;
        // Default "Lunas": QRIS yang berhasil. "all" = semua status QRIS (untuk cek QR pending / kedaluwarsa).
        $status = $filters['status'] ?? Payment::SUCCESS;
        [$from, $to] = $this->range($filters['from'] ?? null, $filters['to'] ?? null);
        $search = trim((string) ($filters['search'] ?? ''));

        $qris = DB::table('payments as p')
            ->join('members as m', 'm.id', '=', 'p.member_id')
            ->selectRaw("'qris' as source, p.purpose, p.id as row_id, p.member_id, COALESCE(p.paid_at, p.created_at) as tx_at, p.amount, p.net_amount, p.mdr_amount, p.merchant_ref as ref, p.gateway_ref, p.payment_channel as method, p.status, NULL as recorded_by")
            ->when($status !== 'all', fn ($q) => $q->where('p.status', $status))
            ->when(in_array($source, ['registration', 'dues'], true), fn ($q) => $q->where('p.purpose', $source));

        $dues = DB::table('member_dues as d')
            ->join('members as m', 'm.id', '=', 'd.member_id')
            ->selectRaw("'admin' as source, NULL as purpose, d.id as row_id, d.member_id, d.paid_at as tx_at, d.amount, NULL as net_amount, NULL as mdr_amount, d.invoice_number as ref, NULL as gateway_ref, d.payment_method as method, 'success' as status, d.recorded_by")
            ->where('d.status', MemberDue::PAID)
            ->whereIn('d.payment_method', ['cash', 'transfer'])
            ->whereNotNull('d.recorded_by');   // tanpa admin pencatat = import data lama → tidak ditampilkan

        foreach ([[$qris, 'COALESCE(p.paid_at, p.created_at)'], [$dues, 'd.paid_at']] as [$q, $column]) {
            $q->when($from, fn ($w) => $w->whereRaw("{$column} >= ?", [$from]))
                ->when($to, fn ($w) => $w->whereRaw("{$column} < ?", [$to]))
                ->when($search !== '', fn ($w) => $this->search($w, $search, $q === $qris ? 'p.merchant_ref' : 'd.invoice_number', $q === $qris ? 'p.gateway_ref' : null));
        }

        // Iuran yang dicatat admin selalu lunas: tidak ikut bila yang dicari status QRIS lain (pending, expired, ...)
        $duesMatchStatus = in_array($status, ['all', Payment::SUCCESS], true);
        $parts = match ($source) {
            'registration', 'dues' => [$qris],
            'admin' => $duesMatchStatus ? [$dues] : [$qris->whereRaw('1 = 0')],
            default => $duesMatchStatus ? [$qris, $dues] : [$qris],
        };
        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        return DB::query()->fromSub($union, 't');
    }

    /** @return array{count: int, gross: int, net: int, mdr: int, by_source: array<string, array{count: int, gross: int}>} by_source: registration | dues | admin */
    public function summary(array $filters): array
    {
        $rows = (clone $this->query($filters))
            ->selectRaw("CASE WHEN source = 'qris' THEN purpose ELSE source END as kind, COUNT(*) as c, COALESCE(SUM(amount), 0) as gross, COALESCE(SUM(net_amount), 0) as net, COALESCE(SUM(mdr_amount), 0) as mdr")
            ->where('status', Payment::SUCCESS)
            ->groupByRaw("CASE WHEN source = 'qris' THEN purpose ELSE source END")->get();

        $bySource = collect(self::SOURCES)->mapWithKeys(fn (string $s) => [$s => ['count' => 0, 'gross' => 0]])->all();
        foreach ($rows as $row) {
            $bySource[$row->kind] = ['count' => (int) $row->c, 'gross' => (int) $row->gross];
        }

        return [
            'count' => (int) $rows->sum('c'),
            'gross' => (int) $rows->sum('gross'),
            'net' => (int) $rows->sum('net'),
            'mdr' => (int) $rows->sum('mdr'),
            'by_source' => $bySource,
        ];
    }

    /** Tanggal WIB (Y-m-d) → batas waktu aplikasi [from, to). */
    public function range(?string $from, ?string $to): array
    {
        $parse = fn (?string $d) => $d ? Carbon::createFromFormat('!Y-m-d', $d, self::TZ) : null;

        return [
            $parse($from)?->setTimezone(config('app.timezone'))->toDateTimeString(),
            $parse($to)?->addDay()->setTimezone(config('app.timezone'))->toDateTimeString(),
        ];
    }

    /** Nama, No. lambung (#7 / 7), atau nomor referensi / invoice. */
    private function search(Builder $q, string $term, string $refColumn, ?string $gatewayRefColumn): void
    {
        $like = '%'.addcslashes($term, '%_\\').'%';
        $q->where(function (Builder $w) use ($term, $like, $refColumn, $gatewayRefColumn) {
            $w->where('m.nama_lengkap', 'like', $like)
                ->orWhere('m.nama_panggilan', 'like', $like)
                ->orWhere($refColumn, 'like', $like);
            if ($gatewayRefColumn) {
                $w->orWhere($gatewayRefColumn, 'like', $like);
            }
            if (preg_match('/^#?\s*(\d{1,6})$/', $term, $m)) {
                $w->orWhere('m.no_lambung', (int) $m[1]);
            }
        });
    }
}
