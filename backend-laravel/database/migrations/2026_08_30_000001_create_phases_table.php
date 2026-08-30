<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phases', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // insert default phases
        DB::table('phases')->insert([
            ['name' => 'registration', 'description' => 'Registration phase', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'voting_open', 'description' => 'Voting is open', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'voting_closed', 'description' => 'Voting closed', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('phases');
    }
};
