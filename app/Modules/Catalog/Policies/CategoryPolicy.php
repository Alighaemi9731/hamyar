<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Policies;

use App\Modules\Catalog\Models\Category;
use App\Modules\Identity\Models\User;

/**
 * Authorization for the category tree.
 *
 * The tree is shared shop furniture rather than a per-product setting, so editing it
 * rides on the same `catalog.*` permissions as products — anyone trusted to add a
 * product is trusted to file it.
 */
final class CategoryPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('catalog.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('catalog.create');
    }

    public function update(User $actor, Category $category): bool
    {
        return $actor->can('catalog.update');
    }

    public function delete(User $actor, Category $category): bool
    {
        return $actor->can('catalog.delete');
    }
}
