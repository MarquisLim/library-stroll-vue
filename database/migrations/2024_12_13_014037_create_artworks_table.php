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
        Schema::create('artworks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->enum('type',['image','video','gif'])->default('image');
            $table->boolean('is_published')->default(false);
            $table->boolean('allow_download')->default(true);
            $table->boolean('allow_comments')->default(true);
            $table->boolean('is_adult')->default(false);
            $table->boolean('has_ai')->default(false);
            $table->boolean('is_private')->default(false);
            $table->unsignedBigInteger('views_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artworks');
    }
};
