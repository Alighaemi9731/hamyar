<?php

declare(strict_types=1);

namespace App\Support\Files;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * Somewhere to put a file that belongs to a record.
 *
 * A shared-kernel **contract**, bound by the Files module (ADR 0003). Repairs needs to
 * keep intake photos and must not import Files to do it; Files owns the storage and must
 * not know a repair ticket exists.
 *
 * Deliberately narrow — four methods, all about one file attached to one model. A
 * general "upload anything anywhere" service would let every module invent its own
 * directory layout and its own idea of who may read a file back.
 */
interface AttachmentStore
{
    /**
     * Store a file against a record and return what was written.
     *
     * @param  string  $collection  groups files on the same record — `intake_photos`,
     *                              `signature`, `id_scan`. The consumer names it; the
     *                              store only keeps them apart.
     */
    public function attach(Model $owner, UploadedFile $file, string $collection, ?int $actorId = null): Attachment;

    /**
     * Every file on a record, in the order they were attached.
     *
     * @return list<Attachment>
     */
    public function for(Model $owner, ?string $collection = null): array;

    /**
     * A time-limited URL a browser can fetch the file from.
     *
     * Temporary and signed, never a public path: these are photographs of other people's
     * property, and a bucket URL that works forever is one that outlives the repair, the
     * customer relationship and the subscription.
     */
    public function temporaryUrl(Attachment $attachment, int $minutes = 15): string;

    /**
     * Remove a file and its row.
     */
    public function detach(Attachment $attachment): void;
}
