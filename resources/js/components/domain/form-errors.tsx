import { OctagonAlertIcon } from 'lucide-react';

import { cn } from '@/lib/utils';

export interface FormErrorsProps {
  /** The whole error bag, exactly as Inertia hands it over. */
  errors: Record<string, string | string[] | undefined>;
  /**
   * Keys this form already renders beside their own input. Those are shown in the right
   * place and must not be repeated here — a message appearing twice reads as two problems.
   */
  handled?: string[];
  /**
   * Keys nothing should ever show here. `quota` is the standing case: `<QuotaBlock>`
   * renders it once in the shell with an upgrade button, and repeating it as a plain
   * sentence would put a worse version of the same message above a better one.
   */
  ignored?: string[];
  className?: string;
}

/** Rendered elsewhere, deliberately. Extend only with a reason. */
const ALWAYS_IGNORED = ['quota'];

/**
 * A home for the errors that belong to no field.
 *
 * ## The failure this exists to prevent
 *
 * From CLAUDE.md, written after it happened:
 *
 * > A validation failure on `accessories` or `lines` has nowhere to render beside an input,
 * > so without a general error region the submit button silently does nothing — and the
 * > operator, with a customer at the counter, presses it again and concludes the software
 * > is broken.
 *
 * That is the whole point. A form that renders `errors.name` and `errors.mobile` looks
 * complete, and it is — right up until the server refuses on `lines`, `accessories`,
 * `payments.0.amount` or a `ValidationException::withMessages(['transfer' => …])` thrown
 * from a service. Then the request 302s back, React re-renders identically, and nothing
 * anywhere on the screen has changed. There is no error state to debug because from the
 * operator's side there is no error: the button simply does not work.
 *
 * ## Why it takes the whole bag rather than a list of keys
 *
 * A component that had to be told which keys to show would need updating every time
 * somebody adds a validation rule — and the keys nobody thought to place are exactly the
 * ones that go missing. Passing the bag inverts that: new server rules are visible by
 * default, and hiding one is the deliberate act. `handled` is how a form says "this one
 * already has a home", so the default is safe and the exception is explicit.
 *
 * ## Nested keys collapse to their parent
 *
 * Laravel returns `lines.2.quantity`, and a form that renders `errors.lines` beside its
 * table will never match that string. So a key is treated as handled when the form handles
 * it *or any prefix of it* — `handled={['lines']}` covers every row and column beneath it.
 * Without this the region would double up on exactly the forms that did the right thing.
 */
export function FormErrors({ errors, handled = [], ignored = [], className }: FormErrorsProps) {
  const skip = new Set([...handled, ...ignored, ...ALWAYS_IGNORED]);

  const isHandled = (key: string) => {
    if (skip.has(key)) {
      return true;
    }

    // `lines.2.quantity` is handled by a form that handles `lines`. Walk the prefixes
    // rather than string-matching, so `linesman` never counts as a prefix of `lines`.
    const parts = key.split('.');

    for (let i = 1; i < parts.length; i++) {
      if (skip.has(parts.slice(0, i).join('.'))) {
        return true;
      }
    }

    return false;
  };

  const messages: string[] = [];

  for (const [key, value] of Object.entries(errors)) {
    if (value === undefined || isHandled(key)) {
      continue;
    }

    // Inertia normally flattens to one string per key, but a bag built by hand — or a
    // 422 read straight off an API — can carry the array. Both are rendered.
    for (const message of Array.isArray(value) ? value : [value]) {
      if (message && !messages.includes(message)) {
        messages.push(message);
      }
    }
  }

  if (messages.length === 0) {
    return null;
  }

  return (
    <div
      // `alert` rather than `status`: this interrupts. A screen reader must announce it
      // without waiting for the user to arrive at it, because the visual cue — a button
      // that did nothing — is the one thing a screen-reader user does not get.
      role="alert"
      className={cn(
        'rounded-card border border-danger/40 bg-danger/5 p-4 text-sm text-danger',
        className
      )}
    >
      <div className="flex items-start gap-3">
        <OctagonAlertIcon className="mt-0.5 size-4 shrink-0" aria-hidden />

        {messages.length === 1 ? (
          <p>{messages[0]}</p>
        ) : (
          <ul className="space-y-1">
            {messages.map((message) => (
              <li key={message}>{message}</li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
