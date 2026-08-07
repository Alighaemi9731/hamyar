<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff invitations.
 *
 * A shop owner adds staff by inviting a mobile number, not by typing a password on
 * their behalf — so nobody but the new user ever knows their credentials.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->string('mobile');
            $table->string('email')->nullable();
            $table->string('role');

            // Only the hash is stored. A leaked database must not yield working
            // invitation links, exactly as with password reset tokens.
            $table->string('token_hash', 64)->unique();

            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'accepted_at']);
            $table->unique(['tenant_id', 'mobile', 'accepted_at']);
        });

        $this->enableRls('invitations');
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
