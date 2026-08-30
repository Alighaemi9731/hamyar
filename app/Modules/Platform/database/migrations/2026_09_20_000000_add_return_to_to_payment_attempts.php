<?php

declare(strict_types=1);

use App\Modules\Platform\Support\ReturnPath;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where to put the shopkeeper back after they pay.
 *
 * A shop hits its monthly cap mid-sale, presses «ارتقا», pays, and returns. Without this
 * they land on the billing receipt and have to walk back to the till and retype a basket
 * they had already built — the upgrade worked and the sale still did not happen.
 *
 * On the attempt rather than in the session, because the session is the one thing that
 * cannot be relied on here: the customer may come back from the gateway in a different
 * browser, and `billing/callback` is deliberately outside `auth` and `tenant` for exactly
 * that reason (ADR 0017). The attempt row is what the authority already identifies, so it
 * is the only thing guaranteed to survive the round trip.
 *
 * Nullable, and every reader treats null as "the receipt". A shop that started its upgrade
 * from the billing page has no screen to go back to, and should not be sent anywhere
 * surprising.
 *
 * Bounded to {@see ReturnPath::MAX_LENGTH}, and never written unsanitised — see that class
 * on why a value which starts in a query string and ends at `redirect()` is an
 * open-redirect hole unless it is allow-listed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->string('return_to', ReturnPath::MAX_LENGTH)->nullable()->after('reference')
                ->comment('Same-host relative path to return to after payment; null = the receipt');
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropColumn('return_to');
        });
    }
};
