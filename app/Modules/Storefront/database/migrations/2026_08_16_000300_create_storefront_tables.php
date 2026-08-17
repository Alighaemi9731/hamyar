<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The public shop page, and the reseller price list a shop actually asks for.
 *
 * Scope fixed at DECISION GATE 4 (part 1): a public page, a live-price catalogue, reseller
 * links, PDF, WhatsApp. **No cart, no checkout, no customer accounts.** This sells the phone
 * call, not the basket — which is also why there is no `orders` table here and will not be
 * one before launch.
 *
 * ## The token is hashed, like a password, because that is what it is
 *
 * `price_list_links.token_hash` rather than `token`. A link is a **bearer credential**: it
 * grants reseller prices to whoever holds the URL, and reseller prices are the most
 * commercially sensitive figures a shop has — the whole reason they are not on the public
 * page. Storing the raw token would mean a database dump, a replica or a backup hands over
 * every live price list in plaintext, and the shop would never know.
 *
 * So the token is generated once, shown once, and only its hash is kept. A shop that loses
 * the URL mints a new link; that is cheaper than the alternative.
 *
 * `lookup` carries the first characters so the row can be *found* without scanning and
 * hashing every link — the same split a password-reset table makes, and it is a prefix
 * rather than the whole thing precisely so it is not itself the credential.
 *
 * ## `price_level_id` is on the row and is never read from the request
 *
 * The spec's rule: *the token grants only the price level it was minted with; changing the
 * URL cannot escalate to another level.* That is a property of the schema, not of a
 * controller being careful — there is nowhere in the request for a price level to come from.
 *
 * ## `price_list_views` exists so a shop can see a leak
 *
 * A link forwarded to a competitor looks exactly like a link used by its recipient, except
 * in the access log. Every view is a row: when, from where, with what user agent.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('storefront_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->boolean('is_enabled')->default(false);

            // The public path segment: `/shop/{slug}`. Unique across tenants, because the
            // storefront is reachable on the apex as well as on the shop's subdomain.
            $table->string('slug')->nullable();

            $table->string('display_name')->nullable();
            $table->text('about')->nullable();
            $table->text('address')->nullable();

            // Stored normalised, like every other number in this product — an operator
            // types four spellings of one number and the WhatsApp link has to work.
            $table->string('phone', 20)->nullable();
            $table->string('whatsapp', 20)->nullable();

            $table->string('working_hours')->nullable();

            // Which categories the public page shows. Null means all.
            $table->jsonb('categories')->nullable();

            // A shop with three phones left does not want «۳ عدد» on a public page —
            // it invites haggling and it is stale within the hour. The public page shows
            // «موجود» or «تماس بگیرید», never a count (see the spec).
            $table->boolean('shows_out_of_stock')->default(false);

            $table->timestamps();

            $table->unique('tenant_id');
        });

        $this->enableRls('storefront_settings');

        DB::statement(
            'create unique index storefront_settings_slug_unique
             on storefront_settings (slug)
             where slug is not null'
        );

        Schema::create('price_list_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // A short, non-secret prefix, so the row can be found without hashing every
            // candidate. NOT the credential — see the class docblock.
            $table->string('lookup', 12);

            // The credential itself, hashed. Never stored raw, never shown twice.
            $table->string('token_hash');

            $table->string('label')->nullable()->comment('Who the shop sent it to — «حاج آقای رضایی»');

            $table->foreignId('price_level_id')->constrained('price_levels')->restrictOnDelete();

            // Nullable: a shop sending a list to one trusted colleague should not be forced
            // to invent a password and then send it over the same channel.
            $table->string('password_hash')->nullable();

            // NOT nullable. The spec says «always with an expiry», default 7 days: a link
            // that never expires is one that leaks eventually and is never noticed.
            $table->timestampTz('expires_at');

            $table->jsonb('categories')->nullable();

            $table->unsignedInteger('view_count')->default(0);
            $table->timestampTz('last_viewed_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'revoked_at'], 'price_list_links_tenant_active_idx');
        });

        /*
        | `allowPlatform` — one of the few tables that opts in, and the reason is narrow.
        |
        | A visitor holding a price-list link arrives with **no tenant**: not a session, not
        | necessarily the right subdomain, nothing but the token. There is no way to scope
        | the lookup until the row is found, so `PriceListAccess::resolve()` runs exactly one
        | statement under `runAsPlatform()` — a single indexed lookup, no joins — and then
        | immediately enters that link's tenant. Every read after that is scoped normally.
        |
        | The flag is opt-in per table precisely so this is a deliberate, greppable act
        | rather than a blanket exemption (ADR 0002 amendment). `price_list_views` below does
        | NOT get it: views are written after the context has been entered.
        */
        $this->enableRls('price_list_links', allowPlatform: true);

        /*
        | The lookup index is GLOBAL, not per tenant, for the same reason: the query that
        | uses it runs before any tenant is known, and this is what makes it one row.
        */
        DB::statement('create unique index price_list_links_lookup_unique on price_list_links (lookup)');

        Schema::create('price_list_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_link_id')->constrained()->cascadeOnDelete();

            // `inet` rather than a string: it is the right type, it is smaller, and it makes
            // "everything from this subnet" answerable if a shop ever asks.
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestampTz('viewed_at');

            $table->index(['tenant_id', 'price_list_link_id'], 'price_list_views_tenant_link_idx');
        });

        $this->enableRls('price_list_views');
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_views');
        Schema::dropIfExists('price_list_links');
        Schema::dropIfExists('storefront_settings');
    }
};
