<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each automatic message says, per shop.
 *
 * ## The body and the provider pattern are both stored, and they are not the same thing
 *
 * `body` is the Persian sentence a shopkeeper writes and reads back — «سلام {name}، دستگاه
 * {device} آماده تحویل است.» It is what the template editor shows, what the preview renders,
 * and what a shop argues about.
 *
 * `provider_template_id` is the name of the pattern the regulator approved on the gateway
 * side. A shop cannot invent one: it is registered with Kavenegar, reviewed, and only then
 * usable. So the body is documentation and the pattern id is what actually sends — and the
 * variable ORDER in the body is what determines the token order on the wire.
 *
 * That coupling is the sharp edge of this table. Reordering `{name}` and `{device}` in the
 * body silently reorders the tokens, and the customer gets their device model where their
 * name should be. `TemplateRenderer` extracts them in order of appearance for exactly this
 * reason, and a test pins it.
 *
 * ## One template per automation per shop
 *
 * Not a library of drafts. A shop picking between three «آماده است» messages is a decision
 * nobody wants to make twice, and an automation firing whichever was most recently edited
 * is worse than one that always says the same thing.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('automation_key');

            // The Persian sentence, with {variables} in it.
            $table->text('body');

            // The gateway-side pattern name. Null until the shop registers one, which is
            // why an automation with no pattern is skipped rather than sent as free text —
            // free text to a do-not-disturb number is silently dropped by the carrier.
            $table->string('provider_template_id')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['tenant_id', 'automation_key'], 'message_templates_tenant_automation_unique');
        });

        $this->enableRls('message_templates');

        DB::statement('alter table message_templates add constraint message_templates_body_not_empty check (length(trim(body)) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
