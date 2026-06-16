<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\Subject;
use App\Models\Question;
use App\Models\Test;
use App\Models\TestSection;
use App\Models\TestQuestion;
use App\Models\TestLink;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Carbon\Carbon;

new #[Layout('layouts.institution')] class extends Component {
    public string $step       = 'exam_type';
    public string $examType   = '';
    public string $title      = '';
    public string $description = '';
    public string $scheduledStart = '';
    public array  $subjects   = [];
    public int    $activeSubjectIdx = 0;

    public function selectExamType(string $type): void
    {
        $this->examType = $type;
        $this->loadSubjects();
        $this->activeSubjectIdx = 0;
        $this->step = 'configure';
    }

    private function loadSubjects(): void
    {
        $dbSubjects = Subject::where('exam_type', $this->examType)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->with(['chapters' => fn($q) => $q->where('is_active', true)->orderBy('display_order')])
            ->get();

        $this->subjects = $dbSubjects->map(function ($subject) {
            return [
                'id'       => $subject->id,
                'name'     => $subject->name,
                'chapters' => $subject->chapters->map(function ($chapter) {
                    $available = Question::where('chapter_id', $chapter->id)
                        ->where('exam_type', $this->examType)
                        ->where('status', 'active')
                        ->count();
                    return [
                        'id'        => $chapter->id,
                        'name'      => $chapter->name,
                        'available' => $available,
                        'count'     => 0,
                    ];
                })->values()->toArray(),
            ];
        })->toArray();

        $labels = ['neet' => 'NEET', 'jee_main' => 'JEE Main', 'kcet' => 'CET'];
        $this->title = ($labels[$this->examType] ?? strtoupper($this->examType)) . ' Mock Test';
    }

    private function calcTotalQuestions(): int
    {
        return collect($this->subjects)->sum(
            fn($s) => collect($s['chapters'])->sum(fn($c) => max(0, (int)($c['count'] ?? 0)))
        );
    }

    private function calcSubjectTotals(): array
    {
        return collect($this->subjects)->mapWithKeys(
            fn($s) => [$s['id'] => collect($s['chapters'])->sum(fn($c) => max(0, (int)($c['count'] ?? 0)))]
        )->all();
    }

    public function with(): array
    {
        return [
            'totalQuestions' => $this->calcTotalQuestions(),
            'subjectTotals'  => $this->calcSubjectTotals(),
        ];
    }

    public function goToSchedule(): void
    {
        if ($this->calcTotalQuestions() === 0) {
            $this->addError('configure', 'Please select at least 1 question from any chapter.');
            return;
        }
        $this->resetErrorBag('configure');
        $this->step = 'schedule';
    }

    public function createTest(): void
    {
        $institutionId = Auth::user()->institution_id;

        $this->validate([
            'title' => [
                'required', 'string', 'max:255',
                Rule::unique('tests')->where(fn($q) => $q->where('institution_id', $institutionId)),
            ],
            'scheduledStart' => 'required|date',
        ]);

        $totalQ = $this->calcTotalQuestions();
        if ($totalQ === 0) {
            $this->addError('configure', 'No questions selected.');
            return;
        }

        $duration = $totalQ;
        $startAt  = Carbon::parse($this->scheduledStart);
        $endAt    = $startAt->copy()->addMinutes($duration);
        $marks    = match($this->examType) {
            'kcet'  => ['positive' => 1, 'negative' => 0],
            default => ['positive' => 4, 'negative' => 1],
        };

        $testUuid = null;

        DB::transaction(function () use ($duration, $totalQ, $startAt, $endAt, $marks, &$testUuid) {
            $test = Test::create([
                'institution_id'          => Auth::user()->institution_id,
                'created_by'              => Auth::id(),
                'template_id'             => null,
                'title'                   => $this->title,
                'description'             => $this->description,
                'exam_type'               => $this->examType,
                'test_category'           => 'mock_test',
                'duration_minutes'        => $duration,
                'has_sectional_timing'    => false,
                'allow_section_switching' => true,
                'scheduled_start'         => $startAt,
                'scheduled_end'           => $endAt,
                'status'                  => 'scheduled',
                'total_marks'             => $totalQ * $marks['positive'],
                'show_result_immediately' => true,
            ]);

            $questionNumber = 1;

            foreach ($this->subjects as $order => $subjectData) {
                $subjectTotal = collect($subjectData['chapters'])->sum(fn($c) => max(0, (int)($c['count'] ?? 0)));
                if ($subjectTotal === 0) continue;

                $section = TestSection::create([
                    'test_id'        => $test->id,
                    'name'           => $subjectData['name'],
                    'subject_id'     => $subjectData['id'],
                    'question_count' => $subjectTotal,
                    'positive_marks' => $marks['positive'],
                    'negative_marks' => $marks['negative'],
                    'display_order'  => $order + 1,
                ]);

                foreach ($subjectData['chapters'] as $chapterData) {
                    $count = max(0, (int)($chapterData['count'] ?? 0));
                    if ($count === 0) continue;

                    $questions = Question::where('chapter_id', $chapterData['id'])
                        ->where('exam_type', $this->examType)
                        ->where('status', 'active')
                        ->inRandomOrder()
                        ->limit($count)
                        ->get();

                    foreach ($questions as $q) {
                        TestQuestion::create([
                            'test_id'         => $test->id,
                            'section_id'      => $section->id,
                            'question_id'     => $q->id,
                            'question_number' => $questionNumber++,
                            'positive_marks'  => $marks['positive'],
                            'negative_marks'  => $marks['negative'],
                        ]);
                    }
                }
            }

            $examLabel = ['neet' => 'neet', 'jee_main' => 'jee', 'kcet' => 'kcet'][$this->examType] ?? 'test';
            TestLink::create([
                'test_id'        => $test->id,
                'institution_id' => Auth::user()->institution_id,
                'slug'           => $examLabel . '-' . strtolower(Str::random(8)),
                'is_active'      => true,
            ]);

            $testUuid = $test->uuid;
        });

        $this->redirect(
            route('institution.tests.show', ['test' => $testUuid, 'tab' => 'links']),
            navigate: false
        );
    }
}; ?>

