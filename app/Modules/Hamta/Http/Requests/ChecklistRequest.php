<?php

declare(strict_types=1);

namespace App\Modules\Hamta\Http\Requests;

use App\Modules\Hamta\Enums\ChecklistStep;
use App\Modules\Hamta\Models\HamtaChecklistAnswer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recording checklist answers.
 *
 * `answers` is nullable rather than present: a salesperson who opens the checklist, ticks
 * nothing and saves has answered nothing, and rejecting that would be rejecting the honest
 * case. The steps themselves are validated against the enum, because an answer keyed to a
 * step that does not exist would be stored and never rendered.
 */
final class ChecklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'answers' => ['nullable', 'array'],
            'answers.*.answer' => [
                'required',
                Rule::in([HamtaChecklistAnswer::ANSWER_CONFIRMED, HamtaChecklistAnswer::ANSWER_SKIPPED]),
            ],
            'answers.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        /** @var array<string, mixed> $answers */
        $answers = $this->input('answers', []);

        if (! is_array($answers)) {
            return;
        }

        $valid = array_map(static fn (ChecklistStep $step): string => $step->value, ChecklistStep::ordered());

        $this->merge([
            'answers' => array_intersect_key($answers, array_flip($valid)),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'answers.*.answer.required' => 'برای هر مرحله مشخص کنید انجام شد یا نشد.',
            'answers.*.note.max' => 'توضیح نباید بیش از ۵۰۰ نویسه باشد.',
        ];
    }
}
