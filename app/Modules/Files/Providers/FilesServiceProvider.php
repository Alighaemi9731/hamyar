<?php

declare(strict_types=1);

namespace App\Modules\Files\Providers;

use App\Modules\Files\Models\Attachment;
use App\Modules\Files\Services\FileStore;
use App\Support\Files\AttachmentStore;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Quota\Metric;
use App\Support\Quota\MetricRegistry;
use App\Support\Quota\Window;

/**
 * Files module.
 *
 * Spec: docs/specs/files.md
 *
 * Migrations, views, translations and routes are picked up by convention — see
 * App\Support\Modules\ModuleServiceProvider. Register bindings and event listeners
 * here. Cross-module calls go through domain events or a public interface bound
 * below; never by reaching into another module's namespace (ADR 0003, enforced by
 * tests/Arch/ModuleBoundariesTest.php).
 */
final class FilesServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        /*
        | What this module meters. Declared here rather than in Platform so shipping a
        | metered action is a change in one module (golden rule 6), and registered with
        | `afterResolving` so provider discovery order — a directory listing — cannot
        | leave them out.
        */
        $this->app->afterResolving(MetricRegistry::class, static function (MetricRegistry $registry): void {
            $registry->register(
                new Metric('files.attachments', 'پیوست', Window::Month, 'files', unitFa: 'پیوست', position: 96),

                /*
                | Storage in whole megabytes, measured rather than counted.
                |
                | A counter would drift the moment a file is deleted, and «شما ۵۰۰ مگابایت
                | مصرف کرده‌اید» after the shop has just cleared half of it is the kind of
                | wrong number that makes people stop believing every other number on the
                | page. `SUM` over an indexed column is cheap at the scale this caps.
                */
                new Metric(
                    'files.storage_mb', 'فضای ذخیره‌سازی', Window::Total, 'files',
                    unitFa: 'مگابایت', position: 97,
                    measure: static fn (int $tenantId): int => (int) floor(
                        ((int) Attachment::query()->where('tenant_id', $tenantId)->sum('size_bytes')) / 1_048_576
                    ),
                ),
            );
        });

        // The shared-kernel contract, bound here. Repairs asks the interface and never
        // learns this class exists (ADR 0003).
        $this->app->singleton(AttachmentStore::class, FileStore::class);
    }
}
