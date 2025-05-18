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
        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()
            ->cascadeOnDelete();
            $table->foreignId('complaint_type_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->morphs('complaintable');

            $table->text('details')->nullable();

            $table->enum('status', ['pending','approved','rejected'])
                ->default('pending');

            $table->text('moderator_note')
                ->nullable();

            $table->foreignId('moderator_id')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};
