<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_faculty', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('faculty_id')->references('id')->on('users')->cascadeOnDelete();

            $table->unique(['batch_id', 'faculty_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_faculty');
    }
};
