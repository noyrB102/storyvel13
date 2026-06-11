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
        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('prompt');
            $table->longText('content')->nullable();
            $table->string('status')->default('pending'); // pending, generating, completed, failed
            $table->string('cover_image_path')->nullable();
            $table->string('genre')->nullable();
            $table->json('attachments')->nullable(); // paths to uploaded docs/images
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stories');
    }
};
