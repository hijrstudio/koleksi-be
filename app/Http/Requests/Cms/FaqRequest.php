<?php

namespace App\Http\Requests\Cms;

use App\Enums\ContentStatus;
use App\Models\Faq;
use App\Support\RichTextSanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FaqRequest extends FormRequest
{
    public const MAX_QUESTION_LENGTH = 255;

    /** Panjang maksimal HTML jawaban (setelah disanitasi) per bahasa */
    public const MAX_ANSWER_LENGTH = 10_000;

    public const MAX_SORT_ORDER = 65_535;

    public function authorize(): bool
    {
        $faq = $this->route('faq');

        return $faq instanceof Faq
            ? $this->user()->can('update', $faq)
            : $this->user()->can('create', Faq::class);
    }

    /**
     * Jawaban disanitasi sebelum validasi, sehingga aturan required/max berlaku pada konten yang benar-benar disimpan.
     */
    protected function prepareForValidation(): void
    {
        $answer = $this->input('answer');

        if (! is_array($answer)) {
            return; // dibiarkan, akan ditolak oleh rule "array"
        }

        $sanitizer = app(RichTextSanitizer::class);

        foreach (['id', 'en'] as $locale) {
            if (is_string($answer[$locale] ?? null)) {
                $answer[$locale] = $sanitizer->clean($answer[$locale]);
            }
        }

        $this->merge(['answer' => $answer]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Bahasa Indonesia wajib; EN boleh kosong (fallback ke ID di website)
            'question' => ['required', 'array:id,en'],
            'question.id' => ['required', 'string', 'max:'.self::MAX_QUESTION_LENGTH],
            'question.en' => ['nullable', 'string', 'max:'.self::MAX_QUESTION_LENGTH],
            'answer' => ['required', 'array:id,en'],
            'answer.id' => ['required', 'string', 'max:'.self::MAX_ANSWER_LENGTH],
            'answer.en' => ['nullable', 'string', 'max:'.self::MAX_ANSWER_LENGTH],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_SORT_ORDER],
            'status' => ['required', Rule::enum(ContentStatus::class)],
        ];
    }

    /**
     * Data siap simpan. Urutan kosong = taruh di paling akhir.
     *
     * @return array<string, mixed>
     */
    public function faqData(): array
    {
        $translations = fn (string $field) => [
            'id' => trim($this->validated("$field.id")),
            'en' => filled($this->validated("$field.en")) ? trim($this->validated("$field.en")) : null,
        ];

        return [
            'question' => $translations('question'),
            'answer' => $translations('answer'),
            'sort_order' => $this->validated('sort_order') ?? Faq::nextSortOrder(),
            'status' => $this->validated('status'),
        ];
    }

    public function attributes(): array
    {
        return [
            'question.id' => 'pertanyaan (ID)',
            'question.en' => 'pertanyaan (EN)',
            'answer.id' => 'jawaban (ID)',
            'answer.en' => 'jawaban (EN)',
            'sort_order' => 'urutan',
            'status' => 'status',
        ];
    }
}
