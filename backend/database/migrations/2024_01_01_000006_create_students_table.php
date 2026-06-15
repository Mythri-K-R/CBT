<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('roll_number', 50);
            $table->string('phone', 20)->nullable();
            $table->string('parent_phone', 20)->nullable();
            $table->string('photo_path', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['institution_id', 'roll_number']);
            $table->index('institution_id');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
