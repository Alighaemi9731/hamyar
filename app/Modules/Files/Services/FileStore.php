<?php

declare(strict_types=1);

namespace App\Modules\Files\Services;

use App\Modules\Files\Models\Attachment as AttachmentRow;
use App\Support\Files\Attachment;
use App\Support\Files\AttachmentStore;
use App\Support\Quota\QuotaGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The Files module's implementation of the shared-kernel contract.
 *
 * ## The stored path never contains anything a user typed
 *
 * A key is `t/{tenant}/{collection}/{ulid}.{ext}`. The original filename is kept in a
 * column for display and is never part of the path, for three reasons that all bite:
 * customers name photos things like `شناسنامه-حسین.jpg`, S3 keys with non-ASCII and
 * spaces are a lifetime of encoding bugs, and a filename in a URL is a traversal
 * question somebody eventually gets wrong.
 *
 * The extension is re-derived from the detected MIME type rather than trusted from the
 * upload, so `evil.php` uploaded as an image cannot land on disk with a `.php` key.
 *
 * ## URLs are temporary and signed, always
 *
 * These are photographs of other people's property. A public bucket URL outlives the
 * repair, the customer relationship and the subscription; a signed one expires while the
 * customer is still at the counter.
 */
final class FileStore implements AttachmentStore
{
    /** Well past a phone photo, well short of somebody uploading a video of the bench. */
    private const MAX_BYTES = 12 * 1024 * 1024;

    /**
     * What a repair bench and a counter actually produce. Deliberately a short list:
     * an attachment store that accepts anything is a malware host with a database.
     *
     * @var array<string, string> mime => extension
     */
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'application/pdf' => 'pdf',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly QuotaGuard $quota,
        private readonly ConnectionInterface $connection,
    ) {}

    public function attach(Model $owner, UploadedFile $file, string $collection, ?int $actorId = null): Attachment
    {
        $tenantId = $this->context->idOrFail();

        $this->guard($file);

        $mime = (string) $file->getMimeType();
        $extension = self::ALLOWED[$mime];

        // Opaque and tenant-prefixed: a leaked object key says nothing about what it is
        // or how many exist, and a misconfigured bucket policy fails at a shop boundary
        // rather than across the platform.
        $path = sprintf('t/%d/%s/%s.%s', $tenantId, $this->safeCollection($collection), Str::ulid(), $extension);

        $bytes = $file->getSize() ?: 0;

        /*
        | Both credits and the row, in one transaction — and the object written LAST.
        |
        | The old order put the blob on the disk before the row existed, so anything that
        | failed after it left a file nothing referenced: invisible, uncountable, and
        | still on the bill. Now a refused upload leaves nothing behind at all, and a
        | storage failure rolls the row back with it.
        |
        | Two metrics because they cap different things: `files.attachments` is how many
        | files a month, `files.storage_mb` is how much space in total. A shop can be
        | inside one and against the other — a hundred small photos, or one enormous PDF.
        */
        /** @var AttachmentRow $row */
        $row = $this->connection->transaction(function () use ($owner, $collection, $path, $file, $mime, $bytes, $actorId): AttachmentRow {
            $this->quota->consume('files.attachments');

            $megabytes = (int) ceil($bytes / 1_048_576);

            if ($megabytes > 0) {
                $this->quota->consume('files.storage_mb', $megabytes);
            }

            /** @var AttachmentRow $row */
            $row = AttachmentRow::query()->create([
                'attachable_type' => $owner::class,
                'attachable_id' => $owner->getKey(),
                'collection' => $collection,
                'disk' => $this->diskName(),
                'path' => $path,
                // Kept for display only. Never used to build the key above.
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime_type' => $mime,
                'size_bytes' => $bytes,
                'uploaded_by' => $actorId,
            ]);

            $this->disk()->put($path, $file->getContent(), 'private');

            return $row;
        });

        return $this->toValue($row);
    }

    /**
     * @return list<Attachment>
     */
    public function for(Model $owner, ?string $collection = null): array
    {
        $rows = AttachmentRow::query()
            ->where('attachable_type', $owner::class)
            ->where('attachable_id', $owner->getKey())
            ->when($collection !== null, fn ($query) => $query->where('collection', $collection))
            ->orderBy('id')
            ->get()
            ->all();

        return array_values(array_map(fn (AttachmentRow $row): Attachment => $this->toValue($row), $rows));
    }

    public function temporaryUrl(Attachment $attachment, int $minutes = 15): string
    {
        $disk = Storage::disk($attachment->disk);

        // The local driver cannot sign, so a dev machine falls back to a route-signed
        // URL. Deliberately not a public path even there: a habit that only holds in
        // production is one that gets broken in development and shipped.
        if (! method_exists($disk, 'temporaryUrl')) {
            return route('files.show', ['attachment' => $attachment->id]);
        }

        return $disk->temporaryUrl($attachment->path, now()->addMinutes($minutes));
    }

    public function detach(Attachment $attachment): void
    {
        Storage::disk($attachment->disk)->delete($attachment->path);

        AttachmentRow::query()->whereKey($attachment->id)->delete();
    }

    private function guard(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new RuntimeException('فایل به‌درستی بارگذاری نشد؛ دوباره تلاش کنید.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('حجم فایل بیش از حد مجاز است (حداکثر ۱۲ مگابایت).');
        }

        // Detected from the file's own bytes, not from the name or the browser's claim.
        $mime = $file->getMimeType();

        if ($mime === null || ! array_key_exists($mime, self::ALLOWED)) {
            throw new RuntimeException('این نوع فایل پذیرفته نمی‌شود؛ عکس یا PDF بفرستید.');
        }
    }

    /**
     * Collections come from our own code, never from a request — but this is the string
     * that goes into a path, so it is constrained anyway. Defence at the boundary costs
     * nothing and survives somebody later wiring a collection name to user input.
     */
    private function safeCollection(string $collection): string
    {
        $safe = preg_replace('/[^a-z0-9_-]/', '', mb_strtolower($collection));

        return is_string($safe) && $safe !== '' ? $safe : 'misc';
    }

    private function toValue(AttachmentRow $row): Attachment
    {
        return new Attachment(
            id: $row->id,
            collection: $row->collection,
            originalName: $row->original_name,
            mimeType: $row->mime_type,
            sizeBytes: $row->size_bytes,
            path: $row->path,
            disk: $row->disk,
        );
    }

    private function diskName(): string
    {
        return config()->string('filesystems.default');
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }
}
