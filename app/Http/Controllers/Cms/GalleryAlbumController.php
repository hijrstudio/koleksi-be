<?php

namespace App\Http\Controllers\Cms;

use App\Enums\ContentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\GalleryAlbumRequest;
use App\Models\GalleryAlbum;
use App\Models\GalleryPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class GalleryAlbumController extends Controller
{
    private const PER_PAGE = 12;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', GalleryAlbum::class);

        $status = ContentStatus::tryFrom((string) $request->query('status'));

        $albums = GalleryAlbum::query()
            ->with('updater:id,name')
            ->withCount('photos')
            ->search($request->string('search')->trim()->toString())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('gallery_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('pages.cms.gallery-albums.index', [
            'title' => 'Galeri Foto',
            'albums' => $albums,
            'statuses' => ContentStatus::cases(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', GalleryAlbum::class);

        return $this->form(new GalleryAlbum(['status' => ContentStatus::Draft, 'gallery_date' => now()]));
    }

    public function store(GalleryAlbumRequest $request): RedirectResponse
    {
        $this->save($request, new GalleryAlbum);

        return redirect()->route('gallery-albums.index')->with('success', 'Album berhasil ditambahkan.');
    }

    public function edit(GalleryAlbum $galleryAlbum): View
    {
        Gate::authorize('update', $galleryAlbum);

        return $this->form($galleryAlbum);
    }

    public function update(GalleryAlbumRequest $request, GalleryAlbum $galleryAlbum): RedirectResponse
    {
        $this->save($request, $galleryAlbum);

        return redirect()->route('gallery-albums.index')->with('success', 'Album berhasil diperbarui.');
    }

    public function destroy(GalleryAlbum $galleryAlbum): RedirectResponse
    {
        Gate::authorize('delete', $galleryAlbum);

        $files = DB::transaction(function () use ($galleryAlbum) {
            $files = [$galleryAlbum->image, ...$galleryAlbum->photos()->pluck('image')->all()];
            $galleryAlbum->delete(); // foto ikut terhapus (cascade)

            return $files;
        });

        Storage::disk(GalleryAlbum::DISK)->delete(array_filter($files));

        return redirect()->route('gallery-albums.index')->with('success', 'Album berhasil dihapus.');
    }

    /**
     * Upload satu foto album (dipanggil per file dari form, sebelum album disimpan).
     * Upload per file menghindari batas PHP max_file_uploads & post_max_size untuk album besar.
     * Foto yang tidak jadi dipakai dibersihkan oleh `gallery:prune-uploads`.
     *
     * POST /gallery-albums/photo-uploads  (multipart: image)
     */
    public function uploadPhoto(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('create', GalleryAlbum::class) || $user->can('gallery-albums.update'), 403);

        $request->validate([
            'image' => [
                'required', 'file',
                'mimetypes:'.implode(',', GalleryAlbumRequest::IMAGE_MIMES),
                'extensions:'.implode(',', GalleryAlbumRequest::IMAGE_EXTENSIONS),
                'max:'.GalleryAlbumRequest::MAX_PHOTO_KB,
                'dimensions:max_width='.GalleryAlbumRequest::MAX_PHOTO_DIMENSION.',max_height='.GalleryAlbumRequest::MAX_PHOTO_DIMENSION,
            ],
        ]);

        $path = $request->file('image')->store(GalleryPhoto::DIRECTORY.'/'.now()->format('Y/m'), GalleryPhoto::DISK);

        return response()->json([
            'path' => $path,
            'url' => Storage::disk(GalleryPhoto::DISK)->url($path),
        ], 201);
    }

    /**
     * Simpan album + sinkronisasi foto dalam satu transaksi:
     * foto dengan id di-update urutannya, path baru dibuat, yang tidak dikirim lagi dihapus.
     */
    private function save(GalleryAlbumRequest $request, GalleryAlbum $album): void
    {
        $rows = $request->photoRows();
        $oldCover = $album->image;
        $newCover = $request->hasFile('image')
            ? $request->file('image')->store(GalleryAlbum::DIRECTORY.'/'.now()->format('Y/m'), GalleryAlbum::DISK)
            : null;

        try {
            $removedFiles = DB::transaction(function () use ($request, $album, $rows, $newCover) {
                $album->fill($request->albumData());

                if ($newCover) {
                    $album->image = $newCover;
                }

                $album->save();

                $existing = $album->photos()->getQuery()->lockForUpdate()->get()->keyBy('id');
                $keptIds = [];

                foreach ($rows as $row) {
                    if ($row['id']) {
                        $photo = $existing->get($row['id']);
                        abort_if($photo === null, 409, 'Data foto sudah berubah, muat ulang halaman.');
                        $photo->update(['sort_order' => $row['sort_order']]);
                    } else {
                        $photo = $album->photos()->create(['image' => $row['path'], 'sort_order' => $row['sort_order']]);
                    }

                    $keptIds[] = $photo->id;
                }

                // Eloquent Collection::except() memfilter by primary key & me-reindex, jadi pakai modelKeys()
                $removed = $existing->except($keptIds);

                if ($removed->isNotEmpty()) {
                    GalleryPhoto::query()->whereKey($removed->modelKeys())->delete();
                }

                // Jika yang berubah hanya foto, tetap catat di updated_at / updated_by album
                if (! $album->wasRecentlyCreated && ! $album->wasChanged()) {
                    $album->touch();
                }

                return $removed->pluck('image')->all();
            });
        } catch (Throwable $e) {
            if ($newCover) {
                Storage::disk(GalleryAlbum::DISK)->delete($newCover);
            }

            throw $e;
        }

        // File lama baru dihapus setelah data baru tersimpan
        if ($newCover && $oldCover) {
            $removedFiles[] = $oldCover;
        }

        Storage::disk(GalleryAlbum::DISK)->delete($removedFiles);
    }

    private function form(GalleryAlbum $album): View
    {
        return view('pages.cms.gallery-albums.form', [
            'title' => $album->exists ? 'Edit Album' : 'Tambah Album',
            'album' => $album->loadMissing(['creator:id,name', 'updater:id,name']),
            'statuses' => ContentStatus::cases(),
            'photos' => $this->formPhotos($album),
        ]);
    }

    /**
     * Data awal repeater foto: dari old input (setelah gagal validasi) atau dari data tersimpan.
     *
     * @return list<array{id: ?int, path: ?string, url: string}>
     */
    private function formPhotos(GalleryAlbum $album): array
    {
        $saved = $album->exists ? $album->photos()->get()->keyBy('id') : collect();
        $old = old('photos');

        if (! is_array($old)) {
            return $saved->map(fn (GalleryPhoto $photo) => [
                'id' => $photo->id,
                'path' => null,
                'url' => $photo->image_url,
            ])->values()->all();
        }

        $disk = Storage::disk(GalleryPhoto::DISK);
        $photos = [];

        foreach ($old as $input) {
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $path = $input['path'] ?? null;

            if ($id && ($photo = $saved->get($id))) {
                $photos[] = ['id' => $id, 'path' => null, 'url' => $photo->image_url];
            } elseif (! $id && GalleryPhoto::isUploadPath($path) && $disk->exists($path)) {
                $photos[] = ['id' => null, 'path' => $path, 'url' => $disk->url($path)];
            }
        }

        return $photos;
    }
}
