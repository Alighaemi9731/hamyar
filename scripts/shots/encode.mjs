/**
 * Turn one raw capture into the files the landing ships.
 *
 * Three outputs per screen:
 *   <id>.webp        1440 wide — what the tour renders
 *   <id>@2x.webp     2880 wide — the srcset entry for a retina laptop
 *   <id>-phone.webp  a real crop of the region the caption talks about
 *
 * The phone variant is the point of this file. The landing currently fakes it in CSS by
 * zooming the desktop image inside a 358px frame (`focus`/`zoom` in the tour), which at
 * 390px shows about 44% of a 1440px screen — on the till tile that means the caption
 * promises trade-in, discount and payment methods while the crop shows the page title and
 * the scan box. A crop taken from the pixels is honest and costs nothing at runtime.
 *
 * `cwebp` (libwebp) does the encoding: it is already on this machine and in the CI image,
 * and it beats every JS encoder on both size and time for screenshots. `sips`/`ffmpeg` are
 * not used — `cwebp` alone covers crop-free encoding, and cropping is done by `sips` only
 * when it is present, otherwise the crop is skipped with a warning rather than shipping a
 * wrong rectangle.
 */

import { execFileSync } from 'node:child_process';
import { existsSync, unlinkSync } from 'node:fs';
import { join } from 'node:path';

/** Quality 82 is where a UI screenshot stops shrinking and starts smudging its 12px labels. */
const QUALITY = 82;

function has(binary) {
  try {
    execFileSync('which', [binary], { stdio: 'ignore' });

    return true;
  } catch {
    return false;
  }
}

function webp(source, destination, { width } = {}) {
  const args = ['-quiet', '-q', String(QUALITY), '-m', '6'];

  if (width) args.push('-resize', String(width), '0');

  args.push(source, '-o', destination);
  execFileSync('cwebp', args);
}

/**
 * @param {{raw: string, outDir: string, id: string, phone: null|{x:number,y:number,width:number,height:number}, viewport: {width:number,height:number}}} options
 * @returns {Promise<string[]>} the basenames written
 */
export async function encode({ raw, outDir, id, phone, viewport }) {
  if (!has('cwebp')) {
    throw new Error('cwebp is not installed. `brew install webp` (macOS) or `apt-get install webp` (CI).');
  }

  const written = [];

  const full = join(outDir, `${id}.webp`);
  webp(raw, full, { width: viewport.width });
  written.push(`${id}.webp`);

  const retina = join(outDir, `${id}@2x.webp`);
  webp(raw, retina);
  written.push(`${id}@2x.webp`);

  if (phone) {
    if (!has('sips')) {
      console.log(`    (no sips — skipped ${id}-phone.webp rather than shipping an uncropped one)`);

      return written;
    }

    // The raw capture is at deviceScaleFactor 2, so the CSS-pixel rectangle doubles.
    const crop = join(outDir, `${id}-phone.png`);
    execFileSync('sips', [
      '-c',
      String(phone.height * 2),
      String(phone.width * 2),
      '--cropOffset',
      String(phone.y * 2),
      String(phone.x * 2),
      raw,
      '--out',
      crop,
    ], { stdio: 'ignore' });

    const phoneOut = join(outDir, `${id}-phone.webp`);
    webp(crop, phoneOut, { width: phone.width });
    if (existsSync(crop)) unlinkSync(crop);
    written.push(`${id}-phone.webp`);
  }

  return written;
}
