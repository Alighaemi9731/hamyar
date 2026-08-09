import { Head, useForm } from '@inertiajs/react';
import { ChevronLeftIcon, FolderTreeIcon, PencilIcon, PlusIcon, Trash2Icon } from 'lucide-react';
import { useState } from 'react';

import { ConfirmDialog } from '@/components/domain/confirm-dialog';
import { EmptyState } from '@/components/domain/empty-state';
import { Num } from '@/components/domain/num';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { AppShell } from '@/layouts/app-shell';

interface CategoryNode {
  id: number;
  parent_id: number | null;
  name: string;
  slug: string;
  position: number;
  product_count: number;
  children: CategoryNode[];
}

interface CategoryOption {
  id: number;
  label: string;
}

interface Props {
  tree: CategoryNode[];
  options: CategoryOption[];
}

/** Sentinel for "no parent". A Radix Select item may not have an empty value. */
const ROOT = 'root';

/**
 * The category tree.
 *
 * Real shop trees are two or three levels — گوشی موبایل › اپل › آیفون ۱۵ — so the whole
 * thing is sent at once and edited in place. No drag-and-drop: reparenting is rare, and
 * a select is both keyboard-reachable and unambiguous about where the node landed.
 */
export default function CategoriesIndex({ tree, options }: Props) {
  const [editing, setEditing] = useState<CategoryNode | null>(null);
  const [creatingUnder, setCreatingUnder] = useState<CategoryNode | null | undefined>(undefined);

  return (
    <AppShell
      title="دسته‌بندی کالاها"
      actions={
        <Button onClick={() => setCreatingUnder(null)}>
          <PlusIcon className="size-4" />
          دسته جدید
        </Button>
      }
    >
      <Head title="دسته‌بندی کالاها" />

      {tree.length === 0 ? (
        <EmptyState
          icon={FolderTreeIcon}
          title="هنوز دسته‌ای نساخته‌اید"
          description="دسته‌ها فقط برای پیدا کردن کالا هستند؛ با دو یا سه دسته شروع کنید و بعد جزئی‌ترشان کنید."
          action={<Button onClick={() => setCreatingUnder(null)}>ساخت اولین دسته</Button>}
        />
      ) : (
        <ul className="divide-y divide-border rounded-card border border-border bg-card">
          {tree.map((node) => (
            <TreeRow
              key={node.id}
              node={node}
              depth={0}
              onEdit={setEditing}
              onAddChild={setCreatingUnder}
            />
          ))}
        </ul>
      )}

      {creatingUnder !== undefined && (
        <CategoryDialog
          key="create"
          options={options}
          parent={creatingUnder}
          onClose={() => setCreatingUnder(undefined)}
        />
      )}

      {editing && (
        <CategoryDialog
          key={`edit-${editing.id}`}
          options={options}
          category={editing}
          onClose={() => setEditing(null)}
        />
      )}
    </AppShell>
  );
}

