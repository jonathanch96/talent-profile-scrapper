<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector;');
        Schema::create('talent', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('name');
            $table->string('job_title')->nullable();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->string('location')->nullable();
            $table->string('timezone')->nullable();
            $table->string('talent_status')->nullable()->comment('Open To Work, Not Open To Work, Not Available');
            $table->string('availability')->nullable()->comment('Full Time, Part Time, Freelance');
            $table->string('website_url')->nullable();
            $table->vector('vectordb', 1536)->nullable()->comment('OpenAI embedding vector');
            $table->enum('scraping_status', ['idle', 'scraping_portfolio', 'processing_with_llm', 'completed', 'failed'])
                ->default('idle')
                ->comment('Portfolio scraping and processing status');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS vector;');
        Schema::dropIfExists('talent');
    }
};
