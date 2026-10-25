<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Api\GuidePlaybookController;
use App\Http\Controllers\Controller;
use App\Models\GuidePlaybook;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduh Web Playbook — hanya member yang login (token member, route grup `member`).
 * Tamu hanya bisa membaca lewat Api\GuidePlaybookController::file.
 */
class PlaybookController extends Controller
{
    public function download(int $playbook): StreamedResponse
    {
        return GuidePlaybookController::pdfResponse(
            GuidePlaybook::query()->published()->findOrFail($playbook),
            'attachment',
        );
    }
}
