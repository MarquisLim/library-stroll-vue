<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_processing_runs', function (Blueprint $table) {
            $table->index('request_id');
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_processing_runs', function (Blueprint $table) {
            $table->dropIndex(['request_id']);
            $table->dropIndex(['status', 'started_at']);
        });
    }
};
