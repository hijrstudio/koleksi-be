<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\GuidePlaybookIndexRequest;
use App\Http\Resources\GuidePlaybookCardResource;
use App\Models\GuidePlaybook;
use App\Support\JsonSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Web Playbook untuk website. Hanya playbook published.
 */
class GuidePlaybookController extends Controller
{
    /**
     * List playbook terbaru dengan pencarian judul (ID/EN). Paginasi bernomor: query data + COUNT = 2 query.
     */
    public function index(GuidePlaybookIndexRequest $request): JsonResponse
    {
        $search = trim((string) $request->validated('search'));

        $playbooks = GuidePlaybook::query()
            ->select(['id', 'title', 'cover_image', 'created_at', 'updated_at'])
            ->published()
            ->when($search !== '', fn (Builder $q) => $q->where(
                fn (Builder $q) => JsonSearch::orWhereLike($q, ['title->id', 'title->en'], $search)
            ))
            ->latest('created_at')
            ->latest('id')
            ->paginate($request->perPage());

        return response()->json([
            'data' => GuidePlaybookCardResource::collection($playbooks->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $playbooks->currentPage(),
                'last_page' => $playbooks->lastPage(),
                'per_page' => $playbooks->perPage(),
                'total' => $playbooks->total(),
            ],
        ]);
    }

    /**
     * Isi PDF untuk dibaca di popup website (siapa saja, termasuk tamu). Dikirim `inline`: FE merender
     * per halaman ke canvas tanpa tombol unduh; unduhan resmi hanya lewat Api\Member\PlaybookController.
     */
    public function file(int $playbook): StreamedResponse
    {
        return self::pdfResponse(GuidePlaybook::query()->published()->findOrFail($playbook), 'inline');
    }

    public static function pdfResponse(GuidePlaybook $playbook, string $disposition): StreamedResponse
    {
        abort_unless(Storage::disk(GuidePlaybook::PDF_DISK)->exists($playbook->file_pdf), 404);

        return Storage::disk(GuidePlaybook::PDF_DISK)->response(
            $playbook->file_pdf,
            $playbook->downloadName(),
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=300',
            ],
            $disposition,
        );
    }
}
