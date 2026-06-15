<?php

use App\Models\TestAttempt;
use Livewire\Volt\Component;

new class extends Component {
    public string $slug;
    public TestAttempt $attempt;

    public function mount(string $slug): void
    {
        $this->slug = $slug;

        $attemptId = session('last_attempt_id');
        if (!$attemptId) {
            $this->redirect(route('student.test.landing', ['slug' => $slug]));
            return;
        }

        $this->attempt = TestAttempt::with(['test', 'student', 'responses.testQuestion'])
            ->findOrFail($attemptId);

        if ($this->attempt->status !== 'submitted') {
            $this->redirect(route('student.test.landing', ['slug' => $slug]));
        }
    }
}; ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Test Result — {{ $attempt->test->title ?? 'ExamSphere' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-[#f5f5f5]">

<header style="background:#1a3c5e;" class="py-3 px-4 sm:px-8 flex items-center justify-between">
    <div class="text-white font-bold text-lg">ExamSphere</div>
    <div class="text-white/70 text-sm">Test Result</div>
</header>

@php
    $a = $attempt;
    $total = $a->responses->count();
    $answered = $a->responses->whereIn('status',['answered','answered_marked_review'])->count();
    $correct = $a->total_correct ?? 0;
    $wrong = $a->total_wrong ?? 0;
    $unattempted = $total - $answered;
    $score = $a->total_score ?? 0;
    $maxMarks = $a->test->total_marks ?? ($total * 4);
    $accuracy = $answered > 0 ? round(($correct / $answered) * 100, 1) : 0;
    $percentage = $maxMarks > 0 ? round(($score / $maxMarks) * 100, 1) : 0;
@endphp

<div class="max-w-3xl mx-auto px-4 py-8 space-y-6">

    <!-- Score card -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div style="background:linear-gradient(135deg,#1a3c5e,#2a5a8e);" class="px-6 py-8 text-white text-center">
            <p class="text-white/70 text-sm mb-1">{{ $a->student->name }} • {{ $a->test->title }}</p>
            <div class="text-6xl font-bold mb-2">{{ $score }}</div>
            <p class="text-white/80 text-lg">out of {{ $maxMarks }} marks</p>
            <div class="mt-4 inline-flex items-center gap-2 bg-white/10 rounded-full px-4 py-1.5">
                <span class="text-white/80 text-sm">Percentile</span>
                <span class="font-bold">{{ $a->percentile ? number_format($a->percentile, 2) : '—' }}</span>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-y sm:divide-y-0 divide-gray-100">
            @foreach([
                ['Correct', $correct, '#16a34a'],
                ['Wrong', $wrong, '#dc2626'],
                ['Unattempted', $unattempted, '#6b7280'],
                ['Accuracy', $accuracy.'%', '#1a3c5e'],
            ] as [$label, $val, $color])
            <div class="px-4 py-4 text-center">
                <p class="text-2xl font-bold" style="color:{{ $color }}">{{ $val }}</p>
                <p class="text-xs text-gray-500 mt-0.5">{{ $label }}</p>
            </div>
            @endforeach
        </div>
    </div>

    @if($a->rank_in_test)
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm px-6 py-4 flex items-center justify-between">
        <div>
            <p class="text-sm text-gray-500">Your Rank</p>
            <p class="text-3xl font-bold" style="color:#1a3c5e;">#{{ $a->rank_in_test }}</p>
        </div>
        <div class="text-right">
            <p class="text-sm text-gray-500">Score</p>
            <p class="text-3xl font-bold">{{ number_format($percentage, 1) }}%</p>
        </div>
    </div>
    @endif

    <!-- Response breakdown -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 font-semibold text-sm" style="color:#1a3c5e;">Question Summary</div>
        <div class="divide-y divide-gray-50">
            @foreach($a->responses->groupBy(fn($r) => $r->testQuestion?->section_id ?? 'General') as $section => $responses)
            <div class="px-5 py-3">
                <p class="text-xs font-semibold text-gray-400 uppercase mb-2">Section {{ $section }}</p>
                <div class="flex flex-wrap gap-2">
                    @foreach($responses as $r)
                    @php
                        $cls = match($r->status) {
                            'answered' => $r->is_correct ? 'bg-green-500 text-white' : 'bg-red-500 text-white',
                            'answered_marked_review' => $r->is_correct ? 'bg-green-400 text-white' : 'bg-red-400 text-white',
                            'not_visited' => 'bg-gray-200 text-gray-600',
                            default => 'bg-red-100 text-red-700',
                        };
                    @endphp
                    <div class="h-7 w-7 rounded text-xs font-bold flex items-center justify-center {{ $cls }}">{{ $r->testQuestion?->question_number ?? '?' }}</div>
                    @endforeach
                </div>
            </div>
            @endforeach
        </div>
    </div>

    <div class="text-center pb-4">
        <p class="text-sm text-gray-500 mb-3">Detailed results will be available on the results portal</p>
        <a href="{{ route('student.results') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-5 py-2 text-sm font-medium hover:bg-gray-50 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
            View All Results
        </a>
    </div>
</div>
</body>
</html>
