<?php

namespace App\Http\Resources;

use App\Models\ForumDiscussion;
use App\Models\ForumPoll;
use App\Models\ForumPollOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Polling / voting untuk website. Jumlah & persen per opsi HANYA dikirim bila hasilnya boleh dilihat
 * (polling: selalu; voting: setelah selesai), jadi hasil voting yang berjalan tidak bisa diintip dari respons API.
 *
 * my_option_id diisi controller (pilihan member yang sedang login, null = belum memilih).
 *
 * @mixin ForumPoll
 */
class ForumPollResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $visible = $this->resultsVisible();
        $options = $this->options;
        $percents = $visible ? self::percents($options) : [];

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'author' => [
                'name' => $this->user?->name ?? 'Admin KOLEKSI',
                'role' => ForumDiscussion::ROLE_MODERATOR,
            ],
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'ended_at' => $this->endedAt()?->toIso8601String(),
            'phase' => $this->phase(),
            'votes_count' => $this->votes_count,
            'results_visible' => $visible,
            'allows_change' => $this->allowsChange(),
            'options' => $options->map(fn (ForumPollOption $o) => [
                'id' => $o->id,
                'label' => $o->label,
                ...($visible ? ['votes' => $o->votes_count, 'percent' => $percents[$o->id]] : []),
            ])->values(),
            'my_option_id' => $this->getAttribute('my_option_id'),
        ];
    }

    /**
     * Persen bulat per opsi yang jumlahnya tepat 100 (metode sisa terbesar). Total 0 → semua 0.
     *
     * @param  Collection<int, ForumPollOption>  $options
     * @return array<int, int> option id → persen
     */
    public static function percents(Collection $options): array
    {
        $sum = $options->sum('votes_count');
        if ($sum === 0) {
            return $options->mapWithKeys(fn (ForumPollOption $o) => [$o->id => 0])->all();
        }

        $exact = $options->mapWithKeys(fn (ForumPollOption $o) => [$o->id => $o->votes_count * 100 / $sum]);
        $result = $exact->map(fn (float|int $p) => (int) floor($p))->all();
        $left = 100 - array_sum($result);

        $exact->sortByDesc(fn ($p, $id) => [$p - floor($p), -$id])->keys()->take($left)
            ->each(function (int $id) use (&$result) {
                $result[$id]++;
            });

        return $result;
    }
}
