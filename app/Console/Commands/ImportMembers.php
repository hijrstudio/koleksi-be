<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\MemberDue;
use App\Services\MembershipService;
use App\Support\PhoneNumber;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Import data anggota dari Excel "Data_Anggota_Koleksi.xlsx".
 *
 * Kolom: # Lambung | Nama | Email | Nomor HP | Tanggal Bergabung | 2021-2022 ... 2029-2030
 *
 *   php artisan members:import storage/app/private/imports/Data_Anggota_Koleksi.xlsx --dry-run
 *   php artisan members:import storage/app/private/imports/Data_Anggota_Koleksi.xlsx --waive-current
 *
 * Periode keanggotaan dihitung per member dari tanggal bergabung. Kolom iuran "2021-2022" berarti
 * periode member yang dimulai pada ulang tahun keanggotaannya di 2021.
 *
 * Sel iuran di Excel umumnya kosong dan hanya diberi warna latar:
 *   hijau → lunas (paid) | merah → belum bayar (tagihan unpaid) | biru → FOC/gratis (waived)
 * Sel yang berisi teks dianggap lunas. Status aktif/tidak dihitung dari iuran yang lunas
 * (MembershipService::refreshStatus), jadi --waive-current tidak perlu dipakai untuk file ini.
 *
 * Aman dijalankan ulang: member dicocokkan berdasarkan no_lambung (update, bukan duplikat),
 * password member yang sudah ada TIDAK diubah kecuali pakai --reset-password,
 * dan status yang sudah ditetapkan manual oleh admin tidak ditimpa.
 */
class ImportMembers extends Command
{
    protected $signature = 'members:import
        {file : Path file .xlsx}
        {--password= : Password default yang sama untuk semua member baru (kosongkan = "Koleksi" + 4 digit terakhir No. HP)}
        {--waive-current : Bebaskan iuran periode berjalan tiap member (dihitung dari tanggal bergabungnya) → member jadi aktif}
        {--reset-password : Reset juga password member yang sudah ada}
        {--dry-run : Simulasi saja, tidak menyimpan apa pun}';

    protected $description = 'Import data anggota KOLEKSI dari Excel';

