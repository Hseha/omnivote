<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ballot_tallies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('candidate_id');
            $table->string('ssg_office');
            $table->unsignedInteger('tally_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ballot_tallies');
    }
};