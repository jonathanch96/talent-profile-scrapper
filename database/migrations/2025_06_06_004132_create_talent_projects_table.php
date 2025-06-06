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
        Schema::create('talent_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('talent_id')->constrained('talent');
            $table->foreignId('experience_id')->constrained('talent_experiences')->nullable();
            $table->json('project_roles')->nullable();
            $table->string('title');
            $table->string('description')->nullable();
            $table->string('image')->nullable();
            $table->string('link')->nullable();
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('talent_projects');
    }
};
