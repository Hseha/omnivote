<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canonical party list. The school has exactly two parties (ASLE and
     * SVEA); candidates reference them via `candidates.party_name`, and the
     * student app renders this table as the Party step.
     */
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('parties')->insert([
            ['name' => 'ASLE', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'SVEA', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};