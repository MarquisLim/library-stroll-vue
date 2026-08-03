<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_processing_runs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('artwork_id')->nullable();
            $table->foreign('artwork_id')
                ->references('id')
                ->on('artworks')
                ->nullOnDelete();

            $table->string('operation', 50);
            $table->string('status', 20);
            $table->uuid('request_id')->nullable();
            $table->string('model')->nullable();
            $table->json('parameters')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['artwork_id', 'operation']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_processing_runs');
    }
};
