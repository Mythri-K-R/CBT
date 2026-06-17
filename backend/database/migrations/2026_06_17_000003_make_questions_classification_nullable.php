<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->foreignId('subject_id')->nullable()->change();
            $table->foreignId('chapter_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->foreignId('subject_id')->nullable(false)->change();
            $table->foreignId('chapter_id')->nullable(false)->change();
        });
    }
};
