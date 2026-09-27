<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds registrar-issued one-time codes to the eligibility feed.
 *
 * Backs two fixes from the security assessment:
 *
 *   M-5 — self-registration could pre-claim a registrar identity, because a
 *         `student_id` present in this table was the only proof required. The
 *         new `activation_code_hash` means the registrar must also have handed
 *         the student a secret out of band.
 *
 *   M-3 — students have no mailbox (their login handle is a name-derived slug),
 *         so a locked-out voter had no self-service recovery and needed a manual
 *         admin unlock. The same code is redeemable as a password-reset code.
 *
 * Only the bcrypt HASH is stored; the plaintext is returned once at issue time
 * (see App\Support\RegistrarCode).
 *
 * Nullable throughout so an existing deployment keeps working before the
 * registrar re-issues codes — absence simply means "no self-service yet".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrar_imports', function (Blueprint $table) {
            $table->string('activation_code_hash')->nullable()->after('review_reason');
            $table->timestamp('activation_code_issued_at')->nullable()->after('activation_code_hash');
            $table->timestamp('activation_code_used_at')->nullable()->after('activation_code_issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('registrar_imports', function (Blueprint $table) {
            $table->dropColumn([
                'activation_code_hash',
                'activation_code_issued_at',
                'activation_code_used_at',
            ]);
        });
    }
};
