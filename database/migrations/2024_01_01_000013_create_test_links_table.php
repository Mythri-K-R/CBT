<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_id')->constrained()->cascadeOnDelete();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 20)->unique();
            $table->string('access_code', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('expires_at')->nullable();
            $table->string('qr_code_path', 500)->nullable();
            // Analytics
            $table->unsignedInteger('total_clicks')->default(0);
            $table->unsignedInteger('total_starts')->default(0);
            $table->unsignedInteger('total_completions')->default(0);
            $table->timestamps();

            $table->index('slug');
            $table->index('test_id');
        });

        Schema::create('test_link_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained('test_links')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('referrer', 500)->nullable();
            $table->enum('action', ['clicked','identified','started','completed','expired','error']);
            $table->timestamp('created_at')->useCurrent();

            $table->index('link_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_link_clicks');
        Schema::dropIfExists('test_links');
    }
};
