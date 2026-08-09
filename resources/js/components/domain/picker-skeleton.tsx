import { Skeleton } from '@/components/ui/skeleton';

/**
 * The loading state shared by `<PartyPicker/>` and `<UnitPicker/>`.
 *
 * Skeleton rows rather than a spinner, and four of them rather than one: the panel
 * keeps the height it is about to have, so the list does not shove the page around
 * when the results land.
 */
export function PickerSkeleton() {
  return (
    <div className="space-y-2 p-2" aria-hidden>
      {Array.from({ length: 4 }).map((_, index) => (
        <div key={index} className="space-y-1.5 rounded-inner px-2 py-1.5">
          <Skeleton className="h-4 w-2/5" />
          <Skeleton className="h-3 w-3/5" />
        </div>
      ))}
    </div>
  );
}
