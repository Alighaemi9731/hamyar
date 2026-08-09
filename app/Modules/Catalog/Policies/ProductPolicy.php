<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Policies;

use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Models\User;

/**
 * Authorization for the catalogue.
 *
 * `managePrices` is separate from `update` because they are different jobs: a
 * warehousekeeper corrects a barcode, an owner decides what things cost. Iranian
 * prices move weekly and the bulk tool can move a whole category in one action, so it
 * is the permission most shops will want to keep to themselves.
 */
final class ProductPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('catalog.view');
    }

    public function view(User $actor, Product $product): bool
    {
        return $actor->can('catalog.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('catalog.create');
    }

    public function update(User $actor, Product $product): bool
    {
        return $actor->can('catalog.update');
    }

    public function delete(User $actor, Product $product): bool
    {
        return $actor->can('catalog.delete');
    }

    public function managePrices(User $actor): bool
    {
        return $actor->can('catalog.manage_prices');
    }
}
