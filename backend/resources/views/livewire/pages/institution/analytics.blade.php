<?php

use App\Models\Batch;
use App\Models\Student;
use App\Models\Test;
use App\Models\TestAttempt;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.institution')] class extends Component {
    public string $batchFilter = '';
    public string $examTypeFilter = '';
    public string $period = '30';

    public function with(): array
    {
        $institutionId = auth()->user()->institution_id;
        $since = now()->subDays((int)$this->period);

        $batches = Batch::where('institution_id', $institutionId)->orderBy('name')->get();

        $attemptsQuery = TestAttempt::query()
            ->whereHas('test', fn($q) => $q->where('institution_id', $institutionId))
            ->where('status', 'submitted')
            ->where('submitted_at', '>=', $since)
            ->when($this->batchFilter, fn($q) => $q->whereHas('student', fn($q2) => $q2->where('batch_id', $this->batchFilter)))
            ->when($this->examTypeFilter, fn($q) => $q->whereHas('test', fn($q2) => $q2->where('exam_type', $this->examTypeFilter)));

        $attempts = (clone $attemptsQuery)->with(['test','student.batch'])->get();

        $totalAttempts = $attempts->count();
        $avgScore = $totalAttempts ? round($attempts->avg('total_score'), 1) : 0;
        $avgAccuracy = $totalAttempts ? round($attempts->avg(fn($a) => ($a->total_correct + $a->total_wrong) > 0 ? ($a->total_correct / ($a->total_correct + $a->total_wrong)) * 100 : 0), 1) : 0;
        $activeStudents = $attempts->pluck('student_id')->unique()->count();
        $testsRun = $attempts->pluck('test_id')->unique()->count();

        // Score trend grouped by week
        $weeklyData = $attempts
            ->groupBy(fn($a) => \Carbon\Carbon::parse($a->submitted_at)->format('Y-W'))
            ->map(fn($g) => ['week' => \Carbon\Carbon::parse($g->first()->submitted_at)->format('d M'), 'avg' => round($g->avg('total_score'), 1), 'count' => $g->count()])
            ->values()
            ->take(-8);

        // Batch comparison
        $batchStats = $attempts->groupBy(fn($a) => $a->student->batch_id ?? 'none')
            ->map(fn($g, $batchId) => [
                'name' => $batches->find($batchId)?->name ?? 'No Batch',
                'avg' => round($g->avg('total_score'), 1),
                'count' => $g->count(),
                'accuracy' => round($g->avg(fn($a) => ($a->total_correct + $a->total_wrong) > 0 ? ($a->total_correct / ($a->total_correct + $a->total_wrong)) * 100 : 0), 1),
            ])
            ->values();

        // Top performers
        $topPerformers = $attempts
            ->groupBy('student_id')
            ->map(fn($g) => ['student' => $g->first()->student, 'avg' => round($g->avg('total_score'), 1), 'tests' => $g->count()])
            ->sortByDesc('avg')
            ->take(5)
            ->values();

        return compact('batches','totalAttempts','avgScore','avgAccuracy','activeStudents','testsRun','weeklyData','batchStats','topPerformers');
    }
}; ?>

<div>
<div class="space-y-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="font-display text-2xl font-bold">Analytics</h1>
            <p class="text-muted-foreground text-sm mt-1">Institution-wide performance insights</p>
        </div>
        <div class="flex items-center gap-2">
            <select wire:model.live="period" class="h-9 rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                <option value="7">Last 7 days</option>
                <option value="30">Last 30 days</option>
                <option value="90">Last 90 days</option>
                <option value="365">Last year</option>
            </select>
            <select wire:model.live="batchFilter" class="h-9 rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                <option value="">All Batches</option>
                @foreach($batches as $b)
                <option value="{{ $b->id }}">{{ $b->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        @foreach([
            ['Total Attempts', $totalAttempts, 'text-primary'],
            ['Avg Score', $avgScore, 'text-info'],
            ['Avg Accuracy', $avgAccuracy.'%', 'text-success'],
            ['Active Students', $activeStudents, 'text-warning'],
            ['Tests Run', $testsRun, 'text-primary'],
        ] as [$label, $val, $color])
        <div class="rounded-xl border border-border bg-card p-4">
            <p class="text-xs text-muted-foreground">{{ $label }}</p>
            <p class="text-2xl font-bold {{ $color }} mt-1">{{ $val }}</p>
        </div>
        @endforeach
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
        <!-- Score trend (simple table-based chart) -->
        <div class="rounded-xl border border-border bg-card p-5">
            <h3 class="font-semibold mb-4">Weekly Score Trend</h3>
            @if($weeklyData->isEmpty())
            <p class="text-sm text-muted-foreground py-8 text-center">No data for this period</p>
            @else
            @php $maxVal = $weeklyData->max('avg') ?: 1; @endphp
            <div class="space-y-3">
                @foreach($weeklyData as $w)
                <div class="flex items-center gap-3 text-sm">
                    <span class="w-14 text-xs text-muted-foreground shrink-0">{{ $w['week'] }}</span>
                    <div class="flex-1 bg-muted rounded-full h-2">
                        <div class="bg-primary h-2 rounded-full transition-all" style="width:{{ ($w['avg']/$maxVal)*100 }}%"></div>
                    </div>
                    <span class="w-12 text-right font-medium">{{ $w['avg'] }}</span>
                    <span class="text-xs text-muted-foreground w-16">({{ $w['count'] }} tests)</span>
                </div>
                @endforeach
            </div>
            @endif
        </div>

        <!-- Batch comparison -->
        <div class="rounded-xl border border-border bg-card p-5">
            <h3 class="font-semibold mb-4">Batch Comparison</h3>
            @if($batchStats->isEmpty())
            <p class="text-sm text-muted-foreground py-8 text-center">No data for this period</p>
            @else
            <div class="space-y-3">
                @foreach($batchStats as $b)
                <div class="flex items-center justify-between text-sm">
                    <span class="font-medium">{{ $b['name'] }}</span>
                    <div class="flex items-center gap-4 text-muted-foreground text-xs">
                        <span>Avg: <strong class="text-foreground">{{ $b['avg'] }}</strong></span>
                        <span>Acc: <strong class="text-foreground">{{ $b['accuracy'] }}%</strong></span>
                        <span>{{ $b['count'] }} attempts</span>
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>

    <!-- Top performers -->
    <div class="rounded-xl border border-border bg-card overflow-hidden">
        <div class="px-5 py-4 border-b border-border">
            <h3 class="font-semibold">Top Performers</h3>
        </div>
        <table class="w-full text-sm">
            <thead class="border-b border-border bg-muted/30">
                <tr>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Student</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground">Avg Score</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground">Tests Taken</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse($topPerformers as $i => $p)
                <tr class="hover:bg-muted/20 transition-colors">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="text-warning font-bold w-5">{{ $i+1 }}</span>
                            <div class="h-7 w-7 rounded-full bg-primary/10 text-primary flex items-center justify-center text-xs font-bold">{{ substr($p['student']->name, 0, 1) }}</div>
                            <div>
                                <p class="font-medium">{{ $p['student']->name }}</p>
                                <p class="text-xs text-muted-foreground">{{ $p['student']->roll_number }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-right font-bold">{{ $p['avg'] }}</td>
                    <td class="px-4 py-3 text-right text-muted-foreground">{{ $p['tests'] }}</td>
                </tr>
                @empty
                <tr><td colspan="3" class="px-4 py-12 text-center text-muted-foreground">No data yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
</div>
