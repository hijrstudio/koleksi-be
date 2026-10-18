<?php

namespace App\Http\Controllers\Cms;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\SpkluOperatorRequest;
use App\Models\SpkluOperator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class SpkluOperatorController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', SpkluOperator::class);

        $status = ContentStatus::tryFrom((string) $request->query('status'));

        $operators = SpkluOperator::query()
            ->with('updater:id,name')
            ->search(mb_substr($request->string('search')->trim()->toString(), 0, 100))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->ordered()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.cms.spklu-operators.index', [
            'title' => 'SPKLU',
            'operators' => $operators,
            'statuses' => ContentStatus::cases(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', SpkluOperator::class);

        return $this->form(new SpkluOperator(['status' => ContentStatus::Draft]));
    }

    public function store(SpkluOperatorRequest $request): RedirectResponse
    {
        $this->save($request, new SpkluOperator);

        return redirect()->route('spklu-operators.index')->with('success', 'Operator SPKLU berhasil ditambahkan.');
    }

    public function edit(SpkluOperator $spkluOperator): View
    {
        Gate::authorize('update', $spkluOperator);

        return $this->form($spkluOperator);
    }

    public function update(SpkluOperatorRequest $request, SpkluOperator $spkluOperator): RedirectResponse
    {
        $this->save($request, $spkluOperator);

        return redirect()->route('spklu-operators.index')->with('success', 'Operator SPKLU berhasil diperbarui.');
    }

    public function destroy(SpkluOperator $spkluOperator): RedirectResponse
    {
        Gate::authorize('delete', $spkluOperator);

        $logo = $spkluOperator->logo;
        $spkluOperator->delete();

        if ($logo) {
            Storage::disk(SpkluOperator::DISK)->delete($logo);
        }

        return redirect()->route('spklu-operators.index')->with('success', 'Operator SPKLU berhasil dihapus.');
    }

    private function save(SpkluOperatorRequest $request, SpkluOperator $operator): void
    {
        $oldLogo = $operator->logo;
        $removeLogo = $request->removeLogo();
        $newLogo = $request->hasFile('logo')
            ? $request->file('logo')->store(SpkluOperator::DIRECTORY.'/'.now()->format('Y/m'), SpkluOperator::DISK)
            : null;

        try {
            DB::transaction(function () use ($request, $operator, $newLogo, $removeLogo) {
                $operator->fill($request->operatorData());

                if ($newLogo) {
                    $operator->logo = $newLogo;
                } elseif ($removeLogo) {
                    $operator->logo = null;
                }

                $operator->save();
            });
        } catch (Throwable $e) {
            if ($newLogo) {
                Storage::disk(SpkluOperator::DISK)->delete($newLogo);
            }

            throw $e;
        }

        // Logo lama baru dihapus setelah data baru tersimpan
        if ($oldLogo && ($newLogo || $removeLogo)) {
            Storage::disk(SpkluOperator::DISK)->delete($oldLogo);
        }
    }

    private function form(SpkluOperator $operator): View
    {
        return view('pages.cms.spklu-operators.form', [
            'title' => $operator->exists ? 'Edit Operator SPKLU' : 'Tambah Operator SPKLU',
            'operator' => $operator->loadMissing(['creator:id,name', 'updater:id,name']),
            'statuses' => ContentStatus::cases(),
        ]);
    }
}
