<?php

namespace App\Http\Controllers\Cms;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\EventVideoRequest;
use App\Models\EventVideo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class EventVideoController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', EventVideo::class);

        $status = ContentStatus::tryFrom((string) $request->query('status'));

        $videos = EventVideo::query()
            ->with('updater:id,name')
            ->search($request->string('search')->trim()->toString())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.cms.event-videos.index', [
            'title' => 'Video Kegiatan',
            'videos' => $videos,
            'statuses' => ContentStatus::cases(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', EventVideo::class);

        return $this->form(new EventVideo(['status' => ContentStatus::Draft]));
    }

    public function store(EventVideoRequest $request): RedirectResponse
    {
        $this->save($request, new EventVideo);

        return redirect()->route('event-videos.index')->with('success', 'Video berhasil ditambahkan.');
    }

    public function edit(EventVideo $eventVideo): View
    {
        Gate::authorize('update', $eventVideo);

        return $this->form($eventVideo);
    }

    public function update(EventVideoRequest $request, EventVideo $eventVideo): RedirectResponse
    {
        $this->save($request, $eventVideo);

        return redirect()->route('event-videos.index')->with('success', 'Video berhasil diperbarui.');
    }

    public function destroy(EventVideo $eventVideo): RedirectResponse
    {
        Gate::authorize('delete', $eventVideo);

        $thumbnail = $eventVideo->thumbnail;
        $eventVideo->delete();

        if ($thumbnail) {
            Storage::disk(EventVideo::DISK)->delete($thumbnail);
        }

        return redirect()->route('event-videos.index')->with('success', 'Video berhasil dihapus.');
    }

    private function save(EventVideoRequest $request, EventVideo $video): void
    {
        $oldThumbnail = $video->thumbnail;
        $newThumbnail = $request->hasFile('thumbnail')
            ? $request->file('thumbnail')->store(EventVideo::DIRECTORY.'/'.now()->format('Y/m'), EventVideo::DISK)
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
                Storage::disk(EventVideo::DISK)->delete($newThumbnail);
            }

            throw $e;
        }

        // Thumbnail lama baru dihapus setelah data baru tersimpan
        if ($oldThumbnail && ($newThumbnail || $removeThumbnail)) {
            Storage::disk(EventVideo::DISK)->delete($oldThumbnail);
        }
    }

    private function form(EventVideo $video): View
    {
        return view('pages.cms.event-videos.form', [
            'title' => $video->exists ? 'Edit Video Kegiatan' : 'Tambah Video Kegiatan',
            'video' => $video->loadMissing(['creator:id,name', 'updater:id,name']),
            'statuses' => ContentStatus::cases(),
        ]);
    }
}
