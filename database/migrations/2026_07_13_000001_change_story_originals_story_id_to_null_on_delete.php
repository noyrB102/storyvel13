<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_originals', function (Blueprint $table) {
            $table->dropForeign(['story_id']);
            $table->foreignId('story_id')->nullable()->change();
            $table->foreign('story_id')->references('id')->on('stories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('story_originals', function (Blueprint $table) {
            $table->dropForeign(['story_id']);
            $table->foreignId('story_id')->nullable(false)->change();
            $table->foreign('story_id')->references('id')->on('stories')->cascadeOnDelete();
        });
    }
};
