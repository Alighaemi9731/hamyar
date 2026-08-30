<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Concerns;

use App\Support\Quota\QuotaGuard;
use Illuminate\Database\ConnectionInterface;

/**
 * Spend one `reporting.exports` credit.
 *
 * ## The named exception to "consume inside the transaction that writes the row"
 *
 * There is no row. An export builds a workbook and streams it; the count IS the only
 * write. So this opens a transaction of its own — the statement still needs one to be
 * atomic — and does it after the workbook is built, so a report that fails to render
 * costs nothing.
 *
 * A trait rather than a base controller because the seven report controllers already
 * extend the application's `Controller` and share nothing else; a base class invented for
 * one method would be a hierarchy pretending to be a concept.
 */
trait MetersExports
{
    protected function meterExport(): void
    {
        app(ConnectionInterface::class)->transaction(
            static fn () => app(QuotaGuard::class)->consume('reporting.exports')
        );
    }
}
