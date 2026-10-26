<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tagihan iuran 1 member untuk 1 periode keanggotaannya
 * (periode dihitung dari tanggal bergabung, lihat App\Support\MembershipTerm).
 */
class MemberDue extends Model
{
    public const UNPAID = 'unpaid';

    public const PAID = 'paid';

    public const WAIVED = 'waived';

    /** Status yang dianggap "lunas" untuk perhitungan keaktifan. */
    public const SETTLED = [self::PAID, self::WAIVED];

    /** payment_method tagihan yang dibebaskan karena pemutihan tunggakan (lihat config membership.arrears_cutoff_year). */
    public const METHOD_AMNESTY = 'amnesty';

    protected $fillable = [
        'invoice_number', 'member_id', 'period_label', 'period_start', 'period_end', 'amount', 'status',
        'payment_method', 'paid_at', 'recorded_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeSettled(Builder $q): void
    {
        $q->whereIn('status', self::SETTLED);
    }

    public function isSettled(): bool
    {
        return in_array($this->status, self::SETTLED, true);
    }

    public function isAmnesty(): bool
    {
        return $this->status === self::WAIVED && $this->payment_method === self::METHOD_AMNESTY;
    }

    // Fase 2: relasi payments() & openPayment() ditambahkan bersama payment gateway.
}
