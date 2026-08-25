<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            Schema::create('candidates', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->enum('ssg_office', ['President', 'Vice_President', 'Secretary', 'Treasurer', 'Auditor']);
                $table->longText('platform_statement');
                $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('pending');
                $table->timestamps();
            });
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            Schema::dropIfExists('candidates');
        });
    }
};
