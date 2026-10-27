<?php

namespace App\Http\Controllers\Cms;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\GuideRegulationRequest;
use App\Models\GuideRegulation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class GuideRegulationController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', GuideRegulation::class);

        $status = ContentStatus::tryFrom((string) $request->query('status'));

        $regulations = GuideRegulation::query()
            ->with('updater:id,name')
            ->search($request->string('search')->trim()->toString())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.cms.guide-regulations.index', [
            'title' => 'Dokumen Regulasi',
            'regulations' => $regulations,
            'statuses' => ContentStatus::cases(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', GuideRegulation::class);

        return $this->form(new GuideRegulation(['status' => ContentStatus::Draft]));
    }

    public function store(GuideRegulationRequest $request): RedirectResponse
    {
        $this->save($request, new GuideRegulation);

        return redirect()->route('guide-regulations.index')->with('success', 'Dokumen regulasi berhasil ditambahkan.');
    }

    public function edit(GuideRegulation $guideRegulation): View
    {
        Gate::authorize('update', $guideRegulation);

        return $this->form($guideRegulation);
    }

    public function update(GuideRegulationRequest $request, GuideRegulation $guideRegulation): RedirectResponse
    {
        $this->save($request, $guideRegulation);

        return redirect()->route('guide-regulations.index')->with('success', 'Dokumen regulasi berhasil diperbarui.');
    }

    public function destroy(GuideRegulation $guideRegulation): RedirectResponse
    {
        Gate::authorize('delete', $guideRegulation);

        $file = $guideRegulation->file_pdf;
        $guideRegulation->delete();
        Storage::disk(GuideRegulation::PDF_DISK)->delete($file);

        return redirect()->route('guide-regulations.index')->with('success', 'Dokumen regulasi berhasil dihapus.');
    }

    /** Pratinjau PDF untuk admin (PDF ada di disk private, tidak punya URL publik). */
    public function pdf(GuideRegulation $guideRegulation): StreamedResponse
    {
        Gate::authorize('viewAny', GuideRegulation::class);

        return Storage::disk(GuideRegulation::PDF_DISK)->response(
            $guideRegulation->file_pdf,
            $guideRegulation->downloadName(),
            ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    private function save(GuideRegulationRequest $request, GuideRegulation $regulation): void
    {
        $oldFile = $regulation->file_pdf;
        $newFile = $request->hasFile('file_pdf')
            ? $request->file('file_pdf')->store(GuideRegulation::DIRECTORY.'/'.now()->format('Y/m'), GuideRegulation::PDF_DISK)
            : null;

        try {
            DB::transaction(function () use ($request, $regulation, $newFile) {
                $regulation->fill($request->regulationData());

                if ($newFile) {
                    $regulation->file_pdf = $newFile;
                }

                $regulation->save();
            });
        } catch (Throwable $e) {
            if ($newFile) {
                Storage::disk(GuideRegulation::PDF_DISK)->delete($newFile);
            }

            throw $e;
        }

        // File lama baru dihapus setelah data baru tersimpan
        if ($oldFile && $newFile) {
            Storage::disk(GuideRegulation::PDF_DISK)->delete($oldFile);
        }
    }

    private function form(GuideRegulation $regulation): View
    {
        return view('pages.cms.guide-regulations.form', [
            'title' => $regulation->exists ? 'Edit Dokumen Regulasi' : 'Tambah Dokumen Regulasi',
            'regulation' => $regulation->loadMissing(['creator:id,name', 'updater:id,name']),
            'statuses' => ContentStatus::cases(),
        ]);
    }
}
