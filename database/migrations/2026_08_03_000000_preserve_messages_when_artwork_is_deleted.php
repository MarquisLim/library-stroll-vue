<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['artwork_id']);
            $table->foreign('artwork_id')
                ->references('id')
                ->on('artworks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['artwork_id']);
            $table->foreign('artwork_id')
                ->references('id')
                ->on('artworks')
                ->cascadeOnDelete();
        });
    }
};
