<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('role', ['super_admin','institution_admin','faculty']);
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('username', 100);
            $table->string('password');
            $table->string('avatar_path', 500)->nullable();
            // Faculty permissions
            $table->boolean('can_create_questions')->default(false);
            $table->boolean('can_edit_questions')->default(false);
            $table->boolean('can_create_tests')->default(false);
            $table->boolean('can_schedule_tests')->default(false);
            $table->boolean('can_view_results')->default(false);
            $table->boolean('can_view_analytics')->default(false);
            $table->boolean('can_generate_links')->default(false);
            $table->boolean('can_manage_students')->default(false);
            // Faculty subjects
            $table->json('assigned_subjects')->nullable();
            // Status
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->boolean('force_password_change')->default(true);
            $table->json('settings')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['institution_id', 'username']);
            $table->index('role');
            $table->index('institution_id');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
