<?php

namespace App\Services\TestLink;

use App\Models\Student;
use App\Models\TestLink;

class LinkAccessService
{
    public function validate(TestLink $link, Student $student): array
    {
        if (!$link->isValid()) {
            return ['valid' => false, 'reason' => 'link_inactive'];
        }

        $test = $link->test;

        if (!in_array($test->status, ['live', 'scheduled'])) {
            return ['valid' => false, 'reason' => 'test_not_active'];
        }

        if ($test->scheduled_start && now()->lt($test->scheduled_start)) {
            return ['valid' => false, 'reason' => 'test_not_started', 'starts_at' => $test->scheduled_start];
        }

        if ($test->scheduled_end && now()->gt($test->scheduled_end)) {
            return ['valid' => false, 'reason' => 'test_ended'];
        }

        // Check student belongs to institution
        if ($student->institution_id !== $link->institution_id) {
            return ['valid' => false, 'reason' => 'student_not_eligible'];
        }

        return ['valid' => true];
    }
}
