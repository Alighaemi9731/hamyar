<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Files that belong to records.
 *
 * One table for every module's attachments, polymorphic on the owner. The alternative —
 * `ticket_photos`, `signatures`, `party_id_scans` — is three tables with the same six
 * columns and three chances to forget the tenant scope on one of them.
 *
 * ## The path is opaque and tenant-prefixed
 *
 * Stored as `t/{tenant}/{collection}/{ulid}.{ext}`. The ULID means a leaked object key
 * reveals nothing about what it is or how many exist, and the tenant prefix means a
 * misconfigured bucket policy fails at a shop boundary rather than across the whole
 * platform. The original filename is kept in a column for display — never in the path,
 * because customers name photos things like `شناسنامه-حسین.jpg`.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index();

            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');

            // `intake_photos`, `signature`, `id_scan`. Named by the consuming module.
            $table->string('collection', 40);

            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');

            $table->foreignId('uploaded_by')->nullable();
            $table->timestamps();

            // Leading with tenant_id (golden rule 1), then the lookup every consumer
            // makes: "the photos on this ticket".
            $table->index(['tenant_id', 'attachable_type', 'attachable_id', 'collection'], 'attachments_owner_index');
        });

        $this->enableRls('attachments');

        DB::statement(
            "comment on column attachments.path is
             'Opaque, tenant-prefixed object key. Never derived from a user-supplied filename.'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
