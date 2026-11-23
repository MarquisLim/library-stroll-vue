<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'updated_at', 'id'], 'assistant_conversations_owner_activity_index');
        });

        Schema::create('assistant_turns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->text('user_query');
            $table->string('result_status', 32);
            $table->unsignedTinyInteger('requested_limit');
            $table->json('source_references');
            $table->timestamps();

            $table->unique(['conversation_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_turns');
        Schema::dropIfExists('assistant_conversations');
    }
};
