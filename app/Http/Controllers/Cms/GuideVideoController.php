<?php

namespace App\Http\Controllers\Cms;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\GuideVideoRequest;
use App\Models\GuideCategory;
use App\Models\GuideVideo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class GuideVideoController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', GuideVideo::class);

        $status = ContentStatus::tryFrom((string) $request->query('status'));
        $categoryId = $request->integer('category') ?: null;

        $videos = GuideVideo::query()
            ->with(['category:id,name', 'updater:id,name'])
            ->search($request->string('search')->trim()->toString())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($categoryId, fn ($q) => $q->where('guide_category_id', $categoryId))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.cms.guide-videos.index', [
            'title' => 'Video Edukasi',
            'videos' => $videos,
            'statuses' => ContentStatus::cases(),
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', GuideVideo::class);

        return $this->form(new GuideVideo(['status' => ContentStatus::Draft]));
    }

    public function store(GuideVideoRequest $request): RedirectResponse
    {
        $this->save($request, new GuideVideo);

        return redirect()->route('guide-videos.index')->with('success', 'Video berhasil ditambahkan.');
    }

    public function edit(GuideVideo $guideVideo): View
    {
        Gate::authorize('update', $guideVideo);

        return $this->form($guideVideo);
    }

    public function update(GuideVideoRequest $request, GuideVideo $guideVideo): RedirectResponse
    {
        $this->save($request, $guideVideo);

        return redirect()->route('guide-videos.index')->with('success', 'Video berhasil diperbarui.');
    }

    public function destroy(GuideVideo $guideVideo): RedirectResponse
    {
        Gate::authorize('delete', $guideVideo);

        $thumbnail = $guideVideo->thumbnail;
        $guideVideo->delete();

        if ($thumbnail) {
            Storage::disk(GuideVideo::DISK)->delete($thumbnail);
        }

        return redirect()->route('guide-videos.index')->with('success', 'Video berhasil dihapus.');
    }

    private function save(GuideVideoRequest $request, GuideVideo $video): void
    {
        $oldThumbnail = $video->thumbnail;
        $newThumbnail = $request->hasFile('thumbnail')
            ? $request->file('thumbnail')->store(GuideVideo::DIRECTORY.'/'.now()->format('Y/m'), GuideVideo::DISK)
            : null;
        $removeThumbnail = ! $newThumbnail && $request->boolean('remove_thumbnail');

        try {
            DB::transaction(function () use ($request, $video, $newThumbnail, $removeThumbnail) {
                $video->fill($request->videoData());

                if ($newThumbnail) {
                    $video->thumbnail = $newThumbnail;
                } elseif ($removeThumbnail) {
                    $video->thumbnail = null;
                }

                $video->save();
            });
        } catch (Throwable $e) {
            if ($newThumbnail) {
                Storage::disk(GuideVideo::DISK)->delete($newThumbnail);
            }

            throw $e;
        }

        // Thumbnail lama baru dihapus setelah data baru tersimpan
        if ($oldThumbnail && ($newThumbnail || $removeThumbnail)) {
            Storage::disk(GuideVideo::DISK)->delete($oldThumbnail);
        }
    }

    private function form(GuideVideo $video): View
    {
        return view('pages.cms.guide-videos.form', [
            'title' => $video->exists ? 'Edit Video' : 'Tambah Video',
            'video' => $video->loadMissing(['creator:id,name', 'updater:id,name']),
            'statuses' => ContentStatus::cases(),
            'categories' => $this->categoryOptions(),
        ]);
    }

    /**
     * @return Collection<int, GuideCategory>
     */
    private function categoryOptions()
    {
        return GuideCategory::query()->latest('id')->get(['id', 'name', 'status']);
    }
}
