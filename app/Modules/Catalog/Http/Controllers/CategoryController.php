<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Requests\CategoryRequest;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\CategoryTree;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The category tree screen.
 *
 * Shallow in practice — گوشی موبایل › اپل › آیفون ۱۵ — so the whole tree is sent at
 * once and edited in place rather than paged or lazily expanded.
 */
final class CategoryController extends Controller
{
    public function index(CategoryTree $tree): Response
    {
        $this->authorize('viewAny', Category::class);

        return Inertia::render('Catalog::Categories/Index', [
            'tree' => $tree->tree(),
            'options' => $tree->options(),
        ]);
    }

    public function store(CategoryRequest $request, CategoryTree $tree): RedirectResponse
    {
        $this->authorize('create', Category::class);

        $name = $request->string('name')->trim()->value();

        Category::query()->create([
            'name' => $name,
            'slug' => $tree->slugFor($name),
            'parent_id' => $request->integer('parent_id') ?: null,
            'position' => $request->integer('position'),
        ]);

        return back()->with('success', 'دسته ثبت شد.');
    }

    public function update(CategoryRequest $request, Category $category, CategoryTree $tree): RedirectResponse
    {
        $this->authorize('update', $category);

        $parentId = $request->integer('parent_id') ?: null;

        try {
            $tree->guardMove($category, $parentId);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['parent_id' => $exception->getMessage()]);
        }

        $name = $request->string('name')->trim()->value();

        $category->update([
            'name' => $name,
            // The slug follows the name so a renamed category does not keep a stale
            // one on a storefront URL; `ignoreId` keeps it from colliding with itself.
            'slug' => $tree->slugFor($name, $category->id),
            'parent_id' => $parentId,
            'position' => $request->integer('position'),
        ]);

        return back()->with('success', 'دسته به‌روزرسانی شد.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        // Refused rather than cascaded. `parent_id` is nullOnDelete, so deleting a
        // parent would silently promote its children to the root — the products are
        // still there but nobody filed them where they now appear.
        if ($category->children()->exists()) {
            return back()->withErrors([
                'category' => 'این دسته زیرشاخه دارد. ابتدا زیرشاخه‌ها را جابه‌جا یا حذف کنید.',
            ]);
        }

        $category->delete();

        // Soft delete: the products keep their `category_id` but the category is gone
        // from every list, so they read as uncategorised without losing where they were
        // filed if the deletion is ever undone.
        return back()->with('success', 'دسته حذف شد. کالاهای آن بدون دسته نمایش داده می‌شوند.');
    }
}
