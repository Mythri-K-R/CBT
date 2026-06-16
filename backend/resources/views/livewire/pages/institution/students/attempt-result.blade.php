<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\Student;
use App\Models\TestAttempt;
use App\Models\TestResponse;

new #[Layout('layouts.institution')] class extends Component {
    public Student $student;
    public TestAttempt $attempt;

    public function mount(Student $student, TestAttempt $attempt): void
    {
        abort_if($student->institution_id !== auth()->user()->institution_id, 403);
        abort_if($attempt->student_id !== $student->id, 403);
        abort_if(!in_array($attempt->status, ['submitted', 'completed']), 404);

        $this->student = $student->load('batches');
        $this->attempt = $attempt->load('test.sections', 'student');
    }

    public function with(): array
    {
        $responses = TestResponse::with([
            'testQuestion.question.chapter',
            'testQuestion.section',
        ])->where('attempt_id', $this->attempt->id)
          ->orderBy('id')
          ->get();

        // Build section → chapter breakdown
        $sections = [];
        foreach ($responses as $resp) {
            $tq          = $resp->testQuestion;
            $sectionName = $tq?->section?->name ?? 'General';
            $chapterName = $tq?->question?->chapter?->name ?? 'General';

            if (!isset($sections[$sectionName])) {
                $sections[$sectionName] = ['name' => $sectionName, 'correct' => 0, 'wrong' => 0, 'skipped' => 0, 'score' => 0.0, 'total' => 0, 'chapters' => []];
            }
            if (!isset($sections[$sectionName]['chapters'][$chapterName])) {
                $sections[$sectionName]['chapters'][$chapterName] = ['name' => $chapterName, 'correct' => 0, 'wrong' => 0, 'skipped' => 0, 'score' => 0.0, 'total' => 0];
            }

            $answered = $resp->selected_answer !== null && in_array($resp->status, ['answered', 'answered_marked_review']);

            $sections[$sectionName]['total']++;
            $sections[$sectionName]['chapters'][$chapterName]['total']++;

            if (!$answered) {
                $sections[$sectionName]['skipped']++;
                $sections[$sectionName]['chapters'][$chapterName]['skipped']++;
            } elseif ($resp->is_correct) {
                $sections[$sectionName]['correct']++;
                $sections[$sectionName]['chapters'][$chapterName]['correct']++;
                $sections[$sectionName]['score'] += (float)$resp->marks_awarded;
                $sections[$sectionName]['chapters'][$chapterName]['score'] += (float)$resp->marks_awarded;
            } else {
                $sections[$sectionName]['wrong']++;
                $sections[$sectionName]['chapters'][$chapterName]['wrong']++;
                $sections[$sectionName]['score'] += (float)$resp->marks_awarded;
                $sections[$sectionName]['chapters'][$chapterName]['score'] += (float)$resp->marks_awarded;
            }
        }

        $totalQ      = $responses->count();
        $correct     = (int)$this->attempt->total_correct;
        $wrong       = (int)$this->attempt->total_wrong;
        $skipped     = $totalQ - $correct - $wrong;
        $answered    = $correct + $wrong;
        $accuracy    = $answered > 0 ? round($correct / $answered * 100, 1) : 0;
        $score       = (float)$this->attempt->total_score;
        $maxScore    = (float)($this->attempt->max_possible_score ?? 0);
        $percentage  = $maxScore > 0 ? round($score / $maxScore * 100, 1) : 0;

        return compact('sections', 'totalQ', 'correct', 'wrong', 'skipped', 'accuracy', 'score', 'maxScore', 'percentage');
    }
}; ?>

