<?php

namespace App\Listeners;

use App\Models\AuditLog;
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
        } elseif (property_exists($event, 'student')) {
            $entityType = 'student';
            $entityId   = $event->student->id;
        }

        AuditLog::create([
            'action'      => strtolower($action),
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'ip_address'  => Request::ip(),
            'user_agent'  => Request::userAgent(),
        ]);
    }
}
