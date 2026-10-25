<?php

namespace App\Http\Controllers\Cms;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\GuidePlaybookRequest;
use App\Models\GuidePlaybook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class GuidePlaybookController extends Controller
{
    private const PER_PAGE = 12;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', GuidePlaybook::class);

        $status = ContentStatus::tryFrom((string) $request->query('status'));

        $playbooks = GuidePlaybook::query()
            ->with('updater:id,name')
            ->search($request->string('search')->trim()->toString())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.cms.guide-playbooks.index', [
            'title' => 'Web Playbook',
            'playbooks' => $playbooks,
            'statuses' => ContentStatus::cases(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', GuidePlaybook::class);

        return $this->form(new GuidePlaybook(['status' => ContentStatus::Draft]));
    }

    public function store(GuidePlaybookRequest $request): RedirectResponse
    {
        $this->save($request, new GuidePlaybook);

        return redirect()->route('guide-playbooks.index')->with('success', 'Web Playbook berhasil ditambahkan.');
    }

    public function edit(GuidePlaybook $guidePlaybook): View
    {
        Gate::authorize('update', $guidePlaybook);

        return $this->form($guidePlaybook);
    }

    public function update(GuidePlaybookRequest $request, GuidePlaybook $guidePlaybook): RedirectResponse
    {
        $this->save($request, $guidePlaybook);

        return redirect()->route('guide-playbooks.index')->with('success', 'Web Playbook berhasil diperbarui.');
    }

    public function destroy(GuidePlaybook $guidePlaybook): RedirectResponse
    {
        Gate::authorize('delete', $guidePlaybook);

        $files = [$guidePlaybook->cover_image, $guidePlaybook->file_pdf];
        $guidePlaybook->delete();
        Storage::disk(GuidePlaybook::COVER_DISK)->delete($files[0]);
        Storage::disk(GuidePlaybook::PDF_DISK)->delete($files[1]);

        return redirect()->route('guide-playbooks.index')->with('success', 'Web Playbook berhasil dihapus.');
    }

    /** Pratinjau PDF untuk admin (PDF ada di disk private, tidak punya URL publik). */
    public function pdf(GuidePlaybook $guidePlaybook): StreamedResponse
    {
        Gate::authorize('viewAny', GuidePlaybook::class);

        return Storage::disk(GuidePlaybook::PDF_DISK)->response(
            $guidePlaybook->file_pdf,
            $guidePlaybook->downloadName(),
            ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    private function save(GuidePlaybookRequest $request, GuidePlaybook $playbook): void
    {
        $directory = GuidePlaybook::DIRECTORY.'/'.now()->format('Y/m');
        $old = ['cover_image' => $playbook->cover_image, 'file_pdf' => $playbook->file_pdf];
        $new = [
            'cover_image' => $request->hasFile('cover_image')
                ? $request->file('cover_image')->store($directory, GuidePlaybook::COVER_DISK)
                : null,
            'file_pdf' => $request->hasFile('file_pdf')
                ? $request->file('file_pdf')->store($directory, GuidePlaybook::PDF_DISK)
                : null,
        ];

        try {
            DB::transaction(function () use ($request, $playbook, $new) {
                $playbook->fill($request->playbookData());

                foreach (array_filter($new) as $column => $path) {
                    $playbook->{$column} = $path;
                }

                $playbook->save();
            });
        } catch (Throwable $e) {
            $this->deleteFiles($new);

            throw $e;
        }

        // File lama baru dihapus setelah data baru tersimpan
        $this->deleteFiles(array_filter($old, fn ($path, $column) => $path && $new[$column], ARRAY_FILTER_USE_BOTH));
    }

    /**
     * @param  array<string, string|null>  $paths  kolom => path
     */
    private function deleteFiles(array $paths): void
    {
        if (filled($paths['cover_image'] ?? null)) {
            Storage::disk(GuidePlaybook::COVER_DISK)->delete($paths['cover_image']);
        }
        if (filled($paths['file_pdf'] ?? null)) {
            Storage::disk(GuidePlaybook::PDF_DISK)->delete($paths['file_pdf']);
        }
    }

    private function form(GuidePlaybook $playbook): View
    {
        return view('pages.cms.guide-playbooks.form', [
            'title' => $playbook->exists ? 'Edit Web Playbook' : 'Tambah Web Playbook',
            'playbook' => $playbook->loadMissing(['creator:id,name', 'updater:id,name']),
            'statuses' => ContentStatus::cases(),
        ]);
    }
}