function TreeRow({
  node,
  depth,
  onEdit,
  onAddChild,
}: {
  node: CategoryNode;
  depth: number;
  onEdit: (node: CategoryNode) => void;
  onAddChild: (node: CategoryNode) => void;
}) {
  const remove = useForm({});
  const [confirming, setConfirming] = useState(false);

  return (
    <>
      <li className="flex min-h-14 flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2.5">
        {/* Depth as inline padding-inline-start: a logical property, so the indent
            grows from the reading edge in either direction. A Tailwind class cannot
            express a runtime depth without an arbitrary-value string per level. */}
        <span
          className="flex min-w-0 flex-1 items-center gap-2"
          style={{ paddingInlineStart: `${depth * 1.5}rem` }}
        >
          {depth > 0 && (
            <ChevronLeftIcon
              className="size-3.5 shrink-0 text-muted-foreground rtl:rotate-180"
              aria-hidden
            />
          )}
          <span className="truncate text-sm font-medium">{node.name}</span>
          {node.product_count > 0 && (
            <span className="shrink-0 text-2xs text-muted-foreground">
              <Num value={node.product_count} /> کالا
            </span>
          )}
        </span>

        <span className="flex items-center gap-1">
          <Button
            variant="ghost"
            size="icon"
            aria-label={`زیرشاخه برای ${node.name}`}
            onClick={() => onAddChild(node)}
          >
            <PlusIcon className="size-4" />
          </Button>
          <Button
            variant="ghost"
            size="icon"
            aria-label={`ویرایش ${node.name}`}
            onClick={() => onEdit(node)}
          >
            <PencilIcon className="size-4" />
          </Button>
          <Button
            variant="ghost"
            size="icon"
            aria-label={`حذف ${node.name}`}
            // `group` so the icon only turns red under the cursor: four permanently
            // red bins are the loudest thing on a screen whose job is filing.
            className="group"
            onClick={() => setConfirming(true)}
          >
            <Trash2Icon className="size-4 text-muted-foreground transition-colors group-hover:text-destructive" />
          </Button>
        </span>
      </li>

      <ConfirmDialog
        open={confirming}
        onOpenChange={setConfirming}
        title={`حذف «${node.name}»`}
        description="کالاهای این دسته حذف نمی‌شوند؛ فقط بدون دسته نمایش داده می‌شوند. اگر این دسته زیرشاخه داشته باشد، حذف انجام نمی‌شود."
        confirmLabel="حذف دسته"
        processing={remove.processing}
        onConfirm={() =>
          remove.delete(`/catalog/categories/${node.id}`, {
            preserveScroll: true,
            onFinish: () => setConfirming(false),
          })
        }
      />

      {node.children.map((child) => (
        <TreeRow
          key={child.id}
          node={child}
          depth={depth + 1}
          onEdit={onEdit}
          onAddChild={onAddChild}
        />
      ))}
    </>
  );
}

function CategoryDialog({
  options,
  category,
  parent,
  onClose,
}: {
  options: CategoryOption[];
  category?: CategoryNode;
  parent?: CategoryNode | null;
  onClose: () => void;
}) {
  const form = useForm({
    name: category?.name ?? '',
    parent_id: String(category?.parent_id ?? parent?.id ?? ROOT),
    position: category?.position ?? 0,
  });

  // A category may not become its own descendant; the server refuses it too, but
  // leaving the option in the list invites the mistake.
  const parents = category ? options.filter((option) => option.id !== category.id) : options;

  function submit(event: React.FormEvent): void {
    event.preventDefault();

    // The Select cannot hold an empty value, so "no parent" travels as a sentinel and
    // becomes a real null here rather than reaching the integer validation rule.
    form.transform((data) => ({
      ...data,
      parent_id: data.parent_id === ROOT ? null : Number(data.parent_id),
    }));

    const options = { preserveScroll: true, onSuccess: onClose };

    if (category) {
      form.put(`/catalog/categories/${category.id}`, options);
    } else {
      form.post('/catalog/categories', options);
    }
  }

  return (
    <Dialog open onOpenChange={(next) => !next && onClose()}>
      <DialogContent dir="rtl">
        <form onSubmit={submit}>
          <DialogHeader>
            <DialogTitle>{category ? 'ویرایش دسته' : 'دسته جدید'}</DialogTitle>
            <DialogDescription>
              {parent
                ? `زیرشاخه‌ای برای «${parent.name}» ساخته می‌شود.`
                : 'دسته‌ها فقط برای پیدا کردن کالا هستند و روی قیمت یا موجودی اثری ندارند.'}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-5 py-4">
            <div className="space-y-2">
              <Label htmlFor="category-name">نام دسته</Label>
              <Input
                id="category-name"
                value={form.data.name}
                autoFocus
                onChange={(event) => form.setData('name', event.target.value)}
                aria-invalid={Boolean(form.errors.name)}
              />
              {form.errors.name && <p className="text-sm text-danger">{form.errors.name}</p>}
            </div>

            <div className="space-y-2">
              <Label htmlFor="category-parent">دسته والد</Label>
              <Select
                value={form.data.parent_id}
                onValueChange={(value) => form.setData('parent_id', value)}
              >
                <SelectTrigger id="category-parent" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent dir="rtl">
                  <SelectItem value={ROOT}>بدون والد (سطح اول)</SelectItem>
                  {parents.map((option) => (
                    <SelectItem key={option.id} value={String(option.id)}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {form.errors.parent_id && (
                <p className="text-sm text-danger">{form.errors.parent_id}</p>
              )}
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              انصراف
            </Button>
            <Button type="submit" disabled={form.processing}>
              {form.processing ? 'در حال ذخیره…' : 'ذخیره'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
