<?php

namespace App\Http\Controllers\Cms;

use App\Enums\ContentStatus;
use App\Enums\ManagementGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\ManagementPeriodRequest;
use App\Models\ManagementMember;
use App\Models\ManagementPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class ManagementPeriodController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ManagementPeriod::class);

        $status = ContentStatus::tryFrom((string) $request->query('status'));

        $periods = ManagementPeriod::query()
            ->with('updater:id,name')
            ->withCount([
                'members as penasihat_count' => fn ($q) => $q->where('group', ManagementGroup::Penasihat),
                'members as bidang_count' => fn ($q) => $q->where('group', ManagementGroup::Bidang),
            ])
            ->search($request->string('search')->trim()->toString())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('start_year')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.cms.management.index', [
            'title' => 'Kepengurusan',
            'periods' => $periods,
            'statuses' => ContentStatus::cases(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', ManagementPeriod::class);

        return $this->form(new ManagementPeriod(['status' => ContentStatus::Draft]));
    }

    public function store(ManagementPeriodRequest $request): RedirectResponse
    {
        $this->save($request, new ManagementPeriod);

        return redirect()->route('management-periods.index')->with('success', 'Kepengurusan berhasil ditambahkan.');
    }

    public function edit(ManagementPeriod $managementPeriod): View
    {
        Gate::authorize('update', $managementPeriod);

        return $this->form($managementPeriod);
    }

    public function update(ManagementPeriodRequest $request, ManagementPeriod $managementPeriod): RedirectResponse
    {
        $this->save($request, $managementPeriod);

        return redirect()->route('management-periods.index')->with('success', 'Kepengurusan berhasil diperbarui.');
    }

    public function destroy(ManagementPeriod $managementPeriod): RedirectResponse
    {
        Gate::authorize('delete', $managementPeriod);

        $photos = DB::transaction(function () use ($managementPeriod) {
            $photos = $managementPeriod->members()->whereNotNull('photo')->pluck('photo')->all();
            $managementPeriod->delete(); // pengurus ikut terhapus (cascade)

            return $photos;
        });

        Storage::disk(ManagementMember::DISK)->delete($photos);

        return redirect()->route('management-periods.index')->with('success', 'Kepengurusan berhasil dihapus.');
    }

    /**
     * Simpan periode + sinkronisasi pengurus dalam satu transaksi:
     * baris dengan id di-update, tanpa id dibuat baru, yang tidak dikirim lagi dihapus.
     */
    private function save(ManagementPeriodRequest $request, ManagementPeriod $period): void
    {
        $rows = $request->memberRows();
        $newPhotos = [];

        try {
            // Upload di luar transaksi agar lock DB tidak tertahan selama I/O file
            foreach ($rows as $index => $row) {
                if ($row['photo']) {
                    $newPhotos[$index] = $row['photo']->store(
                        ManagementMember::DIRECTORY.'/'.now()->format('Y/m'),
                        ManagementMember::DISK
                    );
                }
            }

            $obsoletePhotos = DB::transaction(function () use ($request, $period, $rows, $newPhotos) {
                $period->fill($request->safe()->only(['start_year', 'end_year', 'status']))->save();

                $existing = $period->exists
                    ? $period->members()->getQuery()->lockForUpdate()->get()->keyBy('id')
                    : collect();

                $obsolete = [];
                $keptIds = [];

                foreach ($rows as $index => $row) {
                    $member = $row['id'] ? $existing->get($row['id']) : $period->members()->make();
                    abort_if($member === null, 409, 'Data pengurus sudah berubah, muat ulang halaman.');

                    $attributes = $row['attributes'];

                    if (isset($newPhotos[$index])) {
                        $attributes['photo'] = $newPhotos[$index];
                    } elseif ($row['remove_photo']) {
                        $attributes['photo'] = null;
                    }

                    if (array_key_exists('photo', $attributes) && $member->photo) {
                        $obsolete[] = $member->photo;
                    }

                    $member->fill($attributes)->save();
                    $keptIds[] = $member->id;
                }

                // Eloquent Collection::except() memfilter by primary key & me-reindex, jadi pakai modelKeys()
                $removed = $existing->except($keptIds);

                if ($removed->isNotEmpty()) {
                    $obsolete = [...$obsolete, ...$removed->pluck('photo')->filter()->all()];
                    ManagementMember::query()->whereKey($removed->modelKeys())->delete();
                }

                // Jika yang berubah hanya pengurus, tetap catat di updated_at / updated_by periode
                if (! $period->wasRecentlyCreated && ! $period->wasChanged()) {
                    $period->touch();
                }

                return $obsolete;
            });
        } catch (Throwable $e) {
            Storage::disk(ManagementMember::DISK)->delete(array_values($newPhotos));

            throw $e;
        }

        // Foto lama baru dihapus setelah data baru tersimpan
        Storage::disk(ManagementMember::DISK)->delete($obsoletePhotos);
    }

    private function form(ManagementPeriod $period): View
    {
        return view('pages.cms.management.form', [
            'title' => $period->exists ? 'Edit Kepengurusan' : 'Tambah Kepengurusan',
            'period' => $period->loadMissing(['creator:id,name', 'updater:id,name']),
            'statuses' => ContentStatus::cases(),
            'groups' => ManagementGroup::cases(),
            'rows' => $this->formRows($period),
        ]);
    }

    /**
     * Data awal repeater per grup: dari old input (setelah gagal validasi), data tersimpan, atau jabatan bawaan.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function formRows(ManagementPeriod $period): array
    {
        $members = $period->exists ? $period->members()->get()->keyBy('id') : collect();
        $rows = array_fill_keys(array_column(ManagementGroup::cases(), 'value'), []);
        $old = old('members');

        if (is_array($old)) {
            foreach ($old as $key => $input) {
                $group = ManagementGroup::tryFrom((string) ($input['group'] ?? ''));

                if (! $group || ! is_array($input)) {
                    continue;
                }

                $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT) ?: null;

                $rows[$group->value][] = [
                    'key' => (string) $key,
                    'id' => $id,
                    'name' => (string) ($input['name'] ?? ''),
                    'position' => [
                        'id' => (string) ($input['position']['id'] ?? ''),
                        'en' => (string) ($input['position']['en'] ?? ''),
                    ],
                    'photo_url' => $id ? $members->get($id)?->photo_url : null,
                    'remove_photo' => filter_var($input['remove_photo'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ];
            }

            return $rows;
        }

        if ($period->exists) {
            foreach ($members as $member) {
                $rows[$member->group->value][] = [
                    'key' => 'm'.$member->id,
                    'id' => $member->id,
                    'name' => $member->name,
                    'position' => ['id' => $member->position['id'] ?? '', 'en' => $member->position['en'] ?? ''],
                    'photo_url' => $member->photo_url,
                    'remove_photo' => false,
                ];
            }

            return $rows;
        }

        foreach (ManagementGroup::cases() as $group) {
            foreach ($group->defaultPositions() as $position) {
                $rows[$group->value][] = [
                    'key' => 'n'.Str::lower(Str::random(8)),
                    'id' => null,
                    'name' => '',
                    'position' => $position,
                    'photo_url' => null,
                    'remove_photo' => false,
                ];
            }
        }

        return $rows;
    }
}
