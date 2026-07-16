<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_previous_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('author_name')->nullable();
            $table->string('genre')->nullable();
            $table->longText('content')->nullable();
            $table->boolean('is_private')->default(true);
            $table->boolean('is_edited')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_previous_versions');
    }
};
