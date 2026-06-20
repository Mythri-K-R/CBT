<?php

namespace App\Listeners;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

class LogAuditTrail
{
    public function handle(object $event): void
    {
        $action   = class_basename($event);
        $entityId = null;
        $entityType = null;

        if (property_exists($event, 'attempt')) {
            $entityType = 'test_attempt';
            $entityId   = $event->attempt->id;
        } elseif (property_exists($event, 'import')) {
            $entityType = 'question_import';
            $entityId   = $event->import->id;
        } elseif (property_exists($event, 'user')) {
            $entityType = 'user';
            $entityId   = $event->user->id;
        } elseif (property_exists($event, 'student')) {
            $entityType = 'student';
            $entityId   = $event->student->id;
        }

        // Audit log writes must never propagate exceptions. LogAuditTrail fires
        // synchronously on ExamSubmitted — a DB failure here would return a 500
        // to the student even though their exam was already committed to the DB.
        try {
            AuditLog::create([
                'action'      => strtolower($action),
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'ip_address'  => Request::ip(),
                'user_agent'  => Request::userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::error('LogAuditTrail: failed to write audit log — gap in audit trail', [
                'event'       => class_basename($event),
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
