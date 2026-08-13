<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which nudges have already gone out about an uncollected device.
 *
 * ## This table is what makes the sweep idempotent
 *
 * The sweep runs on a schedule, and a scheduler is a thing that runs twice: a retry
 * after a timeout, a second worker on a bad deploy, somebody running the command by hand
 * to see what it does. Without a record of what has already been sent, each of those
 * texts every customer again — and the customer whose phone has been ready for ninety
 * days gets four identical messages in a minute.
 *
 * So a step is recorded before it is announced, and a unique index makes the second
 * attempt impossible rather than merely unlikely. Idempotency enforced by the database
 * survives two workers racing; a `SELECT` first does not.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('ticket_escalations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('repair_ticket_id');

            // 1-based position in the shop's configured escalation ladder.
            $table->unsignedTinyInteger('step');

            // Days after `ready_at` this step was configured to fire at. Snapshotted so a
            // shop that later rewrites its ladder does not make the history unreadable.
            $table->unsignedSmallInteger('after_days');

            $table->timestamps();

            /*
            | The idempotency guarantee. One row per ticket per step, enforced by the
            | database — so a second sweep, a retry, or two workers racing all collide on
            | an insert rather than quietly sending a duplicate.
            */
            $table->unique(['tenant_id', 'repair_ticket_id', 'step'], 'ticket_escalations_once');
        });

        $this->enableRls('ticket_escalations');

        DB::statement(
            'alter table ticket_escalations
             add constraint ticket_escalations_step_is_positive
             check (step > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_escalations');
    }
};
