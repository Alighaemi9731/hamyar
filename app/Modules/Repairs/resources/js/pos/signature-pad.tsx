import { RotateCcwIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

interface SignaturePadProps {
  /** Called with a PNG blob whenever the drawing changes, or null when cleared. */
  onChange: (signature: Blob | null) => void;
  className?: string;
}

/**
 * Signing for a device, with a finger.
 *
 * ## Pointer events, not mouse or touch events
 *
 * A `mousedown` handler does not fire for a finger, and a `touchstart` handler does not
 * fire for the shop's desk mouse or a stylus. Pointer events are the one API that covers
 * a finger on a counter phone, a stylus on a tablet, and a mouse on the back-office PC
 * without three code paths that drift apart.
 *
 * `setPointerCapture` is what makes a finger that slides off the edge of the pad still
 * finish its stroke rather than leaving a signature cut in half — which is exactly what
 * happens on a 390px screen where the pad is nearly the full width.
 *
 * `touch-action: none` is not optional. Without it the browser treats a drag as a scroll,
 * the page moves under the finger, and nothing is drawn at all.
 *
 * ## The canvas is sized in device pixels, drawn in CSS pixels
 *
 * A canvas whose backing store matches its CSS size is blurry on every phone made in the
 * last decade. It is scaled by `devicePixelRatio` and the context scaled to match, so a
 * signature captured on a counter phone is legible when it is printed on the receipt and
 * when it is produced months later in an argument about who collected the device.
 *
 * ## Re-measuring on resize would wipe the signature
 *
 * Sizing happens once, on mount. A rotation mid-signature is not worth handling: reading
 * the canvas back and repainting it would be more code than asking somebody to sign
 * again, and the clear button is right there.
 */
export function SignaturePad({ onChange, className }: SignaturePadProps) {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const drawing = useRef(false);
  const [hasInk, setHasInk] = useState(false);

  useEffect(() => {
    const canvas = canvasRef.current;

    if (!canvas) return;

    const ratio = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();

    canvas.width = rect.width * ratio;
    canvas.height = rect.height * ratio;

    const context = canvas.getContext('2d');

    if (!context) return;

    context.scale(ratio, ratio);
    context.lineWidth = 2;
    context.lineCap = 'round';
    context.lineJoin = 'round';
    // Explicit rather than inherited: the pad renders inside a themed page, and a
    // signature drawn in the dark-mode foreground colour is invisible on the white
    // receipt it ends up printed on.
    context.strokeStyle = '#111111';
  }, []);

  function positionOf(event: React.PointerEvent<HTMLCanvasElement>): { x: number; y: number } {
    const rect = event.currentTarget.getBoundingClientRect();

    return { x: event.clientX - rect.left, y: event.clientY - rect.top };
  }

  function emit(): void {
    const canvas = canvasRef.current;

    if (!canvas) return;

    canvas.toBlob((blob) => onChange(blob), 'image/png');
  }

  function clear(): void {
    const canvas = canvasRef.current;
    const context = canvas?.getContext('2d');

    if (!canvas || !context) return;

    context.clearRect(0, 0, canvas.width, canvas.height);
    setHasInk(false);
    onChange(null);
  }

  return (
    <div className={cn('space-y-2', className)}>
      <div className="relative">
        <canvas
          ref={canvasRef}
          // A signature needs room. 40vw tall on a phone is roughly a thumb's natural
          // arc; much shorter and people sign a flat squiggle they would not recognise.
          className="h-40 w-full touch-none rounded-control border border-dashed border-border bg-white"
          onPointerDown={(event) => {
            const context = event.currentTarget.getContext('2d');

            if (!context) return;

            // Capture, so a finger that slides past the edge of the pad on a narrow
            // screen still finishes its stroke.
            event.currentTarget.setPointerCapture(event.pointerId);
            drawing.current = true;

            const { x, y } = positionOf(event);

            context.beginPath();
            context.moveTo(x, y);
            setHasInk(true);
          }}
          onPointerMove={(event) => {
            if (!drawing.current) return;

            const context = event.currentTarget.getContext('2d');

            if (!context) return;

            const { x, y } = positionOf(event);

            context.lineTo(x, y);
            context.stroke();
          }}
          onPointerUp={(event) => {
            drawing.current = false;
            event.currentTarget.releasePointerCapture(event.pointerId);
            emit();
          }}
          onPointerCancel={() => {
            drawing.current = false;
          }}
          aria-label="امضای تحویل‌گیرنده"
          role="img"
        />

        {!hasInk && (
          <p className="pointer-events-none absolute inset-0 flex items-center justify-center text-sm text-muted-foreground">
            با انگشت امضا کنید
          </p>
        )}
      </div>

      <Button type="button" variant="ghost" size="sm" onClick={clear} disabled={!hasInk}>
        <RotateCcwIcon className="size-4" aria-hidden />
        پاک کردن
      </Button>
    </div>
  );
}
