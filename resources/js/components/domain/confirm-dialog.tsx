import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';

export interface ConfirmDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  /** What will happen, in one sentence. Never "آیا مطمئن هستید؟". */
  description: ReactNode;
  /** The verb, not "تأیید" — «حذف کالا» tells the reader what the button does. */
  confirmLabel: string;
  cancelLabel?: string;
  /** Red fill. On by default: this component exists for destructive actions. */
  destructive?: boolean;
  processing?: boolean;
  onConfirm: () => void;
}

/**
 * The one confirmation.
 *
 * The copy rule is the whole point: the title names the thing, the description says
 * what actually happens to it, and the button carries the verb. «آیا مطمئن هستید؟»
 * with a «تأیید» button asks someone to confirm a decision the dialog never described,
 * which is how people click through and then ask where their data went.
 */
export function ConfirmDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel,
  cancelLabel = 'انصراف',
  destructive = true,
  processing = false,
  onConfirm,
}: ConfirmDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent dir="rtl">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            {cancelLabel}
          </Button>
          <Button
            type="button"
            variant={destructive ? 'destructive' : 'default'}
            disabled={processing}
            onClick={onConfirm}
          >
            {processing ? 'در حال انجام…' : confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
