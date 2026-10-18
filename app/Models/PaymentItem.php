<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tagihan iuran (member_dues) yang dilunasi oleh satu pembayaran, dengan nominal saat checkout. */
class PaymentItem extends Model
{
    protected $fillable = ['payment_id', 'member_due_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function due(): BelongsTo
    {
        return $this->belongsTo(MemberDue::class, 'member_due_id');
    }
}
