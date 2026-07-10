<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side refresh tokens. Only the SHA-256 hash of the opaque token is
 * stored (never the token itself), so a DB leak can't reissue sessions. Tokens
 * rotate on every refresh — the old row is revoked and points at its successor,
 * giving an audit trail and reuse detection.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('identity_refresh_tokens')) {
            return;
        }

        Schema::create('identity_refresh_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('replaced_by')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('identity_users')->cascadeOnDelete();
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_refresh_tokens');
    }
};
