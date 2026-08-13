<?php

declare(strict_types=1);

namespace App\Support\Files;

/**
 * One stored file, as the rest of the app sees it.
 *
 * A value object rather than the Eloquent model, so a consuming module gets what it
 * needs — an id, a name, a size, a way to ask for a URL — without gaining the ability to
 * query, update or delete rows in a table another module owns (ADR 0003).
 */
final readonly class Attachment
{
    public function __construct(
        public int $id,
        public string $collection,
        public string $originalName,
        public string $mimeType,
        public int $sizeBytes,
        public string $path,
        public string $disk,
    ) {}

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    /**
     * @return array{id: int, collection: string, name: string, mime_type: string, size_bytes: int, is_image: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'collection' => $this->collection,
            'name' => $this->originalName,
            'mime_type' => $this->mimeType,
            'size_bytes' => $this->sizeBytes,
            'is_image' => $this->isImage(),
        ];
    }
}
