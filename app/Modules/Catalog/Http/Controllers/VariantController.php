<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The per-variant details the matrix cannot generate: its barcode, its SKU, and
 * whether the shop still sells it.
 *
 * Both codes are unique per shop among LIVE rows only, enforced by partial indexes.
 * The `Rule::unique` below mirrors that so the operator gets a field-level message
 * instead of a database exception.
 */
final class VariantController extends Controller
{
    public function update(Request $request, ProductVariant $variant): RedirectResponse
    {
        $this->authorize('update', $variant->product);

        $unique = fn (string $column) => Rule::unique('product_variants', $column)
            ->ignore($variant->getKey())
            ->whereNull('deleted_at');

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'sku' => ['nullable', 'string', 'max:64', $unique('sku')],
            'barcode' => ['nullable', 'string', 'max:64', $unique('barcode')],
            'is_active' => ['boolean'],
        ], [
            'sku.unique' => 'این کد کالا قبلاً برای تنوع دیگری ثبت شده است.',
            'barcode.unique' => 'این بارکد قبلاً برای تنوع دیگری ثبت شده است.',
        ]);

        // Empty strings become null so the partial unique indexes keep working: two
        // variants with '' collide, two with NULL do not.
        foreach (['name', 'sku', 'barcode'] as $column) {
            if (($validated[$column] ?? null) === '') {
                $validated[$column] = null;
            }
        }

        $variant->update($validated);

        return back()->with('success', 'تنوع به‌روزرسانی شد.');
    }
}
