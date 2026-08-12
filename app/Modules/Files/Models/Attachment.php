<?php

declare(strict_types=1);

namespace App\Modules\Files\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The row behind a stored file.
 *
 * Internal to the Files module. Other modules receive
 * {@see \App\Support\Files\Attachment} — a value object — so they can display a file
 * without gaining the ability to query or delete rows in this table (ADR 0003).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property string $collection
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $uploaded_by
 */
final class Attachment extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'attachable_type', 'attachable_id', 'collection',
        'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'uploaded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
