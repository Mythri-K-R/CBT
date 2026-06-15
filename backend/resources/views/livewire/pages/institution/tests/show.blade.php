<?php

use App\Models\Test;
use App\Models\TestAttempt;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.institution')] class extends Component {
    public Test $test;
    public string $tab = 'overview';

    public function mount(Test $test): void
    {
        abort_if($test->institution_id !== auth()->user()->institution_id, 403);
        $this->test = $test->load(['template', 'batches', 'testSections.testQuestions']);
    }

    public function with(): array
    {
        $attempts = TestAttempt::with('student')
            ->where('test_id', $this->test->id)
            ->where('status', 'submitted')
            ->orderByDesc('total_score')
            ->get();

        $total = $attempts->count();
        $avgScore = $total ? round($attempts->avg('total_score'), 1) : 0;
        $topScore = $attempts->max('total_score') ?? 0;
        $avgAccuracy = $total ? round($attempts->avg(fn($a) => $a->total_correct / max(1, ($a->total_correct + $a->total_wrong + $a->total_unattempted)) * 100), 1) : 0;

        return compact('attempts', 'total', 'avgScore', 'topScore', 'avgAccuracy');
    }
}; ?>

<div>
<div class="space-y-6">
    <div class="flex items-center gap-4">
        <a href="{{ route('institution.tests') }}" wire:navigate class="h-9 w-9 rounded-lg border border-border flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div class="flex-1 min-w-0">
            <h1 class="font-display text-2xl font-bold truncate">{{ $test->title }}</h1>
            <div class="flex items-center gap-3 mt-1 flex-wrap text-sm text-muted-foreground">
                <span>{{ strtoupper($test->exam_type) }}</span>
                <span>·</span>
                <span>{{ $test->duration_minutes }} min</span>
                <span>·</span>
                @php
                    $statusColors = ['draft'=>'bg-muted text-muted-foreground','scheduled'=>'bg-info/10 text-info','live'=>'bg-success/10 text-success','completed'=>'bg-muted text-muted-foreground'];
                    $sc = $statusColors[$test->status] ?? 'bg-muted text-muted-foreground';
                @endphp
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $sc }}">
                    @if($test->status === 'live')<span class="h-1.5 w-1.5 rounded-full bg-success mr-1.5 animate-pulse"></span>@endif
                    {{ ucfirst($test->status) }}
                </span>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button class="h-9 rounded-lg border border-border px-3 text-sm font-medium hover:bg-muted transition-colors flex items-center gap-1.5">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                Share Link
            </button>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach([
            ['Submissions', $total, 'text-primary'],
            ['Avg Score', $avgScore, 'text-info'],
            ['Top Score', $topScore, 'text-success'],
            ['Avg Accuracy', $avgAccuracy.'%', 'text-warning'],
        ] as [$label, $val, $color])
        <div class="rounded-xl border border-border bg-card p-4">
            <p class="text-sm text-muted-foreground">{{ $label }}</p>
            <p class="text-3xl font-bold {{ $color }} mt-1">{{ $val }}</p>
        </div>
        @endforeach
    </div>

    <!-- Tabs -->
    <div class="border-b border-border flex gap-0">
        @foreach(['overview'=>'Overview','results'=>'Results & Rankings','questions'=>'Questions'] as $key => $label)
        <button wire:click="$set('tab','{{ $key }}')" class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors {{ $tab === $key ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if($tab === 'overview')
    <div class="grid lg:grid-cols-2 gap-6">
        <div class="rounded-xl border border-border bg-card p-5">
            <h3 class="font-semibold mb-4">Test Details</h3>
            <dl class="space-y-3 text-sm">
                @foreach([
                    ['Template', $test->template->name ?? '—'],
                    ['Exam Type', strtoupper($test->exam_type)],
                    ['Duration', $test->duration_minutes.' minutes'],
                    ['Total Marks', $test->total_marks ?? '—'],
                    ['Sections', $test->testSections->count()],
                    ['Questions', $test->testSections->sum(fn($s) => $s->testQuestions->count())],
                    ['Start', $test->scheduled_start?->format('d M Y, h:i A') ?? 'Manual'],
                    ['End', $test->scheduled_end?->format('d M Y, h:i A') ?? 'Manual'],
                ] as [$label, $val])
                <div class="flex justify-between">
                    <dt class="text-muted-foreground">{{ $label }}</dt>
                    <dd class="font-medium">{{ $val }}</dd>
                </div>
                @endforeach
            </dl>
        </div>

        <div class="rounded-xl border border-border bg-card p-5">
            <h3 class="font-semibold mb-4">Assigned Batches</h3>
            @if($test->batches->isEmpty())
            <p class="text-sm text-muted-foreground">No batches assigned.</p>
            @else
            <div class="space-y-2">
                @foreach($test->batches as $batch)
                <div class="flex items-center justify-between text-sm">
                    <span class="font-medium">{{ $batch->name }}</span>
                    <span class="text-muted-foreground">{{ strtoupper($batch->exam_type) }}</span>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>
    @endif

    @if($tab === 'results')
    <div class="rounded-xl border border-border bg-card overflow-hidden">
        <div class="px-4 py-3 border-b border-border bg-muted/30 flex items-center justify-between">
            <h3 class="font-semibold text-sm">Student Rankings</h3>
            <button class="text-xs font-medium text-primary hover:underline">Export CSV</button>
        </div>
        <table class="w-full text-sm">
            <thead class="border-b border-border">
                <tr>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Rank</th>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Student</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground">Score</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground hidden sm:table-cell">Correct</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground hidden sm:table-cell">Wrong</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground hidden md:table-cell">Accuracy</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse($attempts as $i => $a)
                @php $accuracy = ($a->total_correct + $a->total_wrong) > 0 ? round($a->total_correct / ($a->total_correct + $a->total_wrong) * 100) : 0; @endphp
                <tr class="hover:bg-muted/20 transition-colors">
                    <td class="px-4 py-3 font-bold {{ $i < 3 ? 'text-warning' : 'text-muted-foreground' }}">#{{ $i + 1 }}</td>
                    <td class="px-4 py-3">
                        <div class="font-medium">{{ $a->student->name }}</div>
                        <div class="text-xs text-muted-foreground">{{ $a->student->roll_number }}</div>
                    </td>
                    <td class="px-4 py-3 text-right font-bold">{{ $a->total_score }}</td>
                    <td class="px-4 py-3 text-right text-green-600 hidden sm:table-cell">{{ $a->total_correct }}</td>
                    <td class="px-4 py-3 text-right text-red-500 hidden sm:table-cell">{{ $a->total_wrong }}</td>
                    <td class="px-4 py-3 text-right hidden md:table-cell">{{ $accuracy }}%</td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-12 text-center text-muted-foreground">No submissions yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @endif

    @if($tab === 'questions')
    <div class="rounded-xl border border-border bg-card overflow-hidden">
        @foreach($test->testSections as $section)
        <div class="border-b border-border last:border-0">
            <div class="px-4 py-3 bg-muted/30 font-semibold text-sm">{{ $section->name }}</div>
            <table class="w-full text-sm">
                <thead class="border-b border-border">
                    <tr>
                        <th class="text-left px-4 py-2.5 font-semibold text-muted-foreground">Q#</th>
                        <th class="text-left px-4 py-2.5 font-semibold text-muted-foreground">Question</th>
                        <th class="text-right px-4 py-2.5 font-semibold text-muted-foreground">Marks</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach($section->testQuestions as $tq)
                    <tr class="hover:bg-muted/20">
                        <td class="px-4 py-3 font-mono text-muted-foreground">{{ $tq->question_number }}</td>
                        <td class="px-4 py-3 max-w-md">
                            <div class="line-clamp-2 text-sm">{!! strip_tags($tq->question->content ?? '—') !!}</div>
                        </td>
                        <td class="px-4 py-3 text-right text-xs">
                            <span class="text-green-600">+{{ $tq->positive_marks }}</span> /
                            <span class="text-red-500">-{{ $tq->negative_marks }}</span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endforeach
    </div>
    @endif
</div>
</div>
