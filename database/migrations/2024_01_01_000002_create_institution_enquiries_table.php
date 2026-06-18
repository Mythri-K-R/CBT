<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('institution_name');
            $table->string('contact_person');
            $table->string('email');
            $table->string('phone', 20);
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->unsignedInteger('student_count')->nullable();
            $table->string('institution_type', 100)->nullable();
            $table->text('message')->nullable();
            $table->enum('status', ['new_lead','contacted','demo_scheduled','demo_done','converted','rejected'])->default('new_lead');
            $table->text('notes')->nullable();
            $table->foreignId('converted_institution_id')->nullable()->constrained('institutions');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_enquiries');
    }
};
