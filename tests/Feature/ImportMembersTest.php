<?php

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(Carbon::parse('2026-10-08'));

    $this->xlsx = function (array $rows): string {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray(
            [['# Lambung', 'Nama', 'Email', 'Nomor HP', 'Tanggal Bergabung', '2021-2022', '2026-2027'], ...$rows]
        );
        $path = tempnam(sys_get_temp_dir(), 'members').'.xlsx';
        (new Xlsx($sheet))->save($path);

        return $path;
    };
});

test('import normalizes data, skips KOSONG rows and waives current period', function () {
    $file = ($this->xlsx)([
        [1, 'Arwani Hidayat', ' Arwani73@Gmail.com ', '0811 9331 011 ', '8 Agt 2021', null, null],
        [2, 'KOSONG', null, null, null, null, null],
        [6, 'Lihua Soedarmo / Aweng', 'aweng@x.com', '0812-9045-9246', '14 Agts 2021', null, null],
        [7, 'Tanpa Kontak', null, null, '25 Sept 2023', 'lunas', 'lunas'],   // 2021-2022 sebelum bergabung → diabaikan
        [8, 'HP Angka', 'dup@x.com', 81321752222, '19 Juni 2022', null, null],
        [9, 'Email Duplikat', 'DUP@x.com', null, 'tanggal ngawur', null, null],
    ]);

    $this->artisan('members:import', ['file' => $file, '--waive-current' => true])->assertSuccessful();

    expect(Member::count())->toBe(5);

    $m1 = Member::where('no_lambung', 1)->first();
    expect($m1->email)->toBe('arwani73@gmail.com')
        ->and($m1->no_hp)->toBe('08119331011')
        ->and($m1->tgl_bergabung->toDateString())->toBe('2021-08-08')
        ->and($m1->status)->toBe('active')
        ->and($m1->must_change_password)->toBeTrue()
        ->and(Hash::check('Koleksi1011', $m1->password))->toBeTrue();

    $m6 = Member::where('no_lambung', 6)->first();
    expect($m6->nama_lengkap)->toBe('Lihua Soedarmo')
        ->and($m6->nama_panggilan)->toBe('Aweng')
        ->and($m6->notes)->toContain('Lihua Soedarmo / Aweng')
        ->and($m6->no_hp)->toBe('081290459246')
        ->and($m6->tgl_bergabung->toDateString())->toBe('2021-08-14');

    expect(Member::where('no_lambung', 7)->first()->dues()->where('status', 'paid')->count())->toBe(1)
        ->and(Member::where('no_lambung', 8)->first()->no_hp)->toBe('081321752222');

    // Email duplikat dikosongkan, tanggal tak dikenali dikosongkan
    $m9 = Member::where('no_lambung', 9)->first();
    expect($m9->email)->toBeNull()->and($m9->tgl_bergabung)->toBeNull();

    expect(Storage::disk('local')->files('imports'))->toHaveCount(1);
});

test('dry run saves nothing', function () {
    $file = ($this->xlsx)([[1, 'Arwani Hidayat', 'a@x.com', '0811880331', '8 Agt 2021', null, null]]);

    $this->artisan('members:import', ['file' => $file, '--dry-run' => true])->assertSuccessful();

    expect(Member::count())->toBe(0);
});

test('re-import updates instead of duplicating and keeps passwords & manual status', function () {
    $file = ($this->xlsx)([[1, 'Arwani Hidayat', 'a@x.com', '0811880331', '8 Agt 2021', null, null]]);
    $this->artisan('members:import', ['file' => $file])->assertSuccessful();

    $member = Member::first();
    $member->forceFill(['password' => 'GantiSendiri123', 'status' => 'active', 'status_mode' => 'manual'])->save();

    $file2 = ($this->xlsx)([[1, 'Arwani Hidayat S.', 'a@x.com', '0811880331', '8 Agt 2021', null, null]]);
    $this->artisan('members:import', ['file' => $file2])->assertSuccessful();

    $member->refresh();
    expect(Member::count())->toBe(1)
        ->and($member->nama_lengkap)->toBe('Arwani Hidayat S.')
        ->and(Hash::check('GantiSendiri123', $member->password))->toBeTrue()
        ->and($member->status)->toBe('active');
});
