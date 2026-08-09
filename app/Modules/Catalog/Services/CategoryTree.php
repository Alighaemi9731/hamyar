<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Category;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Reading and editing the category tree.
 *
 * The tree is an adjacency list (see the migration for why), so every operation here
 * is about the two things an adjacency list will not enforce for you: slugs that stay
 * unique per shop, and a move that does not make a node its own ancestor.
 */
final class CategoryTree
{
    /**
     * The whole tree as nested arrays, ordered by position then name.
     *
     * Loaded in one query and assembled in memory. A shop has tens of categories, not
     * thousands, and one query beats a recursive CTE nobody can read.
     *
     * @return list<array{id: int, parent_id: int|null, name: string, slug: string, position: int, product_count: int, children: list<mixed>}>
     */
    public function tree(): array
    {
        $rows = Category::query()
            ->withCount('products')
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        /** @var array<int, list<array<string, mixed>>> $byParent */
        $byParent = [];

        foreach ($rows as $row) {
            /** @var int $id */
            $id = $row->getKey();
            /** @var int $count */
            $count = $row->getAttribute('products_count');

            $byParent[$row->parent_id ?? 0][] = [
                'id' => $id,
                'parent_id' => $row->parent_id,
                'name' => $row->name,
                'slug' => $row->slug,
                'position' => $row->position,
                'product_count' => $count,
                'children' => [],
            ];
        }

        return $this->assemble($byParent, 0);
    }

    /**
     * Flat list of every category with its full path — what a `<Select/>` needs.
     *
     * @return list<array{id: int, label: string}>
     */
    public function options(): array
    {
        $options = [];

        $walk = function (array $nodes, string $prefix) use (&$walk, &$options): void {
            foreach ($nodes as $node) {
                /** @var array{id: int, name: string, children: list<mixed>} $node */
                $label = $prefix === '' ? $node['name'] : $prefix.' › '.$node['name'];

                $options[] = ['id' => $node['id'], 'label' => $label];

                $walk($node['children'], $label);
            }
        };

        $walk($this->tree(), '');

        return $options;
    }

    /**
     * A slug that is unique inside this shop.
     *
     * `Str::slug` is called with a null language so it does NOT transliterate to ASCII:
     * a Persian name would otherwise slug to an empty string and every category would
     * collide with every other one.
     */
    public function slugFor(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name, '-', null);

        if ($base === '') {
            $base = 'category';
        }

        $slug = $base;
        $suffix = 2;

        while ($this->slugTaken($slug, $ignoreId)) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * Refuse a move that would detach a subtree from the tree.
     *
     * Making a node its own descendant does not error in an adjacency list — it
     * produces a cycle that simply stops appearing in the tree, taking every product
     * filed under it out of the catalogue with no message.
     */
    public function guardMove(Category $category, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($parentId === $category->getKey()) {
            throw new RuntimeException('یک دسته نمی‌تواند زیرمجموعه خودش باشد.');
        }

        $ancestor = Category::query()->find($parentId);

        while ($ancestor instanceof Category) {
            if ($ancestor->getKey() === $category->getKey()) {
                throw new RuntimeException('این دسته زیرمجموعه خودش می‌شود؛ ابتدا زیرشاخه را جابه‌جا کنید.');
            }

            $ancestor = $ancestor->parent_id === null
                ? null
                : Category::query()->find($ancestor->parent_id);
        }
    }

    /**
     * @param  array<int, list<array<string, mixed>>>  $byParent
     * @return list<array{id: int, parent_id: int|null, name: string, slug: string, position: int, product_count: int, children: list<mixed>}>
     */
    private function assemble(array $byParent, int $parentKey): array
    {
        $nodes = $byParent[$parentKey] ?? [];

        foreach ($nodes as $index => $node) {
            /** @var int $id */
            $id = $node['id'];

            $nodes[$index]['children'] = $this->assemble($byParent, $id);
        }

        /** @var list<array{id: int, parent_id: int|null, name: string, slug: string, position: int, product_count: int, children: list<mixed>}> $nodes */
        return $nodes;
    }

    private function slugTaken(string $slug, ?int $ignoreId): bool
    {
        return Category::query()
            ->withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}
