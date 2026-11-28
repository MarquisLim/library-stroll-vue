<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_turns', function (Blueprint $table) {
            $table->text('reply_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assistant_turns', function (Blueprint $table) {
            $table->dropColumn('reply_code');
        });
    }
};
