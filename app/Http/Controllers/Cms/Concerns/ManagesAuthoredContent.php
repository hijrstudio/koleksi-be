<?php

namespace App\Http\Controllers\Cms\Concerns;

use App\Http\Requests\Cms\AuthoredContentRequest;
use App\Models\Member;
use App\Support\ContentImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Logika bersama controller konten bertulis (artikel panduan, berita, ...):
 * simpan (gambar utama + slug + penulis), pencarian member penulis, dan upload gambar konten.
 * Model wajib memakai HasAuthor, HasUniqueSlug, HasRichContent dan punya konstanta DISK, DIRECTORY,
 * CONTENT_IMAGE_DIRECTORY.
 */
trait ManagesAuthoredContent
{
    private const MEMBER_OPTIONS_LIMIT = 20;

    protected function saveAuthoredContent(AuthoredContentRequest $request, Model $model): void
    {
        $disk = Storage::disk($model::DISK);
        $oldImage = $model->image;
        $newImage = $request->hasFile('image')
            ? $request->file('image')->store($model::DIRECTORY.'/'.now()->format('Y/m'), $model::DISK)
            : null;
        $removeImage = ! $newImage && $request->boolean('remove_image');

        try {
            DB::transaction(function () use ($request, $model, $newImage, $removeImage) {
                $model->fill($request->contentData());

                // Slug kosong: model membuatnya dari judul (create) / slug lama dipertahankan (edit)
                if ($slug = $request->validated('slug')) {
                    $model->slug = $slug;
                }

                if ($newImage) {
                    $model->image = $newImage;
                } elseif ($removeImage) {
                    $model->image = null;
                }

                if ($request->validated('author_type') === $model::AUTHOR_MEMBER) {
                    $model->setAuthorMember((int) $request->validated('author_member_id'));
                } elseif ($model->author_user_id === null) {
                    // Penulis admin = user yang login. Konten milik admin lain tidak diambil alih saat diedit.
                    $model->setAuthorUser($request->user()->id);
                }

                $model->save();
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

    protected function deleteAuthoredContent(Model $model): void
    {
        $image = $model->image;
        $model->delete();

        // Gambar di dalam konten dibersihkan oleh `content:prune-images` (bisa saja dipakai konten lain)
        if ($image) {
            Storage::disk($model::DISK)->delete($image);
        }
    }

    /**
     * Pencarian member untuk pilihan penulis (server-side, supaya tetap ringan walau member ribuan).
     * Otorisasi dilakukan oleh controller pemanggil.
     */
    protected function memberOptionsResponse(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $members = Member::query()
            ->registered()
            ->search($validated['q'] ?? null)
            ->orderBy('nama_lengkap')
            ->limit(self::MEMBER_OPTIONS_LIMIT)
            ->get(['id', 'nama_lengkap', 'no_lambung']);

        return response()->json([
            'results' => $members->map(fn (Member $member) => [
                'id' => $member->id,
                'label' => self::memberLabel($member),
            ]),
        ]);
    }

    /**
     * Upload gambar yang disisipkan di konten (rich editor). Mengembalikan URL publik untuk <img src>.
     * Otorisasi dilakukan oleh controller pemanggil. Gambar yang tidak dipakai dibersihkan `content:prune-images`.
     */
    protected function contentImageResponse(Request $request, string $directory): JsonResponse
    {
        $request->validate(['image' => ContentImages::uploadRules()]);

        return response()->json(ContentImages::store($request->file('image'), $directory), 201);
    }

    /**
     * Member terpilih untuk form (dari old input setelah gagal validasi, atau dari data tersimpan).
     *
     * @return array{id: int, label: string}|null
     */
    protected function selectedMember(Model $model): ?array
    {
        $oldMemberId = filter_var(old('author_member_id'), FILTER_VALIDATE_INT) ?: null;
        $member = $oldMemberId
            ? Member::query()->find($oldMemberId, ['id', 'nama_lengkap', 'no_lambung'])
            : $model->authorMember;

        return $member ? ['id' => $member->id, 'label' => self::memberLabel($member)] : null;
    }

    private static function memberLabel(Member $member): string
    {
        return $member->nama_lengkap.' ('.$member->noLambungLabel().')';
    }
}
