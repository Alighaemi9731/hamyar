import { LoaderCircleIcon, UploadIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useId, useState } from 'react';

import { cn } from '@/lib/utils';

export interface FileDropProps {
  /** Accepted extensions, without the dot: `['csv', 'xlsx']`. Spoken back in the hint. */
  extensions: string[];
  /** Receives the one file that was chosen or dropped. */
  onFile: (file: File) => void;
  /** While the file is being read on the server; the drop is refused and says so. */
  busy?: boolean;
  /** One line under the prompt: what the file must look like. */
  hint?: string;
  /** Secondary actions that belong to the drop — a blank template to download, say. */
  children?: ReactNode;
  className?: string;
}

/**
 * A place to put a file, instead of the browser's own file control.
 *
 * `<input type="file">` renders the operating system's widget: «Choose file · No file
 * chosen» in the browser's language, in the browser's font, inside a Persian screen —
 * the one English sentence on the import page, and the one control the design system
 * does not draw. This is the same input made invisible, with a drawn surface in front
 * of it that says what to drop here and what happens next.
 *
 * ## Still an input
 *
 * The input stays in the document (`sr-only`, not `display:none`), so the label opens
 * the picker on click, the keyboard reaches it with Tab, and `focus-visible` on the
 * input paints the ring on the surface through `:has()`. Drag-and-drop is on top of
 * that, not instead of it — a phone has no drag, and neither does a screen reader.
 *
 * ## The extension is checked on drop
 *
 * `accept` filters the picker and nothing else: a dropped `.docx` arrives regardless.
 * Refusing it here with the accepted list is one round-trip fewer than letting the
 * server say so, and the message names the fix.
 */
export function FileDrop({
  extensions,
  onFile,
  busy = false,
  hint,
  children,
  className,
}: FileDropProps) {
  const id = useId();
  const [over, setOver] = useState(false);
  const [refused, setRefused] = useState<string | null>(null);

  const accepted = extensions.map((extension) => extension.toLowerCase());
  const list = extensions.map((extension) => extension.toUpperCase()).join('، ');

  const take = (file: File | undefined) => {
    if (!file || busy) return;

    const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

    if (!accepted.includes(extension)) {
      setRefused(`فایل «${file.name}» پذیرفته نمی‌شود؛ فقط ${list}.`);

      return;
    }

    setRefused(null);
    onFile(file);
  };

  return (
    <div className={cn('space-y-3', className)}>
      <label
        htmlFor={id}
        onDragOver={(event) => {
          event.preventDefault();
          if (!busy) setOver(true);
        }}
        onDragLeave={() => setOver(false)}
        onDrop={(event) => {
          event.preventDefault();
          setOver(false);
          take(event.dataTransfer.files[0]);
        }}
        className={cn(
          'flex cursor-pointer flex-col items-center justify-center gap-3 rounded-card border border-dashed px-6 py-10 text-center transition-colors duration-(--duration-fast)',
          'has-[:focus-visible]:ring-3 has-[:focus-visible]:ring-ring/50',
          over ? 'border-primary bg-primary/5' : 'border-border bg-surface/50 hover:bg-muted/40',
          busy && 'cursor-progress'
        )}
      >
        <input
          id={id}
          type="file"
          accept={accepted.map((extension) => `.${extension}`).join(',')}
          disabled={busy}
          className="sr-only"
          onChange={(event) => {
            take(event.target.files?.[0]);
            // Cleared so the same file can be chosen again after a fix.
            event.target.value = '';
          }}
        />

        <span
          aria-hidden
          className="flex size-12 items-center justify-center rounded-full bg-accent text-accent-foreground"
        >
          {busy ? (
            <LoaderCircleIcon className="size-6 animate-spin" />
          ) : (
            <UploadIcon className="size-6" />
          )}
        </span>

        <span className="space-y-1">
          <span className="block font-display text-base font-bold text-foreground">
            {busy ? 'در حال خواندن فایل…' : 'فایل را اینجا رها کنید یا انتخاب کنید'}
          </span>
          <span className="block text-xs text-muted-foreground">{hint ?? `فایل ${list}`}</span>
        </span>
      </label>

      {refused && (
        <p role="alert" className="text-sm text-destructive">
          {refused}
        </p>
      )}

      {children && <div className="flex flex-wrap items-center gap-2">{children}</div>}
    </div>
  );
}
