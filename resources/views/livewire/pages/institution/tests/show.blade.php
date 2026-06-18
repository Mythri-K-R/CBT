<?php

use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\TestLink;
use App\Models\TestSection;
use App\Models\TestQuestion;
use App\Models\Question;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.institution')] class extends Component {
    public Test $test;

    #[Url]
    public string $tab = 'overview';

    // Link Management
    public ?TestLink $activeLink = null;
    public bool $showGenerateModal = false;
    public string $expiresOption = 'never';
    public string $expiresAt = '';

    // Schedule
    public bool $showScheduleModal = false;
    public string $editStart = '';
    public string $editEnd   = '';

    // Inline question writing
    public ?int $addingToSection = null;
    public string $qText      = '';
    public string $qOptionA   = '';
    public string $qOptionB   = '';
    public string $qOptionC   = '';
    public string $qOptionD   = '';
    public string $qCorrect   = 'A';
    public string $qDiff      = 'medium';
    public string $qExplanation = '';

    public function mount(Test $test): void
    {
        abort_if($test->institution_id !== auth()->user()->institution_id, 403);
        $this->test = $test->load(['template', 'batches']);

        $this->activeLink = TestLink::where('test_id', $test->id)
            ->where('is_active', true)
            ->latest()
            ->first();
    }

    // ── Schedule ─────────────────────────────────────────────────────────────

    public function openScheduleModal(): void
    {
        $this->editStart = $this->test->scheduled_start?->format('Y-m-d\TH:i') ?? '';
        $this->editEnd   = $this->test->scheduled_end?->format('Y-m-d\TH:i') ?? '';
        $this->showScheduleModal = true;
    }

    public function saveSchedule(): void
    {
        $this->validate([
            'editStart' => 'required|date',
            'editEnd'   => 'required|date|after:editStart',
        ]);

        $newStatus = $this->test->status === 'draft' ? 'scheduled' : $this->test->status;

        $this->test->update([
            'scheduled_start' => \Carbon\Carbon::parse($this->editStart),
            'scheduled_end'   => \Carbon\Carbon::parse($this->editEnd),
            'status'          => $newStatus,
        ]);
        $this->test->refresh();
        $this->showScheduleModal = false;
    }

    // ── Approval ─────────────────────────────────────────────────────────────

    public function approveP(): void
    {
        $this->test->update(['is_paper_approved' => true]);
        $this->test->refresh();
    }

    // ── Link management ───────────────────────────────────────────────────────

    public function generateLink(): void
    {
        abort_unless($this->test->is_paper_approved, 403);

        $expires = null;
        if ($this->expiresOption !== 'never' && $this->expiresAt) {
            $expires = \Carbon\Carbon::parse($this->expiresAt);
        } elseif ($this->expiresOption === '24h') {
            $expires = now()->addHours(24);
        } elseif ($this->expiresOption === '7d') {
            $expires = now()->addDays(7);
        }

        $slug = Str::lower(Str::slug($this->test->title).'-'.Str::random(6));

        $link = TestLink::create([
            'test_id'        => $this->test->id,
            'institution_id' => $this->test->institution_id,
            'slug'           => $slug,
            'is_active'      => true,
            'expires_at'     => $expires,
        ]);

        $this->activeLink        = $link;
        $this->showGenerateModal = false;
        $this->tab               = 'links';

        session()->flash('linkGenerated', true);
    }

    public function deactivateLink(int $linkId): void
    {
        $link = TestLink::where('id', $linkId)
            ->where('institution_id', $this->test->institution_id)
            ->first();

        if ($link) {
            $link->update(['is_active' => false]);
            if ($this->activeLink?->id === $linkId) {
                $this->activeLink = null;
            }
        }
    }

    // ── Inline question writing ───────────────────────────────────────────────

    public function startAddQuestion(int $sectionId): void
    {
        $this->addingToSection = $sectionId;
        $this->qText = $this->qOptionA = $this->qOptionB = $this->qOptionC = $this->qOptionD = $this->qExplanation = '';
        $this->qCorrect = 'A';
        $this->qDiff = 'medium';
        $this->resetErrorBag();
    }

    public function cancelAddQuestion(): void
    {
        $this->addingToSection = null;
    }

    public function saveNewQuestion(): void
    {
        $this->validate([
            'qText'    => 'required|string|min:5',
            'qOptionA' => 'required|string|min:1',
            'qOptionB' => 'required|string|min:1',
            'qOptionC' => 'required|string|min:1',
            'qOptionD' => 'required|string|min:1',
            'qCorrect' => 'required|in:A,B,C,D',
        ]);

        $section = TestSection::where('id', $this->addingToSection)
            ->where('test_id', $this->test->id)
            ->firstOrFail();

        $q = Question::create([
            'institution_id' => auth()->user()->institution_id,
            'created_by'     => auth()->id(),
            'exam_type'      => $this->test->exam_type,
            'subject_id'     => $section->subject_id,
            'type'           => 'single_mcq',
            'source_type'    => 'manual',
            'language'       => 'english',
            'status'         => 'active',
            'question_text'  => $this->qText,
            'options'        => ['A' => $this->qOptionA, 'B' => $this->qOptionB, 'C' => $this->qOptionC, 'D' => $this->qOptionD],
            'correct_answer' => $this->qCorrect,
            'difficulty'     => $this->qDiff,
            'explanation'    => $this->qExplanation ?: null,
            'positive_marks' => $section->positive_marks,
            'negative_marks' => $section->negative_marks,
        ]);

        $nextNum = (TestQuestion::where('test_id', $this->test->id)->max('question_number') ?? 0) + 1;

        TestQuestion::create([
            'test_id'         => $this->test->id,
            'section_id'      => $section->id,
            'question_id'     => $q->id,
            'question_number' => $nextNum,
            'positive_marks'  => $section->positive_marks,
            'negative_marks'  => $section->negative_marks,
        ]);

        $totalMarks = TestQuestion::where('test_id', $this->test->id)->sum('positive_marks');
        $this->test->update(['total_marks' => $totalMarks]);
        $this->test->refresh();

        $this->addingToSection = null;
        $this->qText = $this->qOptionA = $this->qOptionB = $this->qOptionC = $this->qOptionD = $this->qExplanation = '';

        session()->flash('qSaved', true);
    }

    public function removeQuestion(int $tqId): void
    {
        TestQuestion::where('id', $tqId)
            ->where('test_id', $this->test->id)
            ->delete();

        // Renumber remaining
        TestQuestion::where('test_id', $this->test->id)
            ->orderBy('question_number')
            ->get()
            ->each(fn($tq, $i) => $tq->update(['question_number' => $i + 1]));

        $totalMarks = TestQuestion::where('test_id', $this->test->id)->sum('positive_marks');
        $this->test->update(['total_marks' => $totalMarks]);
        $this->test->refresh();
    }

    // ── Data ─────────────────────────────────────────────────────────────────

    public function with(): array
    {
        $attempts = TestAttempt::with('student')
            ->where('test_id', $this->test->id)
            ->where('status', 'submitted')
            ->orderByDesc('total_score')
            ->get();

        $total       = $attempts->count();
        $avgScore    = $total ? round($attempts->avg('total_score'), 1) : 0;
        $topScore    = $attempts->max('total_score') ?? 0;
        $avgAccuracy = $total
            ? round($attempts->avg(fn($a) => $a->total_correct / max(1, ($a->total_correct + $a->total_wrong + $a->total_unattempted)) * 100), 1)
            : 0;

        $allLinks = TestLink::where('test_id', $this->test->id)
            ->orderByDesc('created_at')
            ->get();

        $sections = TestSection::with(['testQuestions' => fn($q) => $q->with('question')->orderBy('question_number')])
            ->where('test_id', $this->test->id)
            ->orderBy('display_order')
            ->get();

        return compact('attempts', 'total', 'avgScore', 'topScore', 'avgAccuracy', 'allLinks', 'sections');
    }
}; ?>

