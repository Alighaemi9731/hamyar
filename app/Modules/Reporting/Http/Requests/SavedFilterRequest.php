<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Requests;

use App\Modules\Reporting\Services\ReportCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saving a named filter set.
 *
 * ## The filter payload is bounded, even though it is opaque
 *
 * The screen decides what a preset's keys mean, so this cannot validate them by name
 * without a rule per report. What it can do — and must — is refuse a payload that is not a
 * flat map of short strings. Without that, `filters` is an unbounded user-controlled JSON
 * blob written to the database and handed back to a browser as query parameters.
 *
 * ## The report key is checked against the catalogue
 *
 * Not because a bogus key is dangerous — a preset grants nothing, and the screen it opens
 * gates itself — but because a preset saved against a typo silently never appears again, and
 * the shopkeeper's conclusion is that saving presets does not work.
 */
final class SavedFilterRequest extends FormRequest
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
            'report_key' => ['required', 'string', Rule::in($this->reportKeys())],
            'name' => ['required', 'string', 'max:60'],

            /*
            | `array` without `present`: a report whose filter bar is entirely at its
            | defaults sends no keys, and a preset of "the defaults" is a legitimate thing
            | to save. Requiring the key would reject exactly that.
            */
            'filters' => ['nullable', 'array', 'max:20'],
            'filters.*' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'report_key.in' => 'این گزارش شناخته نشد.',
            'name.required' => 'برای این ذخیره یک نام بگذارید.',
            'name.max' => 'نام نباید بیش از ۶۰ نویسه باشد.',
            'filters.max' => 'تعداد فیلترها بیش از حد مجاز است.',
        ];
    }

    /**
     * The screen keys a preset may belong to — the report *screens*, not the catalogue's
     * per-cut rows, because a preset stores the cut as one of its filters.
     *
     * @return list<string>
     */
    private function reportKeys(): array
    {
        $keys = [];

        foreach (ReportCatalogue::screens() as $key => $_label) {
            $keys[] = $key;
        }

        return $keys;
    }
}