<div class="max-w-4xl mx-auto space-y-8 pb-16">

    {{-- Header --}}
    <div class="flex items-center gap-4">
        <a href="{{ route('institution.tests') }}" class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-secondary hover:bg-secondary/80 text-secondary-foreground transition-colors shrink-0">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div>
            <h1 class="text-3xl font-display font-bold tracking-tight">Create Test</h1>
            <p class="text-muted-foreground mt-1">Build a custom exam from your question bank.</p>
        </div>
    </div>

    {{-- Step indicator --}}
    <div class="flex items-center gap-0">
        @foreach([['exam_type','Exam Type'],['configure','Questions'],['schedule','Schedule']] as $i => [$s, $label])
        @php
            $done    = ($step === 'configure' && $s === 'exam_type')
                    || ($step === 'schedule'  && in_array($s, ['exam_type','configure']));
            $current = $step === $s;
        @endphp
        <div class="flex items-center {{ $i > 0 ? 'flex-1' : '' }}">
            @if($i > 0)
            <div class="flex-1 h-0.5 {{ $done ? 'bg-primary' : 'bg-border' }} transition-colors"></div>
            @endif
            <div class="flex flex-col items-center gap-1.5">
                <div class="h-8 w-8 rounded-full flex items-center justify-center text-xs font-bold transition-all
                    {{ $done    ? 'bg-primary text-primary-foreground' : '' }}
                    {{ $current ? 'bg-primary text-primary-foreground ring-4 ring-primary/20' : '' }}
                    {{ !$done && !$current ? 'bg-muted text-muted-foreground' : '' }}">
                    @if($done)
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg>
                    @else
                    {{ $i + 1 }}
                    @endif
                </div>
                <span class="text-[11px] font-medium {{ $current ? 'text-primary' : 'text-muted-foreground' }} whitespace-nowrap">{{ $label }}</span>
            </div>
        </div>
        @endforeach
    </div>

    {{-- ─── STEP 1: Exam Type ─── --}}
    @if($step === 'exam_type')
    <div class="space-y-4">
        <p class="text-sm text-muted-foreground font-medium uppercase tracking-wider">Select exam type</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
            <button wire:click="selectExamType('neet')" type="button" wire:loading.attr="disabled"
                class="group relative flex flex-col items-start gap-4 rounded-2xl border-2 border-border bg-card p-6 text-left shadow-sm hover:border-primary hover:shadow-md transition-all active:scale-95">
                <div class="h-12 w-12 rounded-xl bg-green-100 flex items-center justify-center text-green-700 text-lg font-bold">N</div>
                <div>
                    <p class="font-display text-xl font-bold text-foreground">NEET</p>
                    <p class="text-sm text-muted-foreground mt-1">Physics · Chemistry · Biology</p>
                    <p class="text-xs text-muted-foreground mt-2">+4 / –1 marking · MCQ</p>
                </div>
                <div class="absolute inset-0 flex items-center justify-center rounded-2xl bg-background/60 hidden" wire:loading wire:target="selectExamType('neet')">
                    <svg class="animate-spin h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                </div>
            </button>

            <button wire:click="selectExamType('jee_main')" type="button" wire:loading.attr="disabled"
                class="group relative flex flex-col items-start gap-4 rounded-2xl border-2 border-border bg-card p-6 text-left shadow-sm hover:border-primary hover:shadow-md transition-all active:scale-95">
                <div class="h-12 w-12 rounded-xl bg-blue-100 flex items-center justify-center text-blue-700 text-lg font-bold">J</div>
                <div>
                    <p class="font-display text-xl font-bold text-foreground">JEE Main</p>
                    <p class="text-sm text-muted-foreground mt-1">Physics · Chemistry · Mathematics</p>
                    <p class="text-xs text-muted-foreground mt-2">+4 / –1 marking · MCQ</p>
                </div>
                <div class="absolute inset-0 flex items-center justify-center rounded-2xl bg-background/60 hidden" wire:loading wire:target="selectExamType('jee_main')">
                    <svg class="animate-spin h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                </div>
            </button>

            <div class="relative flex flex-col items-start gap-4 rounded-2xl border-2 border-dashed border-border bg-muted/30 p-6 opacity-60 cursor-not-allowed">
                <div class="h-12 w-12 rounded-xl bg-orange-100 flex items-center justify-center text-orange-700 text-lg font-bold">C</div>
                <div>
                    <p class="font-display text-xl font-bold text-foreground">CET / KCET</p>
                    <p class="text-sm text-muted-foreground mt-1">Physics · Chemistry · Math/Bio</p>
                    <p class="text-xs text-muted-foreground mt-2">+1 / 0 marking · MCQ</p>
                </div>
                <span class="absolute top-4 right-4 text-[10px] font-bold bg-muted text-muted-foreground px-2 py-0.5 rounded-full uppercase tracking-wider">Soon</span>
            </div>
        </div>
    </div>
    @endif

    {{-- ─── STEP 2: Configure Questions (Subject Tabs) ─── --}}
    @if($step === 'configure')
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <p class="text-sm font-medium text-muted-foreground uppercase tracking-wider">Select chapters &amp; question counts</p>
            <button wire:click="$set('step','exam_type')" type="button" class="text-xs text-muted-foreground hover:text-foreground transition-colors flex items-center gap-1">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
                Change exam type
            </button>
        </div>

        @error('configure')
        <div class="rounded-lg bg-destructive/10 border border-destructive/20 px-4 py-2 text-sm text-destructive">{{ $message }}</div>
        @enderror

        <div class="rounded-xl border border-border bg-card shadow-sm overflow-hidden">
            {{-- Subject Tabs --}}
            <div class="flex border-b border-border">
                @foreach($subjects as $si => $subject)
                @php $st = $subjectTotals[$subject['id']] ?? 0; @endphp
                <button type="button"
                    wire:click="$set('activeSubjectIdx', {{ $si }})"
                    class="flex-1 px-4 py-3 text-sm font-medium border-b-2 transition-colors flex items-center justify-center gap-2
                        {{ $activeSubjectIdx === $si
                            ? 'border-primary text-primary bg-primary/5'
                            : 'border-transparent text-muted-foreground hover:text-foreground hover:bg-muted/30' }}">
                    {{ $subject['name'] }}
                    @if($st > 0)
                    <span class="text-[10px] font-bold bg-primary text-primary-foreground px-1.5 py-0.5 rounded-full">{{ $st }}</span>
                    @endif
                </button>
                @endforeach
            </div>

            {{-- Chapter table for active subject --}}
            @foreach($subjects as $si => $subject)
            @if($activeSubjectIdx === $si)
            <div class="divide-y divide-border/40">
                {{-- Table header --}}
                <div class="grid grid-cols-12 gap-3 px-5 py-2 bg-muted/30 text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">
                    <div class="col-span-7">Chapter</div>
                    <div class="col-span-2 text-center">Available</div>
                    <div class="col-span-3 text-center">Questions to pick</div>
                </div>
                @foreach($subject['chapters'] as $ci => $chapter)
                <div class="grid grid-cols-12 gap-3 items-center px-5 py-3 hover:bg-muted/20 transition-colors">
                    <div class="col-span-7">
                        <p class="text-sm text-foreground leading-snug">{{ $chapter['name'] }}</p>
                    </div>
                    <div class="col-span-2 text-center">
                        <span class="text-xs {{ $chapter['available'] > 0 ? 'text-muted-foreground' : 'text-destructive/60' }}">
                            {{ $chapter['available'] }}
                        </span>
                    </div>
                    <div class="col-span-3 flex items-center justify-center gap-1">
                        @if($chapter['available'] > 0)
                        <button type="button"
                            wire:click="$set('subjects.{{ $si }}.chapters.{{ $ci }}.count', {{ max(0, (int)($chapter['count'] ?? 0) - 1) }})"
                            class="h-7 w-7 rounded border border-border bg-background hover:bg-muted flex items-center justify-center text-muted-foreground hover:text-foreground transition-colors font-bold text-base leading-none">−</button>
                        <input type="number"
                            wire:model.blur="subjects.{{ $si }}.chapters.{{ $ci }}.count"
                            min="0" max="{{ $chapter['available'] }}"
                            class="w-14 text-center rounded border border-input bg-background px-1 py-1.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        <button type="button"
                            wire:click="$set('subjects.{{ $si }}.chapters.{{ $ci }}.count', {{ min($chapter['available'], (int)($chapter['count'] ?? 0) + 1) }})"
                            class="h-7 w-7 rounded border border-border bg-background hover:bg-muted flex items-center justify-center text-muted-foreground hover:text-foreground transition-colors font-bold text-base leading-none">+</button>
                        @else
                        <span class="text-xs text-muted-foreground italic col-span-3">No questions</span>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
            @endif
            @endforeach
        </div>

        {{-- Summary bar --}}
        <div class="rounded-xl border border-border bg-card/95 px-5 py-4 flex items-center justify-between gap-4">
            <div class="flex items-center gap-6 flex-wrap">
                <div>
                    <p class="text-xs text-muted-foreground">Total Questions</p>
                    <p class="text-2xl font-display font-bold">{{ $totalQuestions }}</p>
                </div>
                <div class="h-8 w-px bg-border hidden sm:block"></div>
                <div>
                    <p class="text-xs text-muted-foreground">Duration</p>
                    <p class="text-2xl font-display font-bold text-primary">{{ $totalQuestions }} min</p>
                </div>
                @if($totalQuestions > 0)
                <div class="h-8 w-px bg-border hidden sm:block"></div>
                <div class="hidden sm:block">
                    <p class="text-xs text-muted-foreground">Breakdown</p>
                    <p class="text-xs font-medium text-foreground">
                        @foreach($subjects as $subject)
                        @php $st = $subjectTotals[$subject['id']] ?? 0; @endphp
                        @if($st > 0){{ $subject['name'] }}: {{ $st }}@if(!$loop->last) · @endif@endif
                        @endforeach
                    </p>
                </div>
                @endif
            </div>
            <button wire:click="goToSchedule" type="button"
                class="inline-flex items-center gap-2 rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90 transition-all active:scale-95 shrink-0">
                Continue
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
            </button>
        </div>
    </div>
    @endif

    {{-- ─── STEP 3: Schedule ─── --}}
    @if($step === 'schedule')
    @php
        $examLabels = ['neet' => 'NEET', 'jee_main' => 'JEE Main', 'kcet' => 'CET'];
        $endPreview = $scheduledStart
            ? \Carbon\Carbon::parse($scheduledStart)->addMinutes($totalQuestions)->format('d M Y, h:i A')
            : null;
    @endphp
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-5">
            <div class="rounded-xl border border-border bg-card p-6 shadow-sm space-y-5">
                <h2 class="font-semibold text-sm border-b border-border/50 pb-3 text-muted-foreground uppercase tracking-wider">Test Details</h2>
                <div class="space-y-2">
                    <label class="text-sm font-medium">Test Title</label>
                    <input type="text" wire:model="title"
                        class="w-full rounded-lg border border-input bg-background px-3 py-2.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        placeholder="e.g. NEET Mock Test 1">
                    @error('title') <span class="text-[11px] text-destructive">{{ $message }}</span> @enderror
                </div>
                <div class="space-y-2">
                    <label class="text-sm font-medium">Description <span class="text-muted-foreground font-normal">(optional)</span></label>
                    <textarea wire:model="description" rows="2"
                        class="w-full rounded-lg border border-input bg-background px-3 py-2.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary resize-none"
                        placeholder="Instructions for students..."></textarea>
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-6 shadow-sm space-y-5">
                <h2 class="font-semibold text-sm border-b border-border/50 pb-3 text-muted-foreground uppercase tracking-wider">Schedule</h2>
                <div class="space-y-2">
                    <label class="text-sm font-medium">Start Date &amp; Time</label>
                    <input type="datetime-local" wire:model.live="scheduledStart"
                        class="w-full rounded-lg border border-input bg-background px-3 py-2.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    @error('scheduledStart') <span class="text-[11px] text-destructive">{{ $message }}</span> @enderror
                </div>
                <div class="space-y-2">
                    <label class="text-sm font-medium text-muted-foreground">End Time <span class="font-normal">(auto-calculated)</span></label>
                    <div class="w-full rounded-lg border border-dashed border-border bg-muted/30 px-3 py-2.5 text-sm text-muted-foreground flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        @if($endPreview)
                            {{ $endPreview }}
                            <span class="ml-auto text-xs">({{ $totalQuestions }} min after start)</span>
                        @else
                            Set a start time to see end time
                        @endif
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="button" wire:click="$set('step','configure')"
                    class="inline-flex items-center gap-2 rounded-lg border border-border bg-background px-4 py-2.5 text-sm font-medium hover:bg-muted transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
                    Back
                </button>
                <button type="button" wire:click="createTest" wire:loading.attr="disabled"
                    class="flex-1 inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90 transition-all active:scale-95 disabled:opacity-60">
                    <span wire:loading.remove wire:target="createTest">
                        Create Test &amp; Generate Link
                    </span>
                    <span wire:loading wire:target="createTest" class="flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        Creating...
                    </span>
                </button>
            </div>
        </div>

        {{-- Summary sidebar --}}
        <div class="space-y-4">
            <div class="rounded-xl border border-border bg-card p-5 shadow-sm space-y-4">
                <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Test Summary</p>
                <div class="space-y-3">
                    <div class="flex justify-between text-sm">
                        <span class="text-muted-foreground">Exam</span>
                        <span class="font-semibold">{{ $examLabels[$examType] ?? $examType }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-muted-foreground">Total Questions</span>
                        <span class="font-semibold">{{ $totalQuestions }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-muted-foreground">Duration</span>
                        <span class="font-semibold">{{ $totalQuestions }} min</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-muted-foreground">Total Marks</span>
                        <span class="font-semibold">{{ $totalQuestions * ($examType === 'kcet' ? 1 : 4) }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-muted-foreground">Marking</span>
                        <span class="font-semibold">{{ $examType === 'kcet' ? '+1 / 0' : '+4 / –1' }}</span>
                    </div>
                </div>
                @if($totalQuestions > 0)
                <div class="border-t border-border/50 pt-3 space-y-2">
                    <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Sections</p>
                    @foreach($subjects as $subject)
                    @php $st = $subjectTotals[$subject['id']] ?? 0; @endphp
                    @if($st > 0)
                    <div class="flex justify-between text-sm">
                        <span>{{ $subject['name'] }}</span>
                        <span class="font-semibold text-primary">{{ $st }} Q</span>
                    </div>
                    @endif
                    @endforeach
                </div>
                @endif
            </div>

            <div class="rounded-xl border border-primary/20 bg-primary/5 p-4 text-xs text-muted-foreground space-y-2">
                <p class="font-semibold text-foreground text-sm">What happens next?</p>
                <ul class="space-y-1.5">
                    <li class="flex items-start gap-2"><span class="text-primary mt-0.5">✓</span> Questions randomly picked from each chapter</li>
                    <li class="flex items-start gap-2"><span class="text-primary mt-0.5">✓</span> Test link auto-generated</li>
                    <li class="flex items-start gap-2"><span class="text-primary mt-0.5">✓</span> Share link with students via WhatsApp</li>
                    <li class="flex items-start gap-2"><span class="text-primary mt-0.5">✓</span> NTA-style exam interface opens for students</li>
                </ul>
            </div>
        </div>
    </div>
    @endif

</div>
