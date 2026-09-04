<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the canonical `positions` table (both tiers) that the candidate
     * application validator (`exists:positions,id`), the ballot engine
     * (`position_key` = positions.slug) and the Flutter client
     * (`GET /api/positions`) all depend on.
     */
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();                 // wire key used in ballot selections
            $table->string('label');                          // display name
            $table->enum('tier', ['school', 'provincial'])->default('school');
            $table->unsignedTinyInteger('seat_count')->default(1);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // The fixed position list from docs/01_PROJECT_OVERVIEW.md.
        $positions = [
            // School tier
            ['president', 'President', 'school', 1, 10],
            ['vice_president', 'Vice President', 'school', 1, 20],
            ['secretary', 'Secretary', 'school', 1, 30],
            ['treasurer', 'Treasurer', 'school', 1, 40],
            ['auditor', 'Auditor', 'school', 1, 50],
            ['press_officer', 'Press Officer', 'school', 1, 60],
            ['senator', 'Senator', 'school', 12, 70],
            ['year_level_representative', 'Year Level Representative', 'school', 1, 80],
            ['property_custodian', 'Property Custodian', 'school', 1, 90],
            // Provincial tier
            ['governor', 'Governor', 'provincial', 1, 10],
            ['vice_governor', 'Vice Governor', 'provincial', 1, 20],
            ['provincial_secretary', 'Provincial Secretary', 'provincial', 1, 30],
            ['provincial_treasurer', 'Provincial Treasurer', 'provincial', 1, 40],
            ['provincial_auditor', 'Provincial Auditor', 'provincial', 1, 50],
            ['provincial_press_officer', 'Provincial Press Officer', 'provincial', 1, 60],
            ['provincial_custodian', 'Provincial Custodian', 'provincial', 1, 70],
        ];

        $now = now();
        foreach ($positions as [$slug, $label, $tier, $seats, $sort]) {
            DB::table('positions')->insert([
                'slug' => $slug,
                'label' => $label,
                'tier' => $tier,
                'seat_count' => $seats,
                'sort_order' => $sort,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};