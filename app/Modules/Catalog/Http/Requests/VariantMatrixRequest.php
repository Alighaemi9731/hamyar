<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Regenerating a product's variants from its attribute matrix.
 *
 * The wire shape is a list of axes rather than a map, because a map keyed by the
 * Persian axis name cannot express "the user has typed a name but no values yet" —
 * which is most of the time while the form is being filled in.
 */
final class VariantMatrixRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'axes' => ['present', 'array', 'max:4'],
            'axes.*.name' => ['required', 'string', 'max:40'],
            'axes.*.values' => ['required', 'array', 'min:1', 'max:40'],
            'axes.*.values.*' => ['required', 'string', 'max:40'],
        ];
    }

    /**
     * The matrix in the shape `VariantMatrix::generate()` wants.
     *
     * Blank names and blank values are dropped here rather than rejected: a half-typed
     * row in a form the user is still filling in is not an error, and refusing the whole
     * save because of one is how people lose work.
     *
     * @return array<string, list<string>>
     */
    public function axes(): array
    {
        /** @var list<array{name?: string, values?: list<string>}> $input */
        $input = $this->input('axes', []);

        $axes = [];

        foreach ($input as $axis) {
            $name = trim($axis['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $values = [];

            foreach ($axis['values'] ?? [] as $value) {
                $value = trim($value);

                if ($value !== '') {
                    $values[] = $value;
                }
            }

            if ($values !== []) {
                $axes[$name] = array_values(array_unique($values));
            }
        }

        return $axes;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'axes.max' => 'حداکثر ۴ ویژگی برای یک کالا پشتیبانی می‌شود.',
            'axes.*.name.required' => 'نام ویژگی را بنویسید (مثلاً رنگ).',
            'axes.*.values.required' => 'برای هر ویژگی حداقل یک مقدار لازم است.',
            'axes.*.values.max' => 'حداکثر ۴۰ مقدار برای هر ویژگی.',
        ];
    }
}
