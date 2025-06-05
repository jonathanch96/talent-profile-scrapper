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
        Schema::create('talent', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('job_title');
            $table->text('description');
            $table->string('image');
            $table->string('location');
            $table->string('timezone');
            $table->string('status');
            $table->json('projects')->nullable();
            $table->json('experiences')->nullable();
            $table->json('availability')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('talent');
    }
};
