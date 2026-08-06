import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * Merge class names, letting later Tailwind utilities win over earlier ones.
 * Used by every shadcn component; do not reimplement it locally.
 */
export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}
