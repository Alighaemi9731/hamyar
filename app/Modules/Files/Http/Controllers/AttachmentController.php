<?php

declare(strict_types=1);

namespace App\Modules\Files\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Files\Models\Attachment;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streaming a stored file back.
 *
 * Only used when the configured disk cannot sign its own URLs — the local driver, which
 * is what a development machine runs. In production MinIO/S3 signs directly and the
 * bytes never pass through PHP.
 *
 * The row is fetched through the tenant-scoped model, so RLS confines it to the shop the
 * hostname resolved to before authorization is even considered. A signed link minted for
 * one shop cannot address another's file even if the ids line up.
 */
final class AttachmentController extends Controller
{
    public function show(Request $request, int $attachment): StreamedResponse
    {
        $row = Attachment::query()->find($attachment);

        abort_if($row === null, 404);

        // Attachments inherit the visibility of what they hang off — a repair photo is
        // readable by somebody who may read the ticket. Checked against the owner rather
        // than given a permission of its own, so a new consumer cannot forget to add one.
        $owner = $row->attachable;

        abort_if($owner === null, 404);

        $this->authorize('view', $owner);

        return response()->streamDownload(
            function () use ($row): void {
                echo \Illuminate\Support\Facades\Storage::disk($row->disk)->get($row->path);
            },
            $row->original_name,
            ['Content-Type' => $row->mime_type],
            'inline',
        );
    }
}
