<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the derived `candidate` role.
 *
 * Accounts approved as candidates before the role existed are still plain
 * `student`. Promote every account that holds an approved, non-archived
 * candidacy so the role reflects reality from day one (App\Support\CandidateRoleSync
 * keeps it in step thereafter).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('candidates') || ! Schema::hasTable('users')) {
            return;
        }

        $query = DB::table('candidates')
            ->where('approval_status', 'approved')
            ->whereNotNull('user_id');

        if (Schema::hasColumn('candidates', 'archived_at')) {
            $query->whereNull('archived_at');
        }

        $userIds = $query->distinct()->pluck('user_id')->all();

        if ($userIds === []) {
            return;
        }

        // Only voter-role accounts are promoted; staff and the appointed SSG
        // President are left untouched.
        DB::table('users')
            ->whereIn('id', $userIds)
            ->where('role', 'student')
            ->update(['role' => 'candidate', 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        // Reverse only the accounts that have no live candidacy left — a
        // candidate promoted after this migration ran keeps their role.
        $live = collect();
        if (Schema::hasTable('candidates')) {
            $query = DB::table('candidates')
                ->where('approval_status', 'approved')
                ->whereNotNull('user_id');

            if (Schema::hasColumn('candidates', 'archived_at')) {
                $query->whereNull('archived_at');
            }

            $live = $query->distinct()->pluck('user_id');
        }

        DB::table('users')
            ->where('role', 'candidate')
            ->whereNotIn('id', $live->all())
            ->update(['role' => 'student', 'updated_at' => now()]);
    }
};