<div>
<div class="space-y-6">

    {{-- Page header --}}
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
            <a href="{{ route('institution.tests.answer-key', $test) }}" wire:navigate
               class="h-9 rounded-lg border border-border bg-background px-4 text-sm font-medium hover:bg-muted transition-colors flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/></svg>
                Answer Key
            </a>
            @if($test->is_paper_approved)
            <button wire:click="$set('showGenerateModal', true)"
                    class="h-9 rounded-lg bg-primary text-primary-foreground px-4 text-sm font-medium hover:bg-primary/90 transition-colors flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                Generate Link
            </button>
            @else
            <button wire:click="$set('tab','preview')"
                    class="h-9 rounded-lg border border-warning/40 bg-warning/10 text-warning px-4 text-sm font-medium hover:bg-warning/20 transition-colors flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                Approve Paper First
            </button>
            @endif
        </div>
    </div>

    {{-- Stat Cards --}}
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

    {{-- Tabs --}}
    <div class="border-b border-border flex gap-0 overflow-x-auto">
        @foreach(['overview'=>'Overview','results'=>'Results','questions'=>'Questions','preview'=>'Paper Preview','links'=>'Link Management'] as $key => $label)
        <button wire:click="$set('tab','{{ $key }}')"
                class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors whitespace-nowrap {{ $tab === $key ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}">
            {{ $label }}
            @if($key === 'preview' && !$test->is_paper_approved)
            <span class="ml-1 h-1.5 w-1.5 rounded-full bg-warning inline-block align-middle"></span>
            @endif
        </button>
        @endforeach
    </div>

    {{-- ── OVERVIEW ────────────────────────────────────────────────────────── --}}
    @if($tab === 'overview')

    {{-- Schedule alert when not yet set --}}
    @if(!$test->scheduled_start)
    <div class="rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 flex items-center justify-between gap-4">
        <div class="flex items-start gap-3">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-amber-600 shrink-0 mt-0.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <div>
                <p class="font-semibold text-sm text-amber-800">Exam schedule not set</p>
                <p class="text-xs text-amber-700 mt-0.5">Set the start and end time before generating the test link.</p>
            </div>
        </div>
        <button wire:click="openScheduleModal"
                class="shrink-0 inline-flex items-center gap-2 rounded-lg bg-amber-600 text-white px-4 py-2 text-sm font-semibold hover:bg-amber-700 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
            Set Schedule
        </button>
    </div>
    @endif

    <div class="grid lg:grid-cols-2 gap-6">
        <div class="rounded-xl border border-border bg-card p-5">
            <h3 class="font-semibold mb-4">Test Details</h3>
            <dl class="space-y-3 text-sm">
                @foreach([
                    ['Template',  $test->template->name ?? '—'],
                    ['Exam Type', strtoupper($test->exam_type)],
                    ['Duration',  $test->duration_minutes.' minutes'],
                    ['Total Marks', $test->total_marks ?? '—'],
                    ['Sections',  $sections->count()],
                    ['Questions', $sections->sum(fn($s) => $s->testQuestions->count())],
                ] as [$lbl, $val])
                <div class="flex justify-between">
                    <dt class="text-muted-foreground">{{ $lbl }}</dt>
                    <dd class="font-medium">{{ $val }}</dd>
                </div>
                @endforeach
            </dl>
        </div>

        <div class="rounded-xl border border-border bg-card p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold">Exam Schedule</h3>
                <button wire:click="openScheduleModal"
                        class="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    {{ $test->scheduled_start ? 'Edit' : 'Set' }} Schedule
                </button>
            </div>
            @if($test->scheduled_start)
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-muted-foreground">Start</dt>
                    <dd class="font-medium">{{ $test->scheduled_start->format('d M Y, h:i A') }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-muted-foreground">End</dt>
                    <dd class="font-medium">{{ $test->scheduled_end?->format('d M Y, h:i A') ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-muted-foreground">Duration</dt>
                    <dd class="font-medium">{{ $test->duration_minutes }} minutes</dd>
                </div>
            </dl>
            @else
            <div class="flex flex-col items-center justify-center py-6 text-center gap-3">
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="text-muted-foreground/40"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                <p class="text-sm text-muted-foreground">No schedule set yet.</p>
                <button wire:click="openScheduleModal"
                        class="rounded-lg bg-primary text-primary-foreground px-4 py-2 text-sm font-medium hover:bg-primary/90 transition-colors">
                    Set Schedule
                </button>
            </div>
            @endif
        </div>
    </div>
    @endif

    {{-- ── RESULTS ─────────────────────────────────────────────────────────── --}}
    @if($tab === 'results')
    <div class="rounded-xl border border-border bg-card overflow-hidden">
        <div class="px-4 py-3 border-b border-border bg-muted/30 flex items-center justify-between">
            <h3 class="font-semibold text-sm">Student Rankings</h3>
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

    {{-- ── QUESTIONS ────────────────────────────────────────────────────────── --}}
    @if($tab === 'questions')
    @if(session('qSaved'))
    <div class="rounded-lg bg-success/10 border border-success/30 text-success px-4 py-2.5 text-sm font-medium flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        Question saved successfully.
    </div>
    @endif
    <div class="space-y-4">
        @foreach($sections as $section)
        <div class="rounded-xl border border-border bg-card overflow-hidden">

            {{-- Section header --}}
            <div class="px-4 py-3 bg-muted/30 flex items-center justify-between">
                <div>
                    <span class="font-semibold text-sm">{{ $section->name }}</span>
                    <span class="ml-2 text-xs text-muted-foreground">{{ $section->testQuestions->count() }} question{{ $section->testQuestions->count() !== 1 ? 's' : '' }}</span>
                    <span class="ml-2 text-xs text-muted-foreground">· +{{ $section->positive_marks }} / –{{ $section->negative_marks }}</span>
                </div>
                @if($addingToSection !== $section->id)
                <button wire:click="startAddQuestion({{ $section->id }})"
                        class="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                    Write Question
                </button>
                @endif
            </div>

            {{-- Inline write form --}}
            @if($addingToSection === $section->id)
            <div class="p-5 border-b border-border bg-primary/5 space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-bold text-primary">New Question — {{ $section->name }}</span>
                    <button wire:click="cancelAddQuestion" class="text-xs text-muted-foreground hover:text-foreground transition-colors">✕ Cancel</button>
                </div>

                {{-- Question text --}}
                <div class="space-y-1">
                    <label class="text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                        Question Text
                        <span class="font-normal normal-case ml-1 text-muted-foreground/70">(supports $formula$ for math)</span>
                    </label>
                    <textarea wire:model="qText" rows="5"
                              x-data
                              x-init="$el.style.height = Math.max(120, $el.scrollHeight) + 'px'"
                              @input="$el.style.height = 'auto'; $el.style.height = Math.max(120, $el.scrollHeight) + 'px'"
                              class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                              style="min-height:120px; resize:vertical"
                              placeholder="Type the question here..."></textarea>
                    @error('qText') <span class="text-[11px] text-destructive">{{ $message }}</span> @enderror
                </div>

                {{-- Options --}}
                <div class="grid grid-cols-2 gap-3">
                    @foreach(['A' => 'qOptionA', 'B' => 'qOptionB', 'C' => 'qOptionC', 'D' => 'qOptionD'] as $opt => $prop)
                    <div class="space-y-1">
                        <label class="text-xs font-semibold {{ $qCorrect === $opt ? 'text-success' : 'text-muted-foreground' }}">Option {{ $opt }}{{ $qCorrect === $opt ? ' ✓ (correct)' : '' }}</label>
                        <input type="text" wire:model="{{ $prop }}"
                               class="w-full rounded-lg border {{ $qCorrect === $opt ? 'border-success bg-success/5' : 'border-input bg-background' }} px-3 py-2 text-sm focus:border-primary focus:outline-none transition-colors"
                               placeholder="Option {{ $opt }}">
                        @error($prop) <span class="text-[11px] text-destructive">{{ $message }}</span> @enderror
                    </div>
                    @endforeach
                </div>

                {{-- Correct answer + Difficulty --}}
                <div class="flex items-end gap-6 flex-wrap">
                    <div class="space-y-1.5">
                        <label class="text-xs font-semibold text-muted-foreground uppercase tracking-wide">Correct Answer</label>
                        <div class="flex gap-2">
                            @foreach(['A','B','C','D'] as $opt)
                            <label class="cursor-pointer">
                                <input type="radio" wire:model.live="qCorrect" value="{{ $opt }}" class="sr-only">
                                <div class="h-9 w-9 rounded-full border-2 flex items-center justify-center text-sm font-bold transition-all
                                    {{ $qCorrect === $opt ? 'border-success bg-success text-white shadow-md' : 'border-border text-muted-foreground hover:border-success/40' }}">
                                    {{ $opt }}
                                </div>
                            </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-semibold text-muted-foreground uppercase tracking-wide">Difficulty</label>
                        <select wire:model="qDiff"
                                class="rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none">
                            <option value="easy">Easy</option>
                            <option value="medium">Medium</option>
                            <option value="hard">Tough</option>
                        </select>
                    </div>
                </div>

                {{-- Explanation --}}
                <div class="space-y-1">
                    <label class="text-xs font-semibold text-muted-foreground uppercase tracking-wide">Explanation <span class="font-normal normal-case">(optional)</span></label>
                    <textarea wire:model="qExplanation" rows="2"
                              class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none resize-none"
                              placeholder="Explain why the correct answer is right..."></textarea>
                </div>

                <div class="flex gap-2">
                    <button wire:click="saveNewQuestion" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-lg bg-primary text-primary-foreground px-5 py-2 text-sm font-semibold hover:bg-primary/90 transition-colors disabled:opacity-60">
                        <span wire:loading.remove wire:target="saveNewQuestion">Save Question</span>
                        <span wire:loading wire:target="saveNewQuestion">Saving...</span>
                    </button>
                    <button wire:click="cancelAddQuestion"
                            class="rounded-lg border border-border bg-background px-4 py-2 text-sm font-medium hover:bg-muted transition-colors">
                        Cancel
                    </button>
                </div>
            </div>
            @endif

            {{-- Question list --}}
            <table class="w-full text-sm">
                @if($section->testQuestions->isNotEmpty())
                <thead class="border-b border-border">
                    <tr>
                        <th class="text-left px-4 py-2.5 font-semibold text-muted-foreground">Q#</th>
                        <th class="text-left px-4 py-2.5 font-semibold text-muted-foreground">Question</th>
                        <th class="text-right px-4 py-2.5 font-semibold text-muted-foreground">Marks</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                @endif
                <tbody class="divide-y divide-border">
                    @forelse($section->testQuestions as $tq)
                    <tr class="hover:bg-muted/20 group">
                        <td class="px-4 py-3 font-mono text-muted-foreground text-xs">{{ $tq->question_number }}</td>
                        <td class="px-4 py-3 max-w-md">
                            <div class="line-clamp-2 text-sm">{!! strip_tags($tq->question->question_text ?? '—') !!}</div>
                            @if($tq->question?->source_type === 'manual')
                            <span class="text-[10px] text-primary font-medium">custom</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-xs">
                            <span class="text-green-600">+{{ $tq->positive_marks }}</span> /
                            <span class="text-red-500">–{{ $tq->negative_marks }}</span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button wire:click="removeQuestion({{ $tq->id }})"
                                    wire:confirm="Remove this question from the test?"
                                    class="opacity-0 group-hover:opacity-100 text-muted-foreground hover:text-destructive transition-all p-1 rounded">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                            </button>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-sm text-muted-foreground">
                            No questions yet. Click <strong>Write Question</strong> above to add one.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @endforeach
    </div>
    @endif

    {{-- ── PAPER PREVIEW ──────────────────────────────────────────────────────── --}}
    @if($tab === 'preview')
    <div class="space-y-4">

        {{-- Approval banner --}}
        @if(!$test->is_paper_approved)
        <div class="rounded-xl border border-warning/30 bg-warning/5 px-5 py-4 flex items-center justify-between gap-4">
            <div class="flex items-start gap-3">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-warning mt-0.5 shrink-0"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <div>
                    <p class="font-semibold text-sm">Review &amp; Approve the Paper</p>
                    <p class="text-sm text-muted-foreground mt-0.5">Read through all questions below. Once you approve, the Generate Link button will be enabled.</p>
                </div>
            </div>
            <button wire:click="approveP"
                    wire:confirm="Approve this paper? You'll be able to generate the test link after approval."
                    class="shrink-0 rounded-lg bg-primary text-primary-foreground px-5 py-2 text-sm font-semibold hover:bg-primary/90 transition-colors">
                Approve Paper →
            </button>
        </div>
        @else
        <div class="rounded-xl border border-success/30 bg-success/5 px-5 py-4 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-success shrink-0"><polyline points="20 6 9 17 4 12"/></svg>
                <div>
                    <p class="font-semibold text-sm text-success">Paper Approved</p>
                    <p class="text-sm text-muted-foreground mt-0.5">Ready to share with students.</p>
                </div>
            </div>
            <button wire:click="$set('showGenerateModal', true)"
                    class="shrink-0 rounded-lg bg-primary text-primary-foreground px-5 py-2 text-sm font-semibold hover:bg-primary/90 transition-colors">
                Generate Test Link →
            </button>
        </div>
        @endif

        {{-- Answer key link --}}
        <div class="flex justify-end">
            <a href="{{ route('institution.tests.answer-key', $test) }}" wire:navigate
               class="inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline">
                View Answer Key →
            </a>
        </div>

        {{-- Paper header --}}
        <div class="rounded-xl border border-border bg-card p-6 text-center">
            <h2 class="text-xl font-bold">{{ $test->title }}</h2>
            <p class="text-muted-foreground text-sm mt-1">
                {{ strtoupper(str_replace('_',' ',$test->exam_type)) }} ·
                Duration: {{ $test->duration_minutes }} minutes ·
                Total Marks: {{ $test->total_marks ?? '—' }}
            </p>
        </div>

        {{-- Sections --}}
        @foreach($sections as $section)
        <div class="rounded-xl border border-border bg-card overflow-hidden">
            <div class="px-5 py-3 border-b border-border bg-muted/20 flex items-center justify-between">
                <div class="font-semibold">{{ $section->name }}</div>
                <div class="text-xs text-muted-foreground">
                    {{ $section->testQuestions->count() }} questions ·
                    +{{ $section->positive_marks }} /
                    –{{ $section->negative_marks }}
                </div>
            </div>
            <div class="divide-y divide-border/40">
                @forelse($section->testQuestions->sortBy('question_number') as $tq)
                @php $q = $tq->question; @endphp
                @if($q)
                <div class="p-5 hover:bg-muted/20 transition-colors">
                    <div class="flex gap-3">
                        <span class="font-bold text-primary shrink-0 w-8">{{ $tq->question_number }}.</span>
                        <div class="flex-1">
                            <div class="text-sm leading-relaxed mb-3">{!! $q->question_text !!}</div>
                            @if($q->options)
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 text-sm">
                                @foreach($q->options as $key => $text)
                                <div class="flex items-start gap-2 text-muted-foreground">
                                    <span class="font-semibold text-foreground shrink-0">({{ $key }})</span>
                                    <span>{{ $text }}</span>
                                </div>
                                @endforeach
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
                @endif
                @empty
                <div class="px-5 py-6 text-center text-sm text-muted-foreground">No questions in this section.</div>
                @endforelse
            </div>
        </div>
        @endforeach
    </div>
    @endif

    {{-- ── LINK MANAGEMENT ─────────────────────────────────────────────────── --}}
    @if($tab === 'links')
    <div class="space-y-4">

        @if(session('linkGenerated'))
        <div class="rounded-lg bg-success/10 border border-success/30 text-success px-4 py-3 text-sm font-medium flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            Test link generated successfully!
        </div>
        @endif

        @if(!$test->is_paper_approved && $allLinks->isEmpty())
        <div class="rounded-xl border border-dashed border-border bg-card p-12 text-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="mx-auto text-warning mb-3"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <h3 class="font-semibold text-muted-foreground">Paper Not Yet Approved</h3>
            <p class="text-sm text-muted-foreground mt-1 mb-4">Please review and approve the paper before generating a test link.</p>
            <button wire:click="$set('tab','preview')"
                    class="rounded-lg bg-primary text-primary-foreground px-4 py-2 text-sm font-medium hover:bg-primary/90 transition-colors">
                Review Paper →
            </button>
        </div>
        @else
        @forelse($allLinks as $link)
        @php
            $url     = route('student.test.landing', ['slug' => $link->slug]);
            $waText  = urlencode("Join the test: {$test->title}\n\nClick here: {$url}");
            $isValid = $link->is_active && (!$link->expires_at || $link->expires_at->isFuture());
        @endphp
        <div class="rounded-xl border border-border bg-card overflow-hidden">
            <div class="px-5 py-4 border-b border-border bg-muted/20 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold
                        {{ $isValid ? 'bg-success/10 text-success' : 'bg-muted text-muted-foreground' }}">
                        @if($isValid)<span class="h-1.5 w-1.5 rounded-full bg-success mr-1 animate-pulse inline-block"></span>@endif
                        {{ $isValid ? 'Active' : 'Inactive' }}
                    </span>
                    <span class="text-xs text-muted-foreground">Created {{ $link->created_at->diffForHumans() }}</span>
                    @if($link->expires_at)
                    <span class="text-xs text-muted-foreground">· Expires {{ $link->expires_at->format('d M Y, h:i A') }}</span>
                    @endif
                </div>
                @if($link->is_active)
                <button wire:click="deactivateLink({{ $link->id }})"
                        wire:confirm="Deactivate this link? Students will no longer be able to access the test via this link."
                        class="text-xs font-medium text-destructive hover:underline">
                    Deactivate
                </button>
                @endif
            </div>

            <div class="p-5 space-y-4">
                <div>
                    <label class="text-xs font-semibold text-muted-foreground uppercase tracking-wide">Test Link URL</label>
                    <div class="mt-1.5 flex gap-2">
                        <input type="text" readonly value="{{ $url }}"
                               class="flex-1 rounded-lg border border-border bg-muted/30 px-3 py-2 text-sm font-mono text-foreground"
                               onclick="this.select()">
                        <button onclick="navigator.clipboard.writeText('{{ $url }}').then(() => { this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000); })"
                                class="shrink-0 rounded-lg border border-border bg-background hover:bg-muted px-3 py-2 text-sm font-medium transition-colors">
                            Copy
                        </button>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <a href="https://wa.me/?text={{ $waText }}" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-white transition-opacity hover:opacity-90"
                       style="background:#25D366;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                        Share on WhatsApp
                    </a>
                    <button onclick="navigator.clipboard.writeText('{{ $url }}')"
                            class="inline-flex items-center gap-2 rounded-lg border border-border bg-background hover:bg-muted px-3 py-2 text-sm font-medium transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                        Copy Link
                    </button>
                </div>

                <div class="grid grid-cols-3 gap-3 pt-2 border-t border-border">
                    @foreach([['Clicks',$link->total_clicks??0,'text-muted-foreground'],['Started',$link->total_starts??0,'text-info'],['Completed',$link->total_completions??0,'text-success']] as [$lbl,$val,$clr])
                    <div class="text-center">
                        <div class="text-2xl font-bold {{ $clr }}">{{ $val }}</div>
                        <div class="text-xs text-muted-foreground mt-0.5">{{ $lbl }}</div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @empty
        <div class="rounded-xl border border-dashed border-border bg-card p-12 text-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="mx-auto text-muted-foreground mb-3"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
            <h3 class="font-semibold text-muted-foreground">No test links yet</h3>
            <p class="text-sm text-muted-foreground mt-1 mb-4">Generate a link to share this test with your students.</p>
            <button wire:click="$set('showGenerateModal', true)"
                    class="rounded-lg bg-primary text-primary-foreground px-4 py-2 text-sm font-medium hover:bg-primary/90 transition-colors">
                Generate Test Link
            </button>
        </div>
        @endforelse
        @endif
    </div>
    @endif

</div>{{-- /space-y-6 --}}

{{-- ── SCHEDULE MODAL ──────────────────────────────────────────────────────── --}}
@if($showScheduleModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
    <div class="bg-card border border-border rounded-xl shadow-xl w-full max-w-md">
        <div class="flex items-center justify-between px-5 py-4 border-b border-border">
            <h2 class="font-semibold text-base">Set Exam Schedule</h2>
            <button wire:click="$set('showScheduleModal', false)" class="h-8 w-8 rounded-lg hover:bg-muted flex items-center justify-center text-muted-foreground transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="p-5 space-y-4">
            <div class="p-3 bg-muted/40 rounded-lg text-sm text-muted-foreground">
                <p class="font-medium text-foreground">{{ $test->title }}</p>
                <p class="mt-0.5">Duration: {{ $test->duration_minutes }} minutes</p>
            </div>
            <div class="space-y-2">
                <label class="block text-sm font-medium">Start Date &amp; Time</label>
                <input type="datetime-local" wire:model.live="editStart"
                       class="w-full rounded-lg border border-input bg-background px-3 py-2.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                @error('editStart') <span class="text-[11px] text-destructive">{{ $message }}</span> @enderror
            </div>
            <div class="space-y-2">
                <label class="block text-sm font-medium">End Date &amp; Time</label>
                <input type="datetime-local" wire:model.live="editEnd"
                       class="w-full rounded-lg border border-input bg-background px-3 py-2.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                @error('editEnd') <span class="text-[11px] text-destructive">{{ $message }}</span> @enderror
            </div>
            @if($editStart && $editEnd)
            @php
                try {
                    $s = \Carbon\Carbon::parse($editStart);
                    $e = \Carbon\Carbon::parse($editEnd);
                    $diffMins = $s->diffInMinutes($e, false);
                } catch (\Exception $ex) { $diffMins = 0; }
            @endphp
            @if($diffMins > 0)
            <div class="text-xs text-muted-foreground bg-muted/30 rounded-lg px-3 py-2">
                Window: {{ $diffMins }} minutes
            </div>
            @endif
            @endif
            <div class="flex gap-3 pt-2">
                <button wire:click="$set('showScheduleModal', false)"
                        class="flex-1 rounded-lg border border-border bg-background hover:bg-muted px-4 py-2 text-sm font-medium transition-colors">
                    Cancel
                </button>
                <button wire:click="saveSchedule" wire:loading.attr="disabled"
                        class="flex-1 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 px-4 py-2 text-sm font-medium transition-colors disabled:opacity-60">
                    <span wire:loading.remove wire:target="saveSchedule">Save Schedule</span>
                    <span wire:loading wire:target="saveSchedule">Saving...</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ── GENERATE LINK MODAL ─────────────────────────────────────────────────── --}}
@if($showGenerateModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
    <div class="bg-card border border-border rounded-xl shadow-xl w-full max-w-md">
        <div class="flex items-center justify-between px-5 py-4 border-b border-border">
            <h2 class="font-semibold text-base">Generate Test Link</h2>
            <button wire:click="$set('showGenerateModal', false)" class="h-8 w-8 rounded-lg hover:bg-muted flex items-center justify-center text-muted-foreground transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="p-5 space-y-4">
            <div class="p-3 bg-muted/40 rounded-lg text-sm text-muted-foreground">
                <p class="font-medium text-foreground mb-1">{{ $test->title }}</p>
                <p>{{ strtoupper($test->exam_type) }} · {{ $test->duration_minutes }} min</p>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1.5">Link Expiry</label>
                <select wire:model="expiresOption" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm">
                    <option value="never">Never expires</option>
                    <option value="24h">Expires in 24 hours</option>
                    <option value="7d">Expires in 7 days</option>
                    <option value="custom">Custom date &amp; time</option>
                </select>
            </div>

            @if($expiresOption === 'custom')
            <div>
                <label class="block text-sm font-medium mb-1.5">Expiry Date &amp; Time</label>
                <input wire:model="expiresAt" type="datetime-local" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm">
            </div>
            @endif

            <div class="flex gap-3 pt-2">
                <button wire:click="$set('showGenerateModal', false)"
                        class="flex-1 rounded-lg border border-border bg-background hover:bg-muted px-4 py-2 text-sm font-medium transition-colors">
                    Cancel
                </button>
                <button wire:click="generateLink"
                        class="flex-1 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 px-4 py-2 text-sm font-medium transition-colors">
                    Generate Link →
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>
