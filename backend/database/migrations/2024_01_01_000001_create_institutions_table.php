<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('type', ['pu_college','degree_college','neet_academy','jee_academy','kcet_institute','coaching_center','school','other']);
            $table->string('contact_person');
            $table->string('email');
            $table->string('phone', 20);
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('logo_path', 500)->nullable();
            $table->string('website', 500)->nullable();
            // Subscription
            $table->enum('plan', ['trial','starter','growth','enterprise'])->default('trial');
            $table->unsignedInteger('student_limit')->default(50);
            $table->unsignedInteger('faculty_limit')->default(3);
            $table->unsignedInteger('question_limit')->default(5000);
            $table->date('subscription_start')->nullable();
            $table->date('subscription_end')->nullable();
            $table->boolean('is_active')->default(true);
            // Settings
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('code');
            $table->index('plan');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institutions');
    }
};
