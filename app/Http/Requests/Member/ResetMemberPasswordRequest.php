<?php

namespace App\Http\Requests\Member;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Atur ulang kata sandi dari link e-mail (lupa kata sandi). Token & e-mail berasal dari link;
 * aturan kata sandi baru sama dengan ganti kata sandi di dasbor (UpdateMemberPasswordRequest).
 */
class ResetMemberPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => UpdateMemberPasswordRequest::newPasswordRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Link atur ulang kata sandi tidak valid.',
            'email.email' => 'Link atur ulang kata sandi tidak valid.',
            'token.required' => 'Link atur ulang kata sandi tidak valid.',
            ...UpdateMemberPasswordRequest::newPasswordMessages(),
        ];
    }

    public function email(): string
    {
        return Str::lower(trim($this->string('email')->toString()));
    }
}
