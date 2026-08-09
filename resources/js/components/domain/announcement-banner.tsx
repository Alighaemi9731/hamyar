import { AlertTriangleIcon, InfoIcon, OctagonAlertIcon } from 'lucide-react';

import type { Announcement } from '@/types';
import { cn } from '@/lib/utils';

const TONE = {
  info: {
    icon: InfoIcon,
    className: 'border-info/25 bg-info/10 text-info',
  },
  warning: {
    icon: AlertTriangleIcon,
    className: 'border-warning/25 bg-warning/10 text-warning',
  },
  critical: {
    icon: OctagonAlertIcon,
    className: 'border-danger/25 bg-danger/10 text-danger',
  },
} as const;

export interface AnnouncementBannerProps {
  announcements: Announcement[];
  className?: string;
}

/**
 * Platform notices, shown above page content.
 *
 * Not dismissible, and deliberately so: these are maintenance windows and billing
 * problems, and the only ones we publish are the ones a shop needs to see. Dismissal
 * would need per-user state to be honest about, which is a bigger feature than the
 * banner itself — and a banner that reappears every page load reads as broken.
 */
export function AnnouncementBanner({ announcements, className }: AnnouncementBannerProps) {
  if (announcements.length === 0) {
    return null;
  }

  return (
    <div className={cn('mb-6 space-y-3', className)}>
      {announcements.map((announcement) => {
        const tone = TONE[announcement.level] ?? TONE.info;
        const Icon = tone.icon;

        return (
          <div
            key={announcement.id}
            role="status"
            className={cn('flex gap-3 rounded-card border px-4 py-3', tone.className)}
          >
            <Icon className="mt-0.5 size-5 shrink-0" aria-hidden />

            <div className="min-w-0">
              <p className="font-medium">{announcement.title}</p>
              <p className="mt-1 text-sm opacity-90">{announcement.body}</p>
            </div>
          </div>
        );
      })}
    </div>
  );
}
