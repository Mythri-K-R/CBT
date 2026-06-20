<?php

use App\Models\Test;
use App\Models\TestAttempt;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.institution')] class extends Component {
    public Test $test;
    public string $search = '';

    public function mount(Test $test): void
    {
        abort_if($test->institution_id !== auth()->user()->institution_id, 403);
        $this->test = $test->load('batches');
    }

    public function with(): array
    {
        $attempts = TestAttempt::with('student')
            ->where('test_id', $this->test->id)
            ->where('status', 'submitted')
            ->when($this->search, fn($q) => $q->whereHas('student', fn($q2) => $q2->where('name','like',"%{$this->search}%")->orWhere('roll_number','like',"%{$this->search}%")))
            ->orderByDesc('total_score')
            ->get()
            ->values()
            ->map(fn($a, $idx) => tap($a, fn($a) => $a->rank = $idx + 1));

        $stats = [
            'total' => $attempts->count(),
            'avg' => $attempts->avg('total_score') ? round($attempts->avg('total_score'), 1) : 0,
            'top' => $attempts->max('total_score') ?? 0,
            'avgAccuracy' => $attempts->count() ? round($attempts->avg(fn($a) => ($a->total_correct + $a->total_wrong) > 0 ? $a->total_correct / ($a->total_correct + $a->total_wrong) * 100 : 0), 1) : 0,
        ];

        return compact('attempts', 'stats');
    }
}; ?>

<div>
<div class="space-y-6">
    <div class="flex items-center gap-4">
        <a href="{{ route('institution.results') }}" wire:navigate class="h-9 w-9 rounded-lg border border-border flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div>
            <h1 class="font-display text-2xl font-bold">{{ $test->title }}</h1>
            <p class="text-sm text-muted-foreground mt-0.5">{{ strtoupper($test->exam_type) }} · {{ $test->duration_minutes }}min · {{ $test->total_marks }} marks</p>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach([
            ['Submissions', $stats['total'], 'text-primary'],
            ['Avg Score', $stats['avg'], 'text-info'],
            ['Top Score', $stats['top'], 'text-success'],
            ['Avg Accuracy', $stats['avgAccuracy'].'%', 'text-warning'],
        ] as [$l, $v, $c])
        <div class="rounded-xl border border-border bg-card p-4">
            <p class="text-sm text-muted-foreground">{{ $l }}</p>
            <p class="text-3xl font-bold {{ $c }} mt-1">{{ $v }}</p>
        </div>
        @endforeach
    </div>

    <div class="flex items-center justify-between flex-wrap gap-3">
        <div class="relative max-w-xs">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search students..." class="h-9 w-full rounded-md border border-input bg-background pl-9 pr-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
        </div>
        <button class="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm font-medium hover:bg-muted transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
            Export
        </button>
    </div>

    <div class="rounded-xl border border-border bg-card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="border-b border-border bg-muted/30">
                <tr>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground w-12">Rank</th>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Student</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground">Score</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground hidden sm:table-cell">Correct</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground hidden sm:table-cell">Wrong</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground hidden md:table-cell">Accuracy</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground hidden lg:table-cell">Percentile</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse($attempts as $a)
                @php
                    $acc = ($a->total_correct + $a->total_wrong) > 0 ? round($a->total_correct / ($a->total_correct + $a->total_wrong) * 100, 1) : 0;
                    $medalClass = match($a->rank) { 1=>'text-yellow-500', 2=>'text-gray-400', 3=>'text-amber-600', default=>'text-muted-foreground' };
                @endphp
                <tr class="hover:bg-muted/20 transition-colors">
                    <td class="px-4 py-3 font-bold text-base {{ $medalClass }}">#{{ $a->rank }}</td>
                    <td class="px-4 py-3">
                        <div class="font-medium">{{ $a->student->name }}</div>
                        <div class="text-xs text-muted-foreground font-mono">{{ $a->student->roll_number }}</div>
                    </td>
                    <td class="px-4 py-3 text-right font-bold text-base">{{ $a->total_score }}</td>
                    <td class="px-4 py-3 text-right text-green-600 hidden sm:table-cell">{{ $a->total_correct }}</td>
                    <td class="px-4 py-3 text-right text-red-500 hidden sm:table-cell">{{ $a->total_wrong }}</td>
                    <td class="px-4 py-3 text-right hidden md:table-cell">{{ $acc }}%</td>
                    <td class="px-4 py-3 text-right hidden lg:table-cell">{{ $a->percentile ? number_format($a->percentile, 2) : '—' }}</td>
                </tr>
                @empty
                <tr><td colspan="7" class="px-4 py-16 text-center text-muted-foreground">No submissions yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
</div>
