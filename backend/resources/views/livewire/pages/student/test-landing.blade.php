<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\TestLink;
use App\Models\Test;
use App\Models\Student;
use App\Models\TestAttempt;
use App\Models\TestResponse;
use App\Models\TestTimerState;
use Illuminate\Support\Str;

new #[Layout('layouts.student')] #[Title('Test Verification')] class extends Component {
    public $slug;
    public $roll_number = '';
    
    public ?TestLink $testLink = null;
    public ?Test $test = null;
    public ?string $errorMessage = null;

    public function mount($slug)
    {
        $this->slug = $slug;
        $this->testLink = TestLink::where('slug', $slug)->firstOrFail();
        $this->test = $this->testLink->test;

        if (!$this->testLink->isValid() || !$this->test->isAccessible()) {
            abort(404, 'This test is no longer available or has not started yet.');
        }
    }

    public function verifyAndStart()
    {
        $this->validate([
            'roll_number' => 'required|string',
        ]);

        $this->errorMessage = null;

        // Find student
        $student = Student::where('roll_number', $this->roll_number)
                          ->where('institution_id', $this->test->institution_id)
                          ->first();

        if (!$student) {
            $this->errorMessage = 'Invalid Roll Number.';
            return;
        }

        // Verify if student is in a batch assigned to this test
        $isAssigned = $this->test->batches()
            ->whereHas('students', function ($query) use ($student) {
                $query->where('student_id', $student->id);
            })->exists();

        if (!$isAssigned) {
            $this->errorMessage = 'You are not assigned to this test.';
            return;
        }

        // Check if attempt already exists
        $attempt = TestAttempt::where('test_id', $this->test->id)
                              ->where('student_id', $student->id)
                              ->first();

        if ($attempt) {
            if ($attempt->status === 'submitted' || $attempt->status === 'completed') {
                $this->errorMessage = 'You have already submitted this test.';
                return;
            }
            // Resume test
            return redirect()->route('student.test.attempt', ['uuid' => $attempt->id]);
        }

        // Create new attempt
        $serverEndTime = now()->addMinutes($this->test->duration_minutes);
        
        $attempt = TestAttempt::create([
            'test_id' => $this->test->id,
            'student_id' => $student->id,
            'link_id' => $this->testLink->id,
            'started_at' => now(),
            'server_end_time' => $serverEndTime,
            'status' => 'in_progress',
        ]);

        // Initialize Timer State
        TestTimerState::create([
            'attempt_id' => $attempt->id,
            'remaining_seconds' => $this->test->duration_minutes * 60,
        ]);

        // Initialize Test Responses for all questions
        $testQuestions = $this->test->testQuestions()->with('question')->get();
        $responses = [];
        
        foreach ($testQuestions as $tq) {
            $responses[] = [
                'attempt_id' => $attempt->id,
                'test_question_id' => $tq->id,
                'question_id' => $tq->question_id,
                'section_id' => $tq->section_id,
                'status' => 'not_visited',
            ];
        }
        
        TestResponse::insert($responses);

        return redirect()->route('student.test.attempt', ['uuid' => $attempt->id]);
    }
}; ?>

<div class="min-h-screen bg-background flex flex-col items-center justify-center p-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold font-outfit text-primary tracking-tight">ExamSphere</h1>
            <p class="text-muted-foreground mt-2">CBT Test Portal</p>
        </div>

        <div class="bg-card border border-border rounded-xl shadow-sm p-6">
            <div class="mb-6 pb-6 border-b border-border">
                <h2 class="text-xl font-semibold">{{ $test->title }}</h2>
                <div class="mt-4 flex flex-col gap-2 text-sm text-muted-foreground">
                    <div class="flex items-center gap-2">
                        <x-lucide-clock class="w-4 h-4" />
                        <span>Duration: {{ $test->duration_minutes }} minutes</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <x-lucide-file-question class="w-4 h-4" />
                        <span>Exam Type: {{ strtoupper($test->exam_type) }}</span>
                    </div>
                </div>
            </div>

            <form wire:submit="verifyAndStart" class="space-y-4">
                <div>
                    <label for="roll_number" class="block text-sm font-medium mb-1">Enter Roll Number</label>
                    <input 
                        wire:model="roll_number" 
                        type="text" 
                        id="roll_number" 
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                        placeholder="e.g. STU2026001"
                        required
                        autofocus
                    >
                    @if($errorMessage)
                        <p class="text-red-500 text-sm mt-2">{{ $errorMessage }}</p>
                    @endif
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-primary text-primary-foreground hover:bg-primary/90 h-10 px-4 py-2">
                        Start Test
                    </button>
                </div>
            </form>
            
            <div class="mt-6 text-xs text-muted-foreground bg-muted/50 p-3 rounded-md">
                <p class="font-medium mb-1 text-foreground">Instructions:</p>
                <ul class="list-disc pl-4 space-y-1">
                    <li>Ensure you have a stable internet connection.</li>
                    <li>Do not refresh the page or switch tabs during the test.</li>
                    <li>The test will auto-submit when the timer runs out.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
