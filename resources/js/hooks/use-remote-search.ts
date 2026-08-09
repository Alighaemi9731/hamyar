import { useCallback, useEffect, useRef, useState } from 'react';

export type SearchStatus = 'idle' | 'loading' | 'ready' | 'error';

/**
 * How a picker fetches. Takes the typed term and an abort signal; returns rows.
 *
 * A function rather than a URL string so the `/design` gallery can drive the same
 * component from a fixture — a picker that can only be reviewed against a live
 * database never gets its loading and error states looked at.
 */
export type SearchFn<TRow> = (term: string, signal: AbortSignal) => Promise<TRow[]>;

interface Options {
  /** Stop searching entirely — a closed popover, a disabled field. */
  enabled?: boolean;
  /** Debounce, ms. 250 is about one Persian word typed at speed. */
  delay?: number;
}

interface RemoteSearch<TRow> {
  term: string;
  setTerm: (term: string) => void;
  results: TRow[];
  status: SearchStatus;
  retry: () => void;
}

/**
 * Debounced remote search with stale-response protection.
 *
 * The bug this exists to prevent: type "sam", then "samsung". Two requests are in
 * flight; if "sam" (the bigger, slower query) resolves second, its rows overwrite the
 * correct ones and the list contradicts the box the user is looking at. Both guards
 * are here — the in-flight request is aborted, and a late response is discarded by
 * sequence number even if the abort loses the race.
 */
export function useRemoteSearch<TRow>(
  search: SearchFn<TRow>,
  { enabled = true, delay = 250 }: Options = {}
): RemoteSearch<TRow> {
  const [term, setTerm] = useState('');
  const [results, setResults] = useState<TRow[]>([]);
  const [status, setStatus] = useState<SearchStatus>('idle');
  const [attempt, setAttempt] = useState(0);

  const sequence = useRef(0);

  const retry = useCallback(() => setAttempt((value) => value + 1), []);

  useEffect(() => {
    if (!enabled) {
      return;
    }

    const controller = new AbortController();
    const ticket = ++sequence.current;

    setStatus('loading');

    const timer = window.setTimeout(() => {
      search(term, controller.signal)
        .then((rows) => {
          if (ticket !== sequence.current) return;
          setResults(rows);
          setStatus('ready');
        })
        .catch((error: unknown) => {
          // An abort is us cancelling our own request, not a failure to report.
          if (controller.signal.aborted || ticket !== sequence.current) return;
          setStatus('error');
          if (import.meta.env.DEV) console.error(error);
        });
    }, delay);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [term, enabled, delay, attempt, search]);

  return { term, setTerm, results, status, retry };
}

/**
 * Build a {@link SearchFn} from one of the module lookup endpoints.
 *
 * `same-origin` credentials because the session cookie is what authorises the lookup;
 * the endpoints sit behind `auth` + `module:` middleware like every other route.
 */
export function endpointSearch<TRow>(
  url: string,
  params: Record<string, string | number | boolean> = {}
): SearchFn<TRow> {
  return async (term, signal) => {
    const query = new URLSearchParams({ q: term });

    for (const [key, value] of Object.entries(params)) {
      query.set(key, String(value));
    }

    const response = await fetch(`${url}?${query.toString()}`, {
      signal,
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });

    if (!response.ok) {
      throw new Error(`Search request to ${url} failed with ${response.status}.`);
    }

    const payload = (await response.json()) as { results?: TRow[] };

    return payload.results ?? [];
  };
}