    /** Bulan Indonesia & Inggris; dicocokkan dari 3 huruf pertama ("Agt", "Agust", "Agts", "Sept", "Juni" ...). */
    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mei' => 5, 'may' => 5,
        'jun' => 6, 'jul' => 7, 'agt' => 8, 'agu' => 8, 'ags' => 8, 'aug' => 8,
        'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11, 'des' => 12, 'dec' => 12,
    ];

    /** Warna latar sel iuran (RGB) → arti. */
    private const DUE_COLORS = [
        '92D050' => MemberDue::PAID,    // hijau
        'FF0000' => MemberDue::UNPAID,  // merah
        '00B0F0' => MemberDue::WAIVED,  // biru (FOC)
    ];

    private array $warnings = [];

    private array $credentials = [];

    public function handle(MembershipService $membership): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error("File tidak ditemukan: {$path}");

            return self::FAILURE;
        }

        $sheet = IOFactory::load($path)->getActiveSheet();
        $rows = $sheet->toArray(null, false, false, false);
        $header = array_shift($rows);
        $col = $this->mapHeader($header);

        if (! isset($col['lambung'], $col['nama'])) {
            $this->error('Header tidak dikenali. Minimal harus ada kolom "# Lambung" dan "Nama".');

            return self::FAILURE;
        }

        $stats = ['created' => 0, 'updated' => 0, 'skipped_kosong' => 0, 'skipped_invalid' => 0, 'dues_paid' => 0, 'dues_unpaid' => 0, 'dues_foc' => 0, 'waived' => 0];
        $seenEmail = $seenPhone = [];

        DB::beginTransaction();

        try {
            $bar = $this->output->createProgressBar(count($rows));

            foreach ($rows as $i => $row) {
                $bar->advance();
                $line = $i + 2; // nomor baris di Excel

                $lambung = (int) ($row[$col['lambung']] ?? 0);
                $rawName = $this->cleanText($row[$col['nama']] ?? '');

                if ($lambung <= 0) {
                    if ($rawName !== '') {
                        $this->addWarning("Baris {$line}: # Lambung kosong/tidak valid, dilewati ({$rawName})");
                        $stats['skipped_invalid']++;
                    }

                    continue;
                }

                if ($rawName === '' || Str::startsWith(Str::upper($rawName), 'KOSONG')) {
                    $stats['skipped_kosong']++;

                    continue;
                }

                [$namaLengkap, $alias] = $this->splitAlias($rawName);

                // Email
                $email = isset($col['email']) ? $this->normalizeEmail($row[$col['email']] ?? null) : null;
                if ($email === false) {
                    $this->addWarning("Baris {$line} (#{$lambung}): email tidak valid \"{$row[$col['email']]}\", dikosongkan");
                    $email = null;
                }
                if ($email && (isset($seenEmail[$email]) || $this->takenBy('email', $email, $lambung))) {
                    $this->addWarning("Baris {$line} (#{$lambung}): email {$email} sudah dipakai member lain, dikosongkan");
                    $email = null;
                }

                // No. HP
                $phone = isset($col['hp']) ? $this->normalizePhone($row[$col['hp']] ?? null) : null;
                if ($phone === false) {
                    $this->addWarning("Baris {$line} (#{$lambung}): No. HP tidak valid \"{$row[$col['hp']]}\", dikosongkan");
                    $phone = null;
                }
                if ($phone && (isset($seenPhone[$phone]) || $this->takenBy('no_hp', $phone, $lambung))) {
                    $this->addWarning("Baris {$line} (#{$lambung}): No. HP {$phone} sudah dipakai member lain, dikosongkan");
                    $phone = null;
                }

                // Tanggal bergabung
                $joined = null;
                if (isset($col['tgl']) && ($raw = $row[$col['tgl']] ?? null) !== null && $raw !== '') {
                    $joined = $this->parseDate($raw);
                    if (! $joined) {
                        $this->addWarning("Baris {$line} (#{$lambung}): tanggal \"{$raw}\" tidak dikenali, dikosongkan");
                    }
                }

                if ($email) {
                    $seenEmail[$email] = true;
                }
                if ($phone) {
                    $seenPhone[$phone] = true;
                }

                /** @var Member $member */
                $member = Member::withTrashed()->firstOrNew(['no_lambung' => $lambung]);
                $isNew = ! $member->exists;

                $member->fill([
                    'nama_lengkap' => $namaLengkap,
                    'email' => $email,
                    'no_hp' => $phone,
                    'tgl_bergabung' => $joined,
                ]);

                // Jangan timpa data yang mungkin sudah dilengkapi member sendiri
                if ($isNew || blank($member->nama_panggilan)) {
                    $member->nama_panggilan = $alias;
                }
                if ($alias && blank($member->notes)) {
                    $member->notes = "Nama di Excel: {$rawName}";
                }

                if ($isNew || $this->option('reset-password')) {
                    $password = $this->makePassword($phone);
                    $member->password = $password;                 // di-hash otomatis (cast 'hashed')
                    $member->must_change_password = true;
                    $this->credentials[] = [$lambung, $namaLengkap, $email, $phone, $password];
                }

                $member->deleted_at = null;
                $member->save();
                $stats[$isNew ? 'created' : 'updated']++;

                // Kolom iuran 2021-2022 ... 2029-2030: warna sel (atau isi sel) → status tagihan periode tsb
                foreach ($col['periods'] ?? [] as $startYear => $idx) {
                    $value = $this->cleanText($row[$idx] ?? '');
                    $status = $this->dueStatus($sheet, $idx, $line, $value);
                    if ($status === null) {
                        continue;
                    }
                    $label = $startYear.'-'.($startYear + 1);
                    if ($startYear < $member->membershipAnchor()->year) {
                        // Baris FOC diwarnai biru penuh sejak 2021, wajar kalau sebelum tanggal bergabung
                        if ($status !== MemberDue::WAIVED) {
                            $this->addWarning("Baris {$line} (#{$lambung}): iuran {$label} ({$status}) sebelum tanggal bergabung, diabaikan");
                        }

                        continue;
                    }

                    $term = $membership->termForYear($member, $startYear);

                    match ($status) {
                        MemberDue::PAID => $membership->markPaid(
                            $membership->invoiceFor($member, $term),
                            'transfer',
                            $term->start->isFuture() ? now() : $term->start->copy(), // tanggal bayar asli tidak tercatat
                            null,
                            'Import Excel: '.($value !== '' ? $value : 'lunas (sel hijau)'),
                        ),
                        MemberDue::WAIVED => $membership->waive($member, $term, null, 'Import Excel: FOC (sel biru)'),
                        MemberDue::UNPAID => $membership->invoiceFor($member, $term),
                    };

                    $stats[match ($status) {
                        MemberDue::PAID => 'dues_paid',
                        MemberDue::WAIVED => 'dues_foc',
                        MemberDue::UNPAID => 'dues_unpaid',
                    }]++;
                }

                if ($this->option('waive-current')) {
                    $membership->waive($member, $membership->currentTerm($member), null, 'Migrasi data awal (import Excel)');
                    $stats['waived']++;
                } else {
                    $membership->refreshStatus($member);
                }
            }

            $bar->finish();
            $this->newLine(2);

            if ($this->option('dry-run')) {
                DB::rollBack();
                $this->warn('DRY RUN — tidak ada data yang disimpan.');
            } else {
                DB::commit();
                $this->writeCredentials();
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Import dibatalkan: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Hasil', 'Jumlah'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values());

        if ($this->warnings) {
            $this->newLine();
            $this->warn(count($this->warnings).' peringatan:');
            foreach ($this->warnings as $w) {
                $this->line("  - {$w}");
            }
        }

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------

    /**
     * Index kolom per field. `periods`: tahun mulai periode ("2021-2022" → 2021) => index kolom.
     *
     * @return array{lambung?: int, nama?: int, email?: int, hp?: int, tgl?: int, periods: array<int, int>}
     */
    private function mapHeader(array $header): array
    {
        $map = ['periods' => []];

        foreach ($header as $i => $h) {
            $key = Str::lower(trim((string) $h));

            match (true) {
                preg_match('/^(\d{4})-\d{4}$/', $key, $m) === 1 => $map['periods'][(int) $m[1]] = $i,
                str_contains($key, 'lambung') => $map['lambung'] = $i,
                $key === 'nama' || $key === 'nama lengkap' => $map['nama'] = $i,
                str_contains($key, 'email') => $map['email'] = $i,
                str_contains($key, 'hp') || str_contains($key, 'telepon') => $map['hp'] = $i,
                str_contains($key, 'bergabung') => $map['tgl'] = $i,
                default => null,
            };
        }

        return $map;
    }

    /**
     * Status tagihan dari 1 sel iuran: teks → lunas, selain itu dari warna latar (DUE_COLORS).
     * null = sel kosong tanpa warna (tidak ada tagihan).
     */
    private function dueStatus(Worksheet $sheet, int $idx, int $line, string $value): ?string
    {
        if ($value !== '') {
            return MemberDue::PAID;
        }

        $fill = $sheet->getStyle([$idx + 1, $line])->getFill();
        if ($fill->getFillType() === null || $fill->getFillType() === Fill::FILL_NONE) {
            return null;
        }

        $rgb = strtoupper($fill->getStartColor()->getRGB());
        if (! isset(self::DUE_COLORS[$rgb])) {
            $this->addWarning("Baris {$line}: warna sel iuran #{$rgb} tidak dikenali, diabaikan");

            return null;
        }

        return self::DUE_COLORS[$rgb];
    }

    private function cleanText(mixed $v): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $v));
    }

    /** "Lihua Soedarmo / Aweng" → ["Lihua Soedarmo", "Aweng"] */
    private function splitAlias(string $name): array
    {
        if (! str_contains($name, '/')) {
            return [$name, null];
        }

        [$main, $alias] = array_map('trim', explode('/', $name, 2));

        return [$main !== '' ? $main : $name, $alias !== '' ? Str::limit($alias, 60, '') : null];
    }

    /** @return string|null|false false = tidak valid */
    private function normalizeEmail(mixed $v): string|null|false
    {
        $v = Str::lower(trim((string) $v));
        if ($v === '') {
            return null;
        }

        return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : false;
    }

    /** @return string|null|false false = tidak valid */
    private function normalizePhone(mixed $v): string|null|false
    {
        return PhoneNumber::normalize($v);
    }

    /** "8 Agt 2021", "25 Sept 2023", "19 Juni 2022", "14 Agts 2021", serial Excel, DateTime */
    private function parseDate(mixed $v): ?Carbon
    {
        try {
            if ($v instanceof DateTimeInterface) {
                return Carbon::instance($v)->startOfDay();
            }
            if (is_numeric($v)) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v))->startOfDay();
            }

            $v = trim((string) $v);

            if (preg_match('/^(\d{1,2})[\s\-\/]+([A-Za-z]+)\.?[\s\-\/]+(\d{4})$/', $v, $m)) {
                $month = self::MONTHS[Str::lower(substr($m[2], 0, 3))] ?? null;

                return $month && checkdate($month, (int) $m[1], (int) $m[3])
                    ? Carbon::create((int) $m[3], $month, (int) $m[1])
                    : null;
            }

            return Carbon::parse($v)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function takenBy(string $column, string $value, int $lambung): bool
    {
        return Member::withTrashed()
            ->where($column, $value)
            ->where('no_lambung', '!=', $lambung)
            ->exists();
    }

    private function makePassword(?string $phone): string
    {
        if ($fixed = $this->option('password')) {
            return $fixed;
        }

        // "Koleksi" + 4 digit terakhir No. HP; tanpa HP → 4 digit acak
        $suffix = $phone ? substr($phone, -4) : str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        return 'Koleksi'.$suffix;
    }

    private function writeCredentials(): void
    {
        if (! $this->credentials) {
            return;
        }

        $csv = "no_lambung,nama,email,no_hp,password_awal\n";
        foreach ($this->credentials as $r) {
            $csv .= implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $r))."\n";
        }

        $file = 'imports/member-credentials-'.now()->format('Ymd-His').'.csv';
        Storage::disk('local')->put($file, $csv);

        $this->newLine();
        $this->info('Password awal disimpan di: '.Storage::disk('local')->path($file));
        $this->warn('File ini berisi password — bagikan ke member lalu HAPUS file-nya.');
    }

    private function addWarning(string $msg): void
    {
        $this->warnings[] = $msg;
    }
}