<div>
<div class="space-y-6">

    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 text-sm text-muted-foreground">
        <a href="{{ route('institution.students') }}" wire:navigate class="hover:text-foreground">Students</a>
        <span>/</span>
        <a href="{{ route('institution.students.show', $student->id) }}" wire:navigate class="hover:text-foreground">{{ $student->name }}</a>
        <span>/</span>
        <span class="text-foreground font-medium">{{ $attempt->test->title }}</span>
    </div>

    {{-- Header --}}
    <div class="flex items-start justify-between gap-4">
        <div class="flex items-center gap-4">
            <div class="h-12 w-12 rounded-full bg-primary/10 text-primary flex items-center justify-center text-xl font-bold">
                {{ substr($student->name, 0, 1) }}
            </div>
            <div>
                <h1 class="font-display text-2xl font-bold">{{ $student->name }}</h1>
                <p class="text-sm text-muted-foreground mt-0.5">
                    {{ $student->roll_number }}
                    @if($student->batches->isNotEmpty()) · {{ $student->batches->first()->name }} @endif
                    · {{ $attempt->test->title }} · {{ \Carbon\Carbon::parse($attempt->submitted_at)->format('d M Y, h:i A') }}
                </p>
            </div>
        </div>
        <a href="{{ route('institution.students.answer-sheet', ['student' => $student->id, 'attempt' => $attempt->uuid]) }}"
           class="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-4 py-2 text-sm font-medium hover:bg-muted transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            View Answer Sheet
        </a>
    </div>

    {{-- Score Summary Cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        @foreach([
            ['Total Score',  number_format($score, 0) . ' / ' . number_format($maxScore, 0), 'text-primary'],
            ['Percentage',   $percentage . '%',   'text-info'],
            ['Correct',      $correct,             'text-success'],
            ['Wrong',        $wrong,               'text-destructive'],
            ['Skipped',      $skipped,             'text-muted-foreground'],
            ['Accuracy',     $accuracy . '%',      'text-warning'],
        ] as [$label, $val, $cls])
        <div class="rounded-xl border border-border bg-card p-4 text-center">
            <p class="text-xs text-muted-foreground mb-1">{{ $label }}</p>
            <p class="text-xl font-bold {{ $cls }}">{{ $val }}</p>
        </div>
        @endforeach
    </div>

    {{-- Section + Chapter Breakdown --}}
    @foreach($sections as $sec)
    @php
        $secTotal = $sec['total'];
        $secCorrectPct = $secTotal > 0 ? round($sec['correct'] / $secTotal * 100) : 0;
        $secWrongPct   = $secTotal > 0 ? round($sec['wrong']   / $secTotal * 100) : 0;
    @endphp
    <div class="rounded-xl border border-border bg-card overflow-hidden">
        {{-- Section header --}}
        <div class="flex items-center justify-between px-5 py-3 border-b border-border bg-muted/30">
            <div class="flex items-center gap-3">
                <span class="font-bold text-base">{{ $sec['name'] }}</span>
                <span class="text-xs text-muted-foreground">{{ $secTotal }} questions</span>
            </div>
            <div class="flex items-center gap-4 text-sm">
                <span class="text-green-600 font-semibold">+{{ $sec['correct'] }}</span>
                <span class="text-red-500 font-semibold">–{{ $sec['wrong'] }}</span>
                <span class="text-muted-foreground">{{ $sec['skipped'] }} skipped</span>
                <span class="font-bold text-primary ml-2">{{ number_format($sec['score'], 0) }} marks</span>
            </div>
        </div>

        {{-- Progress bar --}}
        <div class="px-5 py-2 border-b border-border/50">
            <div class="h-2 bg-muted rounded-full overflow-hidden flex">
                @if($secCorrectPct > 0)<div class="bg-green-500 h-full" style="width:{{ $secCorrectPct }}%"></div>@endif
                @if($secWrongPct > 0)<div class="bg-red-400 h-full" style="width:{{ $secWrongPct }}%"></div>@endif
            </div>
        </div>

        {{-- Chapter table --}}
        <table class="w-full text-sm">
            <thead class="border-b border-border/50">
                <tr class="text-xs text-muted-foreground uppercase">
                    <th class="text-left px-5 py-2 font-semibold">Chapter / Topic</th>
                    <th class="text-center px-3 py-2 font-semibold">Total</th>
                    <th class="text-center px-3 py-2 font-semibold text-green-600">Correct</th>
                    <th class="text-center px-3 py-2 font-semibold text-red-500">Wrong</th>
                    <th class="text-center px-3 py-2 font-semibold">Skipped</th>
                    <th class="text-right px-5 py-2 font-semibold">Marks</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border/40">
                @foreach($sec['chapters'] as $ch)
                @php $chPct = $ch['total'] > 0 ? round($ch['correct'] / $ch['total'] * 100) : 0; @endphp
                <tr class="hover:bg-muted/20">
                    <td class="px-5 py-3">
                        <div class="font-medium">{{ $ch['name'] }}</div>
                        <div class="h-1 bg-muted rounded-full mt-1.5 w-24 overflow-hidden">
                            <div class="h-full bg-primary rounded-full" style="width:{{ $chPct }}%"></div>
                        </div>
                    </td>
                    <td class="px-3 py-3 text-center text-muted-foreground">{{ $ch['total'] }}</td>
                    <td class="px-3 py-3 text-center font-semibold text-green-600">{{ $ch['correct'] }}</td>
                    <td class="px-3 py-3 text-center font-semibold text-red-500">{{ $ch['wrong'] }}</td>
                    <td class="px-3 py-3 text-center text-muted-foreground">{{ $ch['skipped'] }}</td>
                    <td class="px-5 py-3 text-right font-bold {{ $ch['score'] >= 0 ? 'text-green-700' : 'text-red-600' }}">
                        {{ $ch['score'] >= 0 ? '+' : '' }}{{ number_format($ch['score'], 0) }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endforeach

</div>
</div>
