<?php

namespace App\Http\Controllers\Cms;

use App\Enums\ContentStatus;
use App\Enums\MediaType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\HeroBannerRequest;
use App\Models\HeroBanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class HeroBannerController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', HeroBanner::class);

        $status = ContentStatus::tryFrom((string) $request->query('status'));

        $banners = HeroBanner::query()
            ->with('updater:id,name')
            ->search($request->string('search')->trim()->toString())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.cms.hero-banners.index', [
            'title' => 'Hero Banner',
            'banners' => $banners,
            'statuses' => ContentStatus::cases(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', HeroBanner::class);

        return $this->form(new HeroBanner(['status' => ContentStatus::Draft]));
    }

    public function store(HeroBannerRequest $request): RedirectResponse
    {
        $media = $this->storeFile($request->file('file'));

        try {
            DB::transaction(fn () => HeroBanner::create([
                ...$media,
                'title' => $request->validated('title'),
                'status' => $request->validated('status'),
            ]));
        } catch (Throwable $e) {
            Storage::disk(HeroBanner::DISK)->delete($media['file_path']);

            throw $e;
        }

        return redirect()->route('hero-banners.index')->with('success', 'Hero banner berhasil ditambahkan.');
    }

    public function edit(HeroBanner $heroBanner): View
    {
        Gate::authorize('update', $heroBanner);

        return $this->form($heroBanner);
    }

    public function update(HeroBannerRequest $request, HeroBanner $heroBanner): RedirectResponse
    {
        $oldPath = $heroBanner->file_path;
        $media = $request->hasFile('file') ? $this->storeFile($request->file('file')) : [];

        try {
            DB::transaction(fn () => $heroBanner->update([
                ...$media,
                'title' => $request->validated('title'),
                'status' => $request->validated('status'),
            ]));
        } catch (Throwable $e) {
            if ($media) {
                Storage::disk(HeroBanner::DISK)->delete($media['file_path']);
            }

            throw $e;
        }

        // File lama baru dihapus setelah data baru tersimpan
        if ($media && $oldPath) {
            Storage::disk(HeroBanner::DISK)->delete($oldPath);
        }

        return redirect()->route('hero-banners.index')->with('success', 'Hero banner berhasil diperbarui.');
    }

    public function destroy(HeroBanner $heroBanner): RedirectResponse
    {
        Gate::authorize('delete', $heroBanner);

        $path = $heroBanner->file_path;
        $heroBanner->delete();
        Storage::disk(HeroBanner::DISK)->delete($path);

        return redirect()->route('hero-banners.index')->with('success', 'Hero banner berhasil dihapus.');
    }

    private function form(HeroBanner $banner): View
    {
        return view('pages.cms.hero-banners.form', [
            'title' => $banner->exists ? 'Edit Hero Banner' : 'Tambah Hero Banner',
            'banner' => $banner,
            'statuses' => ContentStatus::cases(),
        ]);
    }

    /**
     * Simpan file dengan nama acak (bukan nama asli dari user).
     *
     * @return array{file_path: string, file_type: MediaType, mime_type: string}
     */
    private function storeFile(UploadedFile $file): array
    {
        $mime = $file->getMimeType();

        return [
            'file_path' => $file->store(HeroBanner::DIRECTORY.'/'.now()->format('Y/m'), HeroBanner::DISK),
            'file_type' => MediaType::fromMime($mime),
            'mime_type' => $mime,
        ];
    }
}
