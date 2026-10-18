<?php

namespace App\Http\Resources;

use App\Models\Member;
use App\Models\MemberDue;
use App\Payments\BillingResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Data member yang sedang login (dasbor, header).
 *
 * @mixin Member
 */
class MemberProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MemberDue|null $term periode lunas terakhir */
        $term = $this->dues()->settled()->latest('period_end')->first();

        return [
            'id' => $this->id,
            'no_lambung' => $this->no_lambung,
            'no_lambung_label' => $this->noLambungLabel(),
            'nama_lengkap' => $this->nama_lengkap,
            'nama_panggilan' => $this->nama_panggilan,
            'email' => $this->email,
            'no_hp' => $this->no_hp,
            'photo_url' => $this->photo_url,
            'city_code' => $this->city_code,
            'city_name' => $this->city?->name,
            'tgl_bergabung' => $this->tgl_bergabung?->toDateString(),
            'membership' => [
                'status' => $this->status,
                'is_active' => $this->isActive(),
                'period_label' => $term?->period_label,
                'valid_from' => $term?->period_start?->toDateString(),
                'valid_until' => $this->paid_until?->toDateString(),
                'active_until' => $this->active_until?->toDateString(),   // termasuk masa tenggang
                'renewal_date' => $this->renewalDate()?->toDateString(),
            ],
            'billing' => app(BillingResolver::class)->summary($this->resource),
            'must_change_password' => $this->must_change_password,
            'profile_completed' => $this->profile_completed_at !== null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
