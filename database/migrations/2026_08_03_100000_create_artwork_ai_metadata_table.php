<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artwork_ai_metadata', function (Blueprint $table) {
            $table->id();

            $table->foreignId('artwork_id')
                ->unique()
                ->constrained('artworks')
                ->cascadeOnDelete();

            $table->string('status', 20)->default('pending');

            $table->uuid('qdrant_point_id')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->uuid('source_media_uuid')->nullable();

            $table->text('summary')->nullable();
            $table->json('objects')->nullable();
            $table->json('themes')->nullable();
            $table->json('styles')->nullable();
            $table->json('mood')->nullable();
            $table->json('colors')->nullable();
            $table->json('tags_ru')->nullable();
            $table->json('tags_en')->nullable();

            $table->decimal('confidence', 4, 3)->nullable();

            $table->string('chat_model')->nullable();
            $table->string('vision_model')->nullable();
            $table->string('embedding_model')->nullable();
            $table->unsignedInteger('embedding_dimension')->nullable();

            $table->unsignedInteger('metadata_schema_version')->default(1);
            $table->unsignedInteger('prompt_version')->default(1);

            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artwork_ai_metadata');
    }
};
