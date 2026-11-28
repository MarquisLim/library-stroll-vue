<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_turns', function (Blueprint $table) {
            $table->longText('diagnostics')->nullable();
            $table->json('fallback_source_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assistant_turns', function (Blueprint $table) {
            $table->dropColumn(['diagnostics', 'fallback_source_ids']);
        });
    }
};
