<?php

namespace App\Http\Controllers\Cms;

use App\Enums\ContentStatus;
use App\Enums\EventPhase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\EventRequest;
use App\Models\Event;
use App\Models\EventCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class EventController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Event::class);

        $status = ContentStatus::tryFrom((string) $request->query('status'));
        $phase = EventPhase::tryFrom((string) $request->query('phase'));
        $categoryId = $request->integer('category') ?: null;

        // Filter selain tab, dipakai juga untuk menghitung jumlah per tab
        $filtered = fn () => Event::query()
            ->search($request->string('search')->trim()->toString())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($categoryId, fn ($q) => $q->where('event_category_id', $categoryId));

        $events = $filtered()
            ->with(['category:id,name,status', 'updater:id,name'])
            ->when($phase, fn ($q) => $q->phase($phase), fn ($q) => $q->orderByDesc('start_date')->orderByDesc('id'))
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $phaseCounts = collect(EventPhase::cases())
            ->mapWithKeys(fn (EventPhase $p) => [$p->value => $filtered()->phase($p)->reorder()->count()]);

        return view('pages.cms.events.index', [
            'title' => 'Event',
            'events' => $events,
            'statuses' => ContentStatus::cases(),
            'phases' => EventPhase::cases(),
            'currentPhase' => $phase,
            'phaseCounts' => $phaseCounts,
            'totalCount' => $phaseCounts->sum(),
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Event::class);

        return $this->form(new Event(['status' => ContentStatus::Draft]));
    }

    public function store(EventRequest $request): RedirectResponse
    {
        $this->save($request, new Event);

        return redirect()->route('events.index')->with('success', 'Event berhasil ditambahkan.');
    }

    public function edit(Event $event): View
    {
        Gate::authorize('update', $event);

        return $this->form($event);
    }

    public function update(EventRequest $request, Event $event): RedirectResponse
    {
        $this->save($request, $event);

        return redirect()->route('events.index')->with('success', 'Event berhasil diperbarui.');
    }

    public function destroy(Event $event): RedirectResponse
    {
        Gate::authorize('delete', $event);

        $image = $event->image;
        $event->delete();

        if ($image) {
            Storage::disk(Event::DISK)->delete($image);
        }

        return redirect()->route('events.index')->with('success', 'Event berhasil dihapus.');
    }

    private function save(EventRequest $request, Event $event): void
    {
        $disk = Storage::disk(Event::DISK);
        $oldImage = $event->image;
        $newImage = $request->hasFile('image')
            ? $request->file('image')->store(Event::DIRECTORY.'/'.now()->format('Y/m'), Event::DISK)
            : null;
        $removeImage = ! $newImage && $request->boolean('remove_image');

        try {
            DB::transaction(function () use ($request, $event, $newImage, $removeImage) {
                $event->fill($request->eventData());

                // Slug kosong: model membuatnya dari judul (create) / slug lama dipertahankan (edit)
                if ($slug = $request->validated('slug')) {
                    $event->slug = $slug;
                }

                if ($newImage) {
                    $event->image = $newImage;
                } elseif ($removeImage) {
                    $event->image = null;
                }

                $event->save();
            });
        } catch (Throwable $e) {
            if ($newImage) {
                $disk->delete($newImage);
            }

            throw $e;
        }

        // Gambar lama baru dihapus setelah data baru tersimpan
        if ($oldImage && ($newImage || $removeImage)) {
            $disk->delete($oldImage);
        }
    }

    private function form(Event $event): View
    {
        return view('pages.cms.events.form', [
            'title' => $event->exists ? 'Edit Event' : 'Tambah Event',
            'event' => $event->loadMissing(['creator:id,name', 'updater:id,name']),
            'statuses' => ContentStatus::cases(),
            'categories' => $this->categoryOptions(),
        ]);
    }

    /**
     * @return Collection<int, EventCategory>
     */
    private function categoryOptions(): Collection
    {
        return EventCategory::query()->latest('id')->get(['id', 'name', 'status']);
    }
}
