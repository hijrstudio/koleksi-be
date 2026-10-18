<?php

namespace App\Http\Requests\Api;

/**
 * Query list infografis website: ?search=&category=&page=&per_page= (aturan sama dengan video).
 */
class GuideInfographicIndexRequest extends GuideVideoIndexRequest
{
    public const DEFAULT_PER_PAGE = 6;
}
