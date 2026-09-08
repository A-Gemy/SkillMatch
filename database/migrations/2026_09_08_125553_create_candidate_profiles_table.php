<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('candidate_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->text('bio')->nullable();

            $table->string('current_job_title', 150)->nullable();
            $table->unsignedTinyInteger('years_of_experience')->nullable();

            $table->string('city', 100)->nullable();
            $table->string('country', 100)->nullable();

            $table->string('profile_photo_path')->nullable();

            $table->string('linkedin_url', 2048)->nullable();
            $table->string('github_url', 2048)->nullable();
            $table->string('portfolio_url', 2048)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidate_profiles');
    }
};
