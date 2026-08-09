/**
 * Read a server validation error by key.
 *
 * Inertia types `form.errors` against the form's *data* shape, which is only half the
 * story: a controller returning `back()->withErrors(['receive' => …])` reports a
 * failure of the action, not of a field, and those keys have no data property to be
 * typed against. Reaching for them through a cast at every call site is how one gets
 * spelled wrong and silently never renders.
 *
 * Also used where the payload is assembled in `transform()` — the form is opened with
 * an empty object because its state lives in pickers, so the field names never appear
 * in the data type either.
 */
export function formError(errors: object, key: string): string | undefined {
  return (errors as Record<string, string | undefined>)[key];
}
