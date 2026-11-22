<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artwork_colbert_expectations', function (Blueprint $table) {
            // Keep the deletion record after the artwork and its Dense metadata are gone.
            $table->unsignedBigInteger('artwork_id')->primary();
            $table->string('state', 10);
            $table->string('revision_hash', 64);
            $table->unsignedInteger('dense_attempt')->nullable();
            $table->longText('searchable_text')->nullable();
            $table->string('text_sha256', 64)->nullable();
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artwork_colbert_expectations');
    }
};
