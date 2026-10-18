<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu QRIS Dynamic untuk melunasi 1..n tagihan iuran (payment_items).
 * Status hanya diubah lewat App\Payments\PaymentService.
 */
class Payment extends Model
{
    public const PENDING = 'pending';

    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    public const REFUNDED = 'refunded';

    protected $fillable = [
        'member_id', 'gateway', 'merchant_ref', 'gateway_ref', 'gateway_qr_id',
        'amount', 'net_amount', 'mdr_amount', 'handling_fee', 'status',
        'qr_string', 'payment_channel', 'expired_at', 'paid_at', 'last_checked_at',
        'request_payload', 'response_payload', 'last_callback', 'failure_reason',
    ];

    protected $hidden = ['request_payload', 'response_payload', 'last_callback'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'net_amount' => 'integer',
            'mdr_amount' => 'integer',
            'handling_fee' => 'integer',
            'expired_at' => 'datetime',
            'paid_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'last_callback' => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PaymentItem::class);
    }

    public function dues(): BelongsToMany
    {
        return $this->belongsToMany(MemberDue::class, 'payment_items')->withPivot('amount')->withTimestamps();
    }

    public function scopePending(Builder $q): void
    {
        $q->where('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** Data untuk FE (tanpa payload mentah & rincian biaya internal). */
    public function toCheckoutArray(): array
    {
        $this->loadMissing('dues');

        return [
            'merchant_ref' => $this->merchant_ref,
            'status' => $this->status,
            'amount' => $this->amount,
            'qr_string' => $this->isPending() ? $this->qr_string : null,
            'expired_at' => $this->expired_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_channel' => $this->payment_channel,
            'items' => $this->dues->sortBy('period_start')->map(fn (MemberDue $d) => [
                'period_label' => $d->period_label,
                'amount' => (int) $d->pivot->amount,
            ])->values(),
        ];
    }
}
