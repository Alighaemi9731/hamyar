<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Password resets and sessions.
 *
 * Both were in Laravel's default users migration; both need tenant scoping here.
 *
 * `password_reset_tokens` is keyed by email in stock Laravel. That breaks under
 * multi-tenancy: the same address can exist in two shops, so a token issued for one
 * would reset the other's password. The key is (tenant_id, identifier), and the
 * identifier is the mobile number people actually log in with.
 *
 * Sessions use the **database** driver rather than Redis, even though Redis backs
 * cache and queues. The reason is the session-management screen in Phase 1.4 — a shop
 * owner listing and revoking active logins needs sessions to be enumerable per user,
 * which the Redis driver cannot do without maintaining a parallel index.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Named `identifier`, not `email`: staff log in with a MOBILE number here.
            // Keeping Laravel's column name would make every reader assume otherwise.
            $table->string('identifier');
            // Only the hash is stored, so a database leak yields no working links.
            $table->string('token');
            $table->timestamp('created_at')->nullable();

            $table->primary(['tenant_id', 'identifier']);
        });

        $this->enableRls('password_reset_tokens');

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();

            // Nullable: a guest session exists before anyone logs in, and the central
            // (no-tenant) routes have sessions too. RLS is therefore NOT applied to
            // this table — a row with a null tenant_id would be invisible to its own
            // owner, which would break login before it began. Sessions are protected
            // by the unguessable session id, as in stock Laravel.
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->index();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();

            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
    }
};
