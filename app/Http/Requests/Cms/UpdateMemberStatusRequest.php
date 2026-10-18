<?php

namespace App\Http\Requests\Cms;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberStatusRequest extends FormRequest
{
    /** Pilihan di form: aktif / tidak aktif (manual) atau ikuti iuran (otomatis) */
    public const AUTO = 'auto';

    public function authorize(): bool
    {
        return $this->user()->can('updateStatus', $this->route('member'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([...Member::STATUSES, self::AUTO])],
            // Alasan wajib saat menetapkan status manual, supaya jejak audit jelas
            'reason' => [Rule::requiredIf($this->input('status') !== self::AUTO), 'nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return ['reason' => 'alasan'];
    }
}
