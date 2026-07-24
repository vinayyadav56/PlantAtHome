<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One admin-initiated "please share your location" email. The row is the
 * audit trail: who sent it, to whom, when it was opened/completed, and the
 * captured coordinates. Tokens are stored HASHED (sha256) — a DB leak never
 * exposes a live capture link.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('location_capture_requests')) {
            return;
        }

        Schema::create('location_capture_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();   // customer target
            $table->unsignedBigInteger('vendor_id')->nullable()->index(); // shop (vendor) target
            $table->string('email');
            $table->string('type', 16); // customer | vendor
            $table->string('token_hash', 64)->unique();                   // sha256 of the emailed token
            $table->string('status', 16)->default('pending')->index();    // pending | completed | expired | superseded

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy', 10, 2)->nullable();               // metres
            $table->string('formatted_address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('google_place_id')->nullable();

            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('opened_at')->nullable();                   // email/pixel or page view
            $table->timestamp('expires_at')->index();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_capture_requests');
    }
};
