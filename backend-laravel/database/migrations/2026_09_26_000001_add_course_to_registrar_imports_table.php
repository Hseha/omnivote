<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrar_imports', function (Blueprint $table) {
            $table->string('course')->nullable()->after('department');
        });
    }

    public function down(): void
    {
        Schema::table('registrar_imports', function (Blueprint $table) {
            $table->dropColumn('course');
        });
    }
};