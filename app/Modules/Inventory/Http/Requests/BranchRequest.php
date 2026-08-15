<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating or renaming a branch.
 *
 * ## `code` is unique per tenant and it is not cosmetic
 *
 * It leads the document numbers a branch issues — «F-B2-1405-0001» — so two branches
 * sharing a code produce two invoice sequences that look like one, which is the exact
 * thing per-branch numbering exists to prevent. The database has the unique index; this
 * turns the 23505 into a sentence somebody can act on.
 *
 * ## `is_default` is set, never unset
 *
 * There is always exactly one default branch (a partial unique index says so), so the way
 * to stop a branch being the default is to make a different one the default. A checkbox
 * that could clear the flag would let a shop reach a state where a document with no branch
 * has nowhere to go.
 */
final class BranchRequest extends FormRequest
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
        $branchId = $this->route('branch');
        $ignore = is_object($branchId) && method_exists($branchId, 'getKey') ? $branchId->getKey() : $branchId;

        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required',
                'string',
                'max:10',
                // Latin letters, digits and a dash: it goes into a document number that a
                // customer reads aloud over the phone and a spreadsheet sorts.
                'regex:/^[A-Za-z0-9\-]+$/',
                Rule::unique('branches', 'code')->ignore($ignore)->whereNull('deleted_at'),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'نام شعبه لازم است.',
            'code.required' => 'کد شعبه لازم است — روی شماره فاکتورهای همان شعبه می‌نشیند.',
            'code.unique' => 'این کد قبلاً برای شعبهٔ دیگری ثبت شده است.',
            'code.regex' => 'کد شعبه فقط می‌تواند حروف لاتین، رقم و خط تیره باشد.',
            'code.max' => 'کد شعبه نباید بیش از ۱۰ نویسه باشد.',
        ];
    }
}
