import { router } from '@inertiajs/react';
import { BuildingIcon, CheckIcon, ChevronDownIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

export interface BranchState {
  /** The branch being viewed, or null for «همه شعب». */
  current: number | null;
  /** False for a user with one branch — there is nothing to consolidate. */
  can_consolidate: boolean;
  options: { id: number; name: string }[];
}

/**
 * Which branch the user is looking at.
 *
 * ## It renders nothing for a single-branch shop
 *
 * Which is almost every shop. A control offering one choice is a control that does
 * nothing, and putting it in permanent chrome would tax every screen in the product with
 * a decoration. `options.length < 2` is the whole condition — and it covers both "this
 * shop has one branch" and "this user is pinned to one of several", which want the same
 * answer for different reasons.
 *
 * ## «همه شعب» is a view, never a permission
 *
 * The server decides what it means: `BranchContext` applies the user's access floor
 * whether or not a branch is pinned, so consolidated shows everything *they are allowed*
 * rather than everything there is. The button cannot widen anybody's reach — which is why
 * it is safe to offer it as a plain menu item.
 *
 * ## Switching reloads the page it was pressed on
 *
 * `router.post` to a route that redirects back, so whatever the user was reading
 * re-renders under the new filter. That is the behaviour of a filter rather than of
 * navigation, and it is what lets the control live in the header without hijacking
 * wherever somebody happens to be.
 */
export function BranchSwitcher({ branch }: { branch?: BranchState }) {
  const options = branch?.options ?? [];

  if (options.length < 2) {
    return null;
  }

  const current = branch?.current ?? null;
  const active = options.find((option) => option.id === current);

  const choose = (branchId: number | null) => {
    if (branchId === current) {
      return;
    }

    router.post(
      '/branch/switch',
      { branch_id: branchId },
      { preserveScroll: true, preserveState: false }
    );
  };

  return (
    /* `dir="rtl"` on the Root for menu primitives — see the design system's rule 2. */
    <DropdownMenu dir="rtl">
      <DropdownMenuTrigger asChild>
        {/* No `size="sm"` with an `h-10` override beside it: the override existed
            because `sm` is 28px and this is a primary nav control. The default is 40px,
            so the size prop was saying the opposite of what the class enforced. */}
        <Button variant="outline" className="gap-2">
          <BuildingIcon className="size-4 shrink-0" aria-hidden />
          <span className="max-w-32 truncate">{active ? active.name : 'همه شعب'}</span>
          <ChevronDownIcon className="size-4 shrink-0 opacity-60" aria-hidden />
        </Button>
      </DropdownMenuTrigger>

      <DropdownMenuContent align="end" className="w-56">
        <DropdownMenuLabel>نمایش اطلاعات کدام شعبه؟</DropdownMenuLabel>
        <DropdownMenuSeparator />

        {branch?.can_consolidate ? (
          <DropdownMenuItem onSelect={() => choose(null)} className="gap-2">
            <CheckIcon
              className={`size-4 ${current === null ? 'opacity-100' : 'opacity-0'}`}
              aria-hidden
            />
            همه شعب
          </DropdownMenuItem>
        ) : null}

        {options.map((option) => (
          <DropdownMenuItem key={option.id} onSelect={() => choose(option.id)} className="gap-2">
            <CheckIcon
              className={`size-4 ${current === option.id ? 'opacity-100' : 'opacity-0'}`}
              aria-hidden
            />
            {option.name}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
