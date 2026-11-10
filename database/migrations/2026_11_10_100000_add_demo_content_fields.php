<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('is_demo')->default(false)->index());
        Schema::table('artworks', function (Blueprint $t) {
            $t->boolean('is_demo')->default(false)->index();
            $t->boolean('ai_indexing_enabled')->default(true)->index();
        });
        Schema::table('artwork_imports', function (Blueprint $t) {
            foreach (['creator_name', 'creator_url', 'license_url', 'original_date', 'original_medium', 'primary_category'] as $field) {
                $t->text($field)->nullable();
            }
            $t->text('attribution_text')->nullable();
            $t->text('original_description')->nullable();
            $t->string('media_sha256', 64)->nullable()->index();
            $t->boolean('is_demo')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('artwork_imports', fn (Blueprint $t) => $t->dropColumn(['creator_name', 'creator_url', 'license_url', 'original_date', 'original_medium', 'primary_category', 'attribution_text', 'original_description', 'media_sha256', 'is_demo']));
        Schema::table('artworks', fn (Blueprint $t) => $t->dropColumn(['is_demo', 'ai_indexing_enabled']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_demo'));
    }
};
