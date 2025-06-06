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
        Schema::create('talent_scraping_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('talent_id')->constrained('talent')->onDelete('cascade');
            $table->string('website_url');
            $table->string('scraped_data_path')->nullable()->comment('Path to scraped JSON/SPA data file');
            $table->string('processed_data_path')->nullable()->comment('Path to LLM processed data file');
            $table->enum('status', ['pending', 'scraping', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable()->comment('Additional scraping metadata');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['talent_id', 'status']);
            $table->index('website_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('talent_scraping_results');
    }
};
