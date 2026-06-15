<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->enum('plan', ['trial','starter','growth','enterprise']);
            $table->unsignedInteger('student_limit');
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->default('INR');
            $table->string('payment_reference')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['active','expired','cancelled','upgraded'])->default('active');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_history');
    }
};
