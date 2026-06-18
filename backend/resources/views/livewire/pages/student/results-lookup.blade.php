<?php

use App\Models\Student;
use App\Models\TestAttempt;
use App\Models\Test;
use Livewire\Volt\Component;

new class extends Component {
    public string $rollNumber = '';
    public string $phone = '';
    public string $testCode = '';
    public bool $searched = false;
    public ?Student $student = null;
    public $attempts = null;
    public ?string $error = null;

    public function search(): void
    {
        $this->validate([
            'rollNumber' => 'required|string|max:50',
            'phone'      => 'nullable|string|max:15',
        ]);

        $this->error = null;
        $this->searched = true;

        $query = Student::query()
            ->where('roll_number', $this->rollNumber);

        if ($this->phone) {
            $query->where('phone', $this->phone);
        }

        $this->student = $query->first();

        if (!$this->student) {
            $this->error = 'No student found with that roll number' . ($this->phone ? ' and phone number' : '') . '.';
            return;
        }

        $this->attempts = TestAttempt::query()
            ->with('test')
            ->where('student_id', $this->student->id)
            ->where('status', 'submitted')
            ->when($this->testCode, function ($q) {
                $q->whereHas('test', fn($q2) => $q2->where('code', $this->testCode));
            })
            ->orderByDesc('submitted_at')
            ->get();
    }

    public function reset_search(): void
    {
        $this->reset(['rollNumber', 'phone', 'testCode', 'searched', 'student', 'attempts', 'error']);
    }
}; ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Results Lookup — Examsphere</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-background text-foreground min-h-screen flex flex-col">

<header class="border-b border-border bg-card/80 backdrop-blur px-4 py-4">
    <div class="max-w-4xl mx-auto flex items-center justify-between">
        <a href="/" class="flex items-center gap-2 font-display font-bold text-xl text-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20"/><path d="m17 5-5-3-5 3"/><path d="m17 19-5 3-5-3"/><path d="M2 12h20"/><path d="m5 17-3-5 3-5"/><path d="m19 17 3-5-3-5"/></svg>
            Examsphere
        </a>
        <span class="text-sm text-muted-foreground">Results Portal</span>
    </div>
</header>

<main class="flex-1 flex flex-col items-center py-12 px-4">
    <div class="w-full max-w-xl">

        @if(!$searched || $error)
        <div class="text-center mb-8">
            <h1 class="font-display text-3xl font-bold mb-2">Check Your Results</h1>
            <p class="text-muted-foreground">Enter your roll number to view all your test results</p>
        </div>

        <div class="bg-card border border-border rounded-2xl shadow-sm p-6">
            <form wire:submit="search" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5">Roll Number <span class="text-destructive">*</span></label>
                    <input wire:model="rollNumber" type="text" placeholder="e.g. STU2026001" autofocus class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary @error('rollNumber') border-destructive @enderror">
                    @error('rollNumber')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1.5">Phone Number (optional)</label>
                    <input wire:model="phone" type="tel" placeholder="For verification" class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                    <p class="text-xs text-muted-foreground mt-1">Helps narrow results if roll number is shared</p>
                </div>

                @if($error)
                <div class="rounded-lg bg-destructive/10 border border-destructive/20 px-4 py-3 text-sm text-destructive">
                    {{ $error }}
                </div>
                @endif

                <button type="submit" class="w-full rounded-lg bg-primary py-2.5 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">
                    Find My Results
                </button>
            </form>
        </div>
        @else

        <!-- Results view -->
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h2 class="font-display text-xl font-bold">{{ $student->name }}</h2>
                <p class="text-sm text-muted-foreground mt-0.5">Roll: {{ $student->roll_number }}{{ $student->batch ? ' · '.$student->batch->name : '' }}</p>
            </div>
            <button wire:click="reset_search" class="text-sm text-primary hover:underline">Search again</button>
        </div>

        @if($attempts->isEmpty())
        <div class="text-center py-16 rounded-2xl border border-dashed border-border bg-card">
            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="mx-auto mb-3 text-muted-foreground/40"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
            <p class="font-medium mb-1">No results yet</p>
            <p class="text-sm text-muted-foreground">No submitted tests found for this roll number</p>
        </div>
        @else
        <div class="space-y-4">
            @foreach($attempts as $attempt)
            @php
                $score = $attempt->total_score ?? 0;
                $max = $attempt->test->total_marks ?? 0;
                $pct = $max > 0 ? round($score / $max * 100) : 0;
                $correct = $attempt->total_correct ?? 0;
                $wrong = $attempt->total_wrong ?? 0;
                $unattempted = ($attempt->total_questions ?? 0) - $correct - $wrong;
            @endphp
            <div class="bg-card border border-border rounded-2xl p-5 hover:border-primary/30 transition-colors">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="font-semibold">{{ $attempt->test->title }}</h3>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ strtoupper($attempt->test->exam_type ?? '') }}
                            @if($attempt->submitted_at)
                             · {{ \Carbon\Carbon::parse($attempt->submitted_at)->format('d M Y') }}
                            @endif
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-2xl font-bold">{{ $score }}</p>
                        <p class="text-xs text-muted-foreground">of {{ $max }}</p>
                    </div>
                </div>

                <div class="w-full bg-muted rounded-full h-1.5 mb-3">
                    <div class="h-1.5 rounded-full bg-primary transition-all" style="width:{{ $pct }}%"></div>
                </div>

                <div class="grid grid-cols-3 gap-2 text-center text-xs">
                    <div class="rounded-lg bg-green-50 py-2">
                        <p class="font-bold text-green-700 text-base">{{ $correct }}</p>
                        <p class="text-green-600">Correct</p>
                    </div>
                    <div class="rounded-lg bg-red-50 py-2">
                        <p class="font-bold text-red-700 text-base">{{ $wrong }}</p>
                        <p class="text-red-600">Wrong</p>
                    </div>
                    <div class="rounded-lg bg-gray-50 py-2">
                        <p class="font-bold text-gray-700 text-base">{{ max(0, $unattempted) }}</p>
                        <p class="text-gray-500">Skipped</p>
                    </div>
                </div>

                @if($attempt->rank_in_test)
                <div class="mt-3 pt-3 border-t border-border flex items-center justify-between text-xs text-muted-foreground">
                    <span>Rank: <strong class="text-foreground">#{{ $attempt->rank_in_test }}</strong></span>
                    @if($attempt->percentile)
                    <span>Percentile: <strong class="text-foreground">{{ number_format($attempt->percentile, 2) }}</strong></span>
                    @endif
                </div>
                @endif
            </div>
            @endforeach
        </div>
        @endif
        @endif
    </div>
</main>

<footer class="py-6 text-center text-xs text-muted-foreground border-t border-border">
    Examsphere · CBT Platform for Coaching Institutes
</footer>
</body>
</html>
