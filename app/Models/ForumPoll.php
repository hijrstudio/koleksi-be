<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Polling / voting forum. Dibuat admin CMS; member memilih satu opsi (lihat ForumPollService).
 *
 *   voting  : hasil disembunyikan sampai selesai, suara tidak bisa diubah, wajib ada ends_at.
 *   polling : hasil terlihat langsung, pilihan boleh diganti selama masih berjalan.
 *
 * Fase dihitung dari waktu, tidak disimpan: scheduled (belum mulai) → open → ended (lewat ends_at / ditutup admin).
 */
class ForumPoll extends Model
{
    use SoftDeletes;

    public const VOTING = 'voting';

    public const POLLING = 'polling';

    public const TYPES = [self::VOTING, self::POLLING];

    public const PUBLISHED = 'published';

    public const HIDDEN = 'hidden';

    public const STATUSES = [self::PUBLISHED, self::HIDDEN];

    public const PHASE_SCHEDULED = 'scheduled';

    public const PHASE_OPEN = 'open';

    public const PHASE_ENDED = 'ended';

    public const TITLE_MAX = 190;

    public const OPTION_MAX = 120;

    public const MIN_OPTIONS = 2;

    public const MAX_OPTIONS = 10;

    protected $fillable = ['type', 'title', 'status', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'closed_at' => 'datetime',
            'votes_count' => 'integer',
        ];
    }

    public function options(): HasMany
    {
        return $this->hasMany(ForumPollOption::class, 'poll_id')->orderBy('position')->orderBy('id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ForumPollVote::class, 'poll_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Terbit & sudah mulai (yang dijadwalkan belum tampil di website). */
    public function scopeVisible(Builder $q): void
    {
        $q->where('status', self::PUBLISHED)->where('starts_at', '<=', now());
    }

    /** Masih menerima suara. */
    public function scopeOpen(Builder $q): void
    {
        $q->whereNull('closed_at')->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /** Sudah selesai (lewat batas waktu atau ditutup admin). */
    public function scopeEnded(Builder $q): void
    {
        $q->where(fn ($w) => $w->whereNotNull('closed_at')->orWhere('ends_at', '<=', now()));
    }

    public function isVoting(): bool
    {
        return $this->type === self::VOTING;
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    /** Waktu selesai sebenarnya: ditutup admin lebih dulu, atau batas waktu. */
    public function endedAt(): ?Carbon
    {
        return $this->closed_at ?? ($this->ends_at?->isPast() ? $this->ends_at : null);
    }

    public function phase(): string
    {
        if ($this->endedAt() !== null) {
            return self::PHASE_ENDED;
        }

        return $this->starts_at->isFuture() ? self::PHASE_SCHEDULED : self::PHASE_OPEN;
    }

    public function isOpen(): bool
    {
        return $this->isPublished() && $this->phase() === self::PHASE_OPEN;
    }

    /** Voting: hasil baru dibuka setelah selesai. Polling: selalu terlihat. */
    public function resultsVisible(): bool
    {
        return ! $this->isVoting() || $this->phase() === self::PHASE_ENDED;
    }

    /** Polling yang masih berjalan boleh ganti pilihan; voting tidak pernah. */
    public function allowsChange(): bool
    {
        return ! $this->isVoting();
    }

    /** Opsi dikunci begitu ada suara supaya suara yang masuk tidak berubah makna. */
    public function optionsLocked(): bool
    {
        return $this->votes_count > 0;
    }
}
