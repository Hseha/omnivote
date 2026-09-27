<?php

use App\Models\RegistrarImport;
use App\Models\User;
use App\Support\DepartmentCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('course')->nullable()->after('department');
        });

        // Map the legacy short codes already stored on accounts and feed rows
        // (CCS/BSOA) onto their canonical college names so User Management,
        // filters, and the import guard all read one consistent structure.
        $this->normalizeDepartmentCodes(User::class);
        $this->normalizeDepartmentCodes(RegistrarImport::class);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('course');
        });
    }

    /** Rewrite legacy department codes (e.g. "CCS") to canonical college names. */
    private function normalizeDepartmentCodes(string $model): void
    {
        $distinct = $model::query()
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->distinct()
            ->pluck('department');

        foreach ($distinct as $legacy) {
            $canonical = DepartmentCatalog::resolveCollege($legacy);
            if ($canonical !== null && $canonical !== $legacy) {
                $model::where('department', $legacy)->update(['department' => $canonical]);
            }
        }
    }
};