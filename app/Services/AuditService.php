<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditService
{
    public function log(string $action, ?string $entityType = null, ?int $entityId = null, array $context = []): void
    {
        $user = Auth::user();

        AuditLog::create([
            'institution_id' => $user?->institution_id,
            'user_id'        => $user?->id,
            'action'         => $action,
            'entity_type'    => $entityType,
            'entity_id'      => $entityId,
            'old_values'     => $context['old'] ?? null,
            'new_values'     => $context['new'] ?? null,
            'ip_address'     => Request::ip(),
            'user_agent'     => Request::userAgent(),
        ]);
    }
}
