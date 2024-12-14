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
        Schema::create('artwork_collection', function (Blueprint $table) {
            $table->foreignId('artwork_id')->constrained()->onDelete('cascade');
            $table->foreignId('collection_id')->constrained()->onDelete('cascade');
            $table->primary(['artwork_id','collection_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artwork_collection');
    }
};
