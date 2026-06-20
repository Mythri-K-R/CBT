<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recovery audit log — Feature 7
 *
 * Records all circuit breaker state transitions and session recovery events.
 * This is a lightweight append-only table; reads are infrequent (dashboard only).
 *
 * Retention: see config('resilience.dlq.log_retention_days').
 * Purged by the scheduled maintenance command (if configured).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_recovery_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_type', 50);   // circuit_open, circuit_closed, session_recovery
            $table->string('service', 30);       // redis, mysql, queue
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            // No updated_at — this table is append-only.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_recovery_log');
    }
};
