<?php

namespace App\Services;

use App\Models\ForumPoll;
use App\Models\ForumPollOption;
use App\Models\ForumPollVote;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Polling & voting forum: simpan dari CMS dan suara member.
 *
 * Suara: satu baris per (poll, member) dijamin unique index. Counter poll & opsi diubah dengan UPDATE atomik
 * (votes_count = votes_count ± 1) di transaksi yang sama, jadi tetap akurat walau banyak member memilih bersamaan
 * dan baris poll tidak perlu dikunci (tidak jadi antrean saat ramai).
 */
class ForumPollService
{
    /**
     * @param  array{type: string, title: string, status: string, starts_at: Carbon, ends_at: ?Carbon, options?: list<string>}  $data
     */
    public function save(ForumPoll $poll, array $data, User $admin): ForumPoll
    {
        return DB::transaction(function () use ($poll, $data, $admin) {
            $locked = $poll->exists && ForumPoll::query()->whereKey($poll->id)->lockForUpdate()->value('votes_count') > 0;

            $poll->fill([
                // Jenis & opsi tidak boleh berubah setelah ada suara (aturan hasil/ganti pilihan ikut berubah)
                'type' => $locked ? $poll->type : $data['type'],
                'title' => ForumService::cleanLine($data['title']),
                'status' => $data['status'],
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
            ]);
            $poll->user_id ??= $admin->id;
            $poll->save();

            if (! $locked && isset($data['options'])) {
                $poll->options()->delete();
                $poll->options()->createMany(collect(self::cleanOptions($data['options']))
                    ->map(fn (string $label, int $i) => ['label' => $label, 'position' => $i])->all());
            }

            return $poll;
        });
    }

    /** Label opsi bersih, tanpa yang kosong / dobel (tidak peka huruf besar-kecil). @return list<string> */
    public static function cleanOptions(array $labels): array
    {
        return collect($labels)
            ->map(fn ($label) => ForumService::cleanLine((string) $label))
            ->filter(fn (string $label) => $label !== '')
            ->unique(fn (string $label) => mb_strtolower($label))
            ->values()->all();
    }

    /** Tutup sekarang (sebelum batas waktu) / buka lagi bila batas waktunya belum lewat. */
    public function setClosed(ForumPoll $poll, bool $closed): void
    {
        if (! $closed && $poll->ends_at?->isPast()) {
            throw ValidationException::withMessages(['ends_at' => 'Batas waktunya sudah lewat. Ubah tanggal selesai dulu untuk membuka lagi.']);
        }

        $poll->forceFill(['closed_at' => $closed ? ($poll->closed_at ?? now()) : null])->save();
    }

    /**
     * Member memilih satu opsi. Voting: hanya sekali. Polling: boleh ganti pilihan selama masih berjalan.
     *
     * @throws ValidationException polling tidak berjalan, opsi bukan milik polling ini, atau sudah memilih (voting)
     */
    public function vote(ForumPoll $poll, Member $member, int $optionId): ForumPollVote
    {
        if (! $poll->isOpen()) {
            throw ValidationException::withMessages(['option_id' => $poll->phase() === ForumPoll::PHASE_ENDED
                ? 'Polling ini sudah selesai.' : 'Polling ini belum dimulai.']);
        }

        $option = $poll->options()->whereKey($optionId)->first()
            ?? throw ValidationException::withMessages(['option_id' => 'Pilihan tidak valid.']);

        try {
            // Suara pertama: insert biasa; unique index menolak klik ganda / dua tab bersamaan
            $vote = DB::transaction(function () use ($poll, $member, $option) {
                $vote = ForumPollVote::query()->create(['poll_id' => $poll->id, 'option_id' => $option->id, 'member_id' => $member->id]);
                ForumPollOption::query()->whereKey($option->id)->increment('votes_count');
                ForumPoll::query()->whereKey($poll->id)->increment('votes_count');

                return $vote;
            });
            // Ikut polling / voting: sekali per polling (ganti pilihan tidak menambah poin)
            app(PointService::class)->award($member, 'poll', "poll:{$poll->id}");

            return $vote;
        } catch (UniqueConstraintViolationException) {
            // Sudah pernah memilih
        }

        if (! $poll->allowsChange()) {
            throw ValidationException::withMessages(['option_id' => 'Anda sudah memberikan suara di voting ini.']);
        }

        return DB::transaction(function () use ($poll, $member, $option) {
            $vote = ForumPollVote::query()->where('poll_id', $poll->id)->where('member_id', $member->id)
                ->lockForUpdate()->firstOrFail();

            if ($vote->option_id !== $option->id) {
                ForumPollOption::query()->whereKey($vote->option_id)->where('votes_count', '>', 0)->decrement('votes_count');
                ForumPollOption::query()->whereKey($option->id)->increment('votes_count');
                $vote->update(['option_id' => $option->id]);
            }

            return $vote;
        });
    }
}
