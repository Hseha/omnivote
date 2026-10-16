<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time, expiring password-setup tokens handed to a newly granted SSG
     * President instead of transmitting a credential.
     *
     * The plaintext token is generated with a CSPRNG, mailed to no one, and
     * shown only inside the app via the notification link; only its SHA-256
     * hash is persisted here. A token is redeemed at most once (`used_at`) and
     * carries a hard expiry (`expires_at`) so an intercepted invite cannot be
     * replayed. `issued_by` records the acting administrator for the audit
     * trail.
     */
    public function up(): void
    {
        Schema::create('ssg_president_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssg_president_invites');
    }
};
