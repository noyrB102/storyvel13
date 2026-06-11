<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_messages', function (Blueprint $table) {
            $table->timestamp('accepted_at')->nullable()->after('content');
            $table->timestamp('declined_at')->nullable()->after('accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('story_messages', function (Blueprint $table) {
            $table->dropColumn(['accepted_at', 'declined_at']);
        });
    }
};
