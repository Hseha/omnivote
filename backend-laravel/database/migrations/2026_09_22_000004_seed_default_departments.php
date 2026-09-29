<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Legacy (retired) config key. It once held a plain list of department
     * codes, but nothing reads it — the `departments`/`courses` tables (and
     * their aliases) are the source of truth now, and the import guard
     * deliberately never writes it. The key is removed by
     * `2026_09_30_000001_remove_legacy_department_list_setting`.
     */
    public function up(): void
    {
        // No-op: superseded by the catalog tables.
    }

    public function down(): void
    {
        // Nothing to undo.
    }
};