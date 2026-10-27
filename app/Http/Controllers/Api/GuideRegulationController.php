<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\GuideRegulationIndexRequest;
use App\Models\GuideRegulation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dokumen regulasi untuk website. Hanya dokumen published.
 */
class GuideRegulationController extends Controller
{
    /**
     * List dokumen terbaru dengan pencarian judul. Paginasi bernomor: query data + COUNT = 2 query.
     * Item: id, title, published_at. PDF dibuka lewat endpoint file (FE: /api/regulations/{id}/file).
     */
    public function index(GuideRegulationIndexRequest $request): JsonResponse
    {
        $search = trim((string) $request->validated('search'));

        $regulations = GuideRegulation::query()
            ->select(['id', 'title', 'created_at'])
            ->published()
            ->when($search !== '', fn (Builder $q) => $q->search($search))
            ->latest('created_at')
            ->latest('id')
            ->paginate($request->perPage());

        return response()->json([
            'data' => $regulations->getCollection()->map(fn (GuideRegulation $regulation) => [
                'id' => $regulation->id,
                'title' => $regulation->title,
                'published_at' => $regulation->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $regulations->currentPage(),
                'last_page' => $regulations->lastPage(),
                'per_page' => $regulations->perPage(),
                'total' => $regulations->total(),
            ],
        ]);
    }

    /** Isi PDF (inline, dibuka di tab baru browser). Dokumen draft / file hilang = 404. */
    public function file(int $regulation): StreamedResponse
    {
        $regulation = GuideRegulation::query()->published()->findOrFail($regulation);

        abort_unless(Storage::disk(GuideRegulation::PDF_DISK)->exists($regulation->file_pdf), 404);

        return Storage::disk(GuideRegulation::PDF_DISK)->response(
            $regulation->file_pdf,
            $regulation->downloadName(),
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=300',
            ],
            'inline',
        );
    }
}
