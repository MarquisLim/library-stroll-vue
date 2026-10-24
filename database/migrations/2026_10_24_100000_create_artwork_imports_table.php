<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artwork_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artwork_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('source', 20);
            $table->string('external_id', 80);
            $table->text('source_url');
            $table->text('image_url');
            $table->string('license', 80);
            $table->string('image_sha256', 64);
            $table->json('metadata');
            $table->timestamps();
            $table->unique(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artwork_imports');
    }
};
