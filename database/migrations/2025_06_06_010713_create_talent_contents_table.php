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
        Schema::create('talent_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('talent_id')->constrained('talent');
            $table->foreignId('content_type_id')->constrained('content_types');
            $table->foreignId('content_type_value_id')->constrained('content_type_values');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('talent_contents');
    }
};
