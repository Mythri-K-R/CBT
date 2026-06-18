<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('exam_type', ['neet','jee_main','jee_advanced','kcet','general']);
            $table->enum('batch_type', ['regular','crash_course','dropper','repeater','weekend','online','combined'])->default('regular');
            $table->unsignedSmallInteger('target_year')->nullable();
            $table->date('start_date')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('max_students')->nullable();
            $table->boolean('is_active')->default(true);
            $table->enum('status', ['active','completed','archived'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('institution_id');
            $table->index('status');
            $table->index('exam_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
