<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Support\Str;

class StudentService
{
    public function create(int $institutionId, array $data): Student
    {
        return Student::create([
            'institution_id' => $institutionId,
            'name'           => $data['name'],
            'roll_number'    => $data['roll_number'] ?? $this->generateRollNumber($institutionId),
            'phone'          => $data['phone'] ?? null,
            'parent_phone'   => $data['parent_phone'] ?? null,
            'is_active'      => true,
        ]);
    }

    private function generateRollNumber(int $institutionId): string
    {
        $lastStudent = Student::withoutGlobalScopes()
            ->where('institution_id', $institutionId)
            ->withTrashed()
            ->orderByDesc('id')
            ->first();

        $nextNum = $lastStudent ? ((int) substr($lastStudent->roll_number, -4)) + 1 : 1;
        $year    = now()->year;

        do {
            $roll = "STU-{$year}-".str_pad($nextNum, 4, '0', STR_PAD_LEFT);
            $nextNum++;
        } while (Student::withoutGlobalScopes()
            ->where('institution_id', $institutionId)
            ->where('roll_number', $roll)
            ->exists());

        return $roll;
    }
}
