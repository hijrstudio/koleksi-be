<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Log mentah setiap callback dari gateway (audit & debug). */
class PaymentNotification extends Model
{
    protected $fillable = [
        'gateway', 'merchant_ref', 'gateway_status', 'accepted', 'processed',
        'ip', 'headers', 'body', 'error',
    ];

    protected function casts(): array
    {
        return [
            'accepted' => 'boolean',
            'processed' => 'boolean',
            'headers' => 'array',
        ];
    }
}
