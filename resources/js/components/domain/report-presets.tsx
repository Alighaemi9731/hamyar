import { router } from '@inertiajs/react';
import { BookmarkPlusIcon, XIcon } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export interface ReportPreset {
  id: number;
  name: string;
  filters: Record<string, string>;
}

export interface ReportPresetsProps {
  /** The screen key a preset belongs to — `sales`, `financial`, `tax`… */
  reportKey: string;
  presets: ReportPreset[];
  /** The filter values as they stand right now, which "ذخیره" captures. */
  current: Record<string, string>;
  /** Where applying a preset navigates to. */
  path: string;
}

/**
 * Saved filter presets for a report screen.
 *
 * ## Why the filters are opaque to this component
 *
 * Every report has a different filter bar — a range here, an as-of date there, a
 * threshold in days on dead stock — so this takes whatever map the screen hands it and
 * gives the same map back on apply. Teaching it the filters of seven screens would make
 * it the eighth place that has to change when one of them gains a control.
 *
 * ## Applying a preset is a navigation, not a state restore
 *
 * `router.get` with the preset's filters, so the URL after applying is a URL somebody can
 * bookmark, send to a colleague, or reload. Restoring into local state would leave the
 * address bar describing a report nobody is looking at — and the export link, which is
 * built from the query, would download a different range than the screen shows.
 *
 * ## A preset grants nothing
 *
 * It carries filters and a report key, never a permission. The screen it opens gates
 * itself exactly as it does for a typed URL, which is why presets can be listed for
 * anybody who can reach the report at all.
 */
export function ReportPresets({ reportKey, presets, current, path }: ReportPresetsProps) {
  const [naming, setNaming] = useState(false);
  const [name, setName] = useState('');

  const apply = (preset: ReportPreset) => {
    router.get(path, preset.filters, { preserveState: false, preserveScroll: true });
  };

  const save = () => {
    const trimmed = name.trim();

    if (trimmed === '') {
      return;
    }

    router.post(
      '/reporting/presets',
      { report_key: reportKey, name: trimmed, filters: current },
      {
        preserveScroll: true,
        onSuccess: () => {
          setName('');
          setNaming(false);
        },
      }
    );
  };

  const remove = (preset: ReportPreset) => {
    router.delete(`/reporting/presets/${preset.id}`, { preserveScroll: true });
  };

  return (
    <div className="flex flex-wrap items-center gap-2 print:hidden">
      {/*
        Both controls in each chip were under the floor: the preset itself overrode the
        button to 32px, and the delete beside it was a bare `<button>` wrapping a 14px
        glyph with no height at all — a destructive action at fourteen pixels, sitting two
        millimetres from the one that applies the preset.
      */}
      {presets.map((preset) => (
        <span
          key={preset.id}
          className="inline-flex items-center gap-0.5 rounded-full border bg-surface-muted pe-1 ps-1 text-sm"
        >
          <Button variant="ghost" className="rounded-full px-3" onClick={() => apply(preset)}>
            {preset.name}
          </Button>

          <button
            type="button"
            onClick={() => remove(preset)}
            aria-label={`حذف ${preset.name}`}
            className="flex size-10 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:text-danger focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
          >
            <XIcon className="size-3.5" aria-hidden />
          </button>
        </span>
      ))}

      {naming ? (
        <span className="inline-flex items-center gap-2">
          <Input
            value={name}
            onChange={(event) => setName(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                event.preventDefault();
                save();
              }

              if (event.key === 'Escape') {
                setNaming(false);
              }
            }}
            placeholder="نام این فیلتر"
            maxLength={60}
            autoFocus
            className="h-9 w-44"
          />

          <Button size="sm" onClick={save} disabled={name.trim() === ''}>
            ذخیره
          </Button>

          <Button size="sm" variant="ghost" onClick={() => setNaming(false)}>
            انصراف
          </Button>
        </span>
      ) : (
        <Button variant="ghost" size="sm" onClick={() => setNaming(true)}>
          <BookmarkPlusIcon className="size-4" aria-hidden />
          ذخیره فیلتر
        </Button>
      )}
    </div>
  );
}
