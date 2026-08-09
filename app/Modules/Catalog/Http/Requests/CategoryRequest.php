<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating or renaming a node in the category tree.
 *
 * `exists` on the parent needs no tenant clause: RLS confines the lookup to this shop
 * at the database, so another shop's category id simply does not exist here.
 */
final class CategoryRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'نام دسته را بنویسید.',
            'name.max' => 'نام دسته حداکثر ۱۲۰ نویسه است.',
            'parent_id.exists' => 'دسته والد پیدا نشد.',
        ];
    }
}
