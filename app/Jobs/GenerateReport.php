<?php

namespace App\Jobs;

use App\Models\Student;
use App\Models\Test;
use App\Models\TestAttempt;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class GenerateReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(
        public readonly string $type,
        public readonly int    $entityId,
        public readonly int    $userId,
        public readonly array  $options = []
    ) {}

    public function handle(): void
    {
        $jobId    = $this->options['job_id'] ?? uniqid();
        $format   = $this->options['format'] ?? 'pdf';

        if ($this->type === 'test') {
            $this->generateTestReport($jobId, $format);
        } elseif ($this->type === 'student') {
            $this->generateStudentReport($jobId, $format);
        }
    }

    private function generateTestReport(string $jobId, string $format): void
    {
        $test = Test::with(['testQuestions.question', 'sections'])
                    ->withCount('completedAttempts')
                    ->find($this->entityId);

        $attempts = TestAttempt::where('test_id', $this->entityId)
            ->whereIn('status', ['submitted','completed'])
            ->with('student:id,name,roll_number')
            ->orderByDesc('total_score')
            ->get();

        $pdf = Pdf::loadView('reports.test', compact('test', 'attempts'));
        Storage::put("reports/{$jobId}.pdf", $pdf->output());
    }

    private function generateStudentReport(string $jobId, string $format): void
    {
        $student  = Student::with('subjectStats.subject', 'chapterStats.chapter')->find($this->entityId);
        $attempts = TestAttempt::where('student_id', $this->entityId)
            ->whereIn('status', ['submitted','completed'])
            ->with('test:id,title')
            ->latest()
            ->get();

        $pdf = Pdf::loadView('reports.student', compact('student', 'attempts'));
        Storage::put("reports/{$jobId}.pdf", $pdf->output());
    }
}
