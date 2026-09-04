<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Makes phases mutable at runtime (exactly one active phase) and adds the
     * `election_settings` key/value store that backs the admin Election Setup
     * screen (title, dates) and `GET /api/election/status`.
     */
    public function up(): void
    {
        Schema::table('phases', function (Blueprint $table) {
            $table->boolean('is_active')->default(false)->after('name');
        });

        // Default to the registration phase until an admin flips it.
        DB::table('phases')->update(['is_active' => false]);
        DB::table('phases')->where('name', 'registration')->update(['is_active' => true]);

        Schema::create('election_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        DB::table('election_settings')->insert([
            ['key' => 'title', 'value' => 'Student Council General Election', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'voting_opens_at', 'value' => null, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'voting_closes_at', 'value' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('election_settings');

        Schema::table('phases', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};