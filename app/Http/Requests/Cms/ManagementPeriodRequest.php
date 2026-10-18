<?php

namespace App\Http\Requests\Cms;

use App\Enums\ContentStatus;
use App\Enums\ManagementGroup;
use App\Models\ManagementPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class ManagementPeriodRequest extends FormRequest
{
    public const MIN_YEAR = 1900;

    public const MAX_YEAR = 2200;

    public const MAX_MEMBERS = 100;

    public const MAX_PHOTO_KB = 2 * 1024;

    /** SVG sengaja tidak diizinkan (bisa berisi script / XSS) */
    public const PHOTO_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function authorize(): bool
    {
        $period = $this->period();

        return $period
            ? $this->user()->can('update', $period)
            : $this->user()->can('create', ManagementPeriod::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $period = $this->period();

        return [
            'start_year' => ['required', 'integer', 'between:'.self::MIN_YEAR.','.self::MAX_YEAR],
            'end_year' => [
                'required', 'integer', 'between:'.self::MIN_YEAR.','.self::MAX_YEAR, 'gte:start_year',
                Rule::unique('management_periods')
                    ->where('start_year', $this->integer('start_year'))
                    ->ignore($period),
            ],
            'status' => ['required', Rule::enum(ContentStatus::class)],

            'members' => ['required', 'array', 'min:1', 'max:'.self::MAX_MEMBERS],
            'members.*' => ['required', 'array:id,group,name,position,photo,remove_photo'],
            // id hanya boleh milik periode ini (mencegah mengubah pengurus periode lain)
            'members.*.id' => $period
                ? ['nullable', 'integer', 'distinct', Rule::exists('management_members', 'id')->where('management_period_id', $period->id)]
                : ['prohibited'],
            'members.*.group' => ['required', Rule::enum(ManagementGroup::class)],
            'members.*.name' => ['required', 'string', 'max:150'],
            'members.*.position' => ['required', 'array:id,en'],
            'members.*.position.id' => ['required', 'string', 'max:150'],
            'members.*.position.en' => ['nullable', 'string', 'max:150'],
            'members.*.photo' => [
                'nullable', 'file',
                'mimetypes:'.implode(',', self::PHOTO_MIMES),
                'extensions:'.implode(',', self::PHOTO_EXTENSIONS),
                'max:'.self::MAX_PHOTO_KB,
            ],
            'members.*.remove_photo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Baris pengurus siap simpan, urut sesuai form. sort_order dihitung per grup.
     *
     * @return list<array{id: ?int, photo: ?UploadedFile, remove_photo: bool, attributes: array<string, mixed>}>
     */
    public function memberRows(): array
    {
        $rows = [];
        $order = [];

        foreach ($this->validated('members') as $key => $member) {
            $group = ManagementGroup::from($member['group']);
            $order[$group->value] = ($order[$group->value] ?? 0) + 1;

            $rows[] = [
                'id' => isset($member['id']) ? (int) $member['id'] : null,
                'photo' => $this->file("members.$key.photo"),
                'remove_photo' => filter_var($member['remove_photo'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'attributes' => [
                    'group' => $group,
                    'name' => trim($member['name']),
                    'position' => [
                        'id' => trim($member['position']['id']),
                        'en' => filled($member['position']['en'] ?? null) ? trim($member['position']['en']) : null,
                    ],
                    'sort_order' => $order[$group->value],
                ],
            ];
        }

        return $rows;
    }

    public function attributes(): array
    {
        return [
            'start_year' => 'tahun mulai',
            'end_year' => 'tahun selesai',
            'members' => 'pengurus',
            'members.*.group' => 'grup',
            'members.*.name' => 'nama',
            'members.*.position.id' => 'jabatan (ID)',
            'members.*.position.en' => 'jabatan (EN)',
            'members.*.photo' => 'foto',
        ];
    }

    public function messages(): array
    {
        return [
            'end_year.unique' => 'Periode ini sudah ada.',
            'end_year.gte' => 'Tahun selesai tidak boleh lebih kecil dari tahun mulai.',
        ];
    }

    private function period(): ?ManagementPeriod
    {
        $period = $this->route('management_period');

        return $period instanceof ManagementPeriod ? $period : null;
    }
}
