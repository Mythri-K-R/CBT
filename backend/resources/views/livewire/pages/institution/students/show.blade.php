<?php

use App\Models\Student;
use App\Models\TestAttempt;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.institution')] class extends Component {
    public Student $student;
    public string $tab = 'overview';

    public function mount(Student $student): void
    {
        abort_if($student->institution_id !== auth()->user()->institution_id, 403);
        $this->student = $student->load('batches');
    }

    public function with(): array
    {
        $attempts = TestAttempt::with('test')
            ->where('student_id', $this->student->id)
            ->where('status', 'submitted')
            ->orderByDesc('submitted_at')
            ->get();

        $stats = [
            'total' => $attempts->count(),
            'avgScore' => $attempts->count() ? round($attempts->avg('total_score'), 1) : 0,
            'bestScore' => $attempts->max('total_score') ?? 0,
            'avgAccuracy' => $attempts->count() ? round($attempts->avg(fn($a) => ($a->total_correct + $a->total_wrong) > 0 ? $a->total_correct / ($a->total_correct + $a->total_wrong) * 100 : 0), 1) : 0,
        ];

        return compact('attempts', 'stats');
    }
}; ?>

<div>
<div class="space-y-6">
    <div class="flex items-center gap-4">
        <a href="{{ route('institution.students') }}" wire:navigate class="h-9 w-9 rounded-lg border border-border flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div class="flex items-center gap-4">
            <div class="h-12 w-12 rounded-full bg-primary/10 text-primary flex items-center justify-center text-xl font-bold">{{ substr($student->name, 0, 1) }}</div>
            <div>
                <h1 class="font-display text-2xl font-bold">{{ $student->name }}</h1>
                <div class="flex items-center gap-2 text-sm text-muted-foreground mt-0.5">
                    <span class="font-mono">{{ $student->roll_number }}</span>
                    @if($student->batches->isNotEmpty())
                    <span>·</span>
                    <span>{{ $student->batches->first()->name }}</span>
                    @endif
                    @if($student->phone)
                    <span>·</span>
                    <span>{{ $student->phone }}</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach([
            ['Tests Taken', $stats['total'], 'text-primary'],
            ['Avg Score', $stats['avgScore'], 'text-info'],
            ['Best Score', $stats['bestScore'], 'text-success'],
            ['Avg Accuracy', $stats['avgAccuracy'].'%', 'text-warning'],
        ] as [$l, $v, $c])
        <div class="rounded-xl border border-border bg-card p-4">
            <p class="text-sm text-muted-foreground">{{ $l }}</p>
            <p class="text-3xl font-bold {{ $c }} mt-1">{{ $v }}</p>
        </div>
        @endforeach
    </div>

    <!-- Tabs -->
    <div class="border-b border-border flex gap-0">
        @foreach(['overview'=>'Overview','history'=>'Test History'] as $key => $label)
        <button wire:click="$set('tab','{{ $key }}')" class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors {{ $tab === $key ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if($tab === 'overview')
    <div class="grid lg:grid-cols-2 gap-6">
        <div class="rounded-xl border border-border bg-card p-5">
            <h3 class="font-semibold mb-4">Student Details</h3>
            <dl class="space-y-3 text-sm">
                @foreach([
                    ['Name', $student->name],
                    ['Roll Number', $student->roll_number],
                    ['Batch', $student->batches->first()?->name ?? '—'],
                    ['Phone', $student->phone ?? '—'],
                    ['Enrolled', $student->created_at->format('d M Y')],
                ] as [$label, $val])
                <div class="flex justify-between">
                    <dt class="text-muted-foreground">{{ $label }}</dt>
                    <dd class="font-medium">{{ $val }}</dd>
                </div>
                @endforeach
            </dl>
        </div>

        @if($attempts->isNotEmpty())
        <div class="rounded-xl border border-border bg-card p-5">
            <h3 class="font-semibold mb-4">Score Trend</h3>
            @php $maxScore = $attempts->max('total_score') ?: 1; @endphp
            <div class="space-y-2">
                @foreach($attempts->take(8)->reverse() as $a)
                <div class="flex items-center gap-3 text-xs">
                    <span class="w-24 text-muted-foreground truncate">{{ $a->test->title }}</span>
                    <div class="flex-1 bg-muted rounded-full h-2">
                        <div class="h-2 rounded-full bg-primary transition-all" style="width:{{ ($a->total_score / $maxScore) * 100 }}%"></div>
                    </div>
                    <span class="w-8 text-right font-medium">{{ $a->total_score }}</span>
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>
    @endif

    @if($tab === 'history')
    <div class="rounded-xl border border-border bg-card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="border-b border-border bg-muted/30">
                <tr>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Test</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground">Score</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground hidden sm:table-cell">Correct</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground hidden sm:table-cell">Wrong</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground hidden md:table-cell">Rank</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse($attempts as $a)
                <tr class="hover:bg-muted/20 transition-colors cursor-pointer"
                    onclick="window.location='{{ route('institution.students.attempt', ['student' => $student->id, 'attempt' => $a->uuid]) }}'">
                    <td class="px-4 py-3">
                        <div class="font-medium">{{ $a->test->title }}</div>
                        <div class="text-xs text-muted-foreground">{{ strtoupper($a->test->exam_type) }}</div>
                    </td>
                    <td class="px-4 py-3 text-right font-bold">{{ $a->total_score }}</td>
                    <td class="px-4 py-3 text-right text-green-600 hidden sm:table-cell">{{ $a->total_correct }}</td>
                    <td class="px-4 py-3 text-right text-red-500 hidden sm:table-cell">{{ $a->total_wrong }}</td>
                    <td class="px-4 py-3 text-right hidden md:table-cell text-muted-foreground">{{ $a->rank_in_test ? '#'.$a->rank_in_test : '—' }}</td>
                    <td class="px-4 py-3 text-right text-muted-foreground text-xs">{{ \Carbon\Carbon::parse($a->submitted_at)->format('d M Y') }}</td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-12 text-center text-muted-foreground">No test attempts yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @endif
</div>
</div>
