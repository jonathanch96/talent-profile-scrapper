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
        Schema::create('talent_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('talent_id')->constrained('talent')->onDelete('cascade');
            $table->foreignId('scraping_result_id')->nullable()->constrained('talent_scraping_results')->onDelete('cascade');
            $table->string('original_url', 1000)->comment('Original document URL');
            $table->string('source_link_text')->nullable()->comment('Text of the link that led to this document');
            $table->string('document_type')->comment('pdf, doc, docx, txt, etc.');
            $table->string('file_path')->nullable()->comment('Local file path');
            $table->string('filename')->comment('Original or generated filename');
            $table->bigInteger('file_size')->nullable()->comment('File size in bytes');
            $table->enum('download_status', ['pending', 'downloading', 'completed', 'failed'])->default('pending');
            $table->enum('extraction_status', ['pending', 'extracting', 'completed', 'failed'])->default('pending');
            $table->text('extracted_content')->nullable()->comment('Extracted text content');
            $table->json('metadata')->nullable()->comment('Document metadata (pages, author, etc.)');
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['talent_id', 'download_status']);
            $table->index(['document_type', 'extraction_status']);
            $table->index('original_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('talent_documents');
    }
};
