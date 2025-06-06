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
            $table->string('username')->unique();
            $table->string('name');
            $table->string('job_title');
            $table->text('description');
            $table->string('image');
            $table->string('location');
            $table->string('timezone');
            $table->string('talent_status')->comment('Open To Work, Not Open To Work, Not Available');
            $table->string('availability')->nullable()->comment('Full Time, Part Time, Freelance');

            $table->timestamps();
            $table->softDeletes();
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
