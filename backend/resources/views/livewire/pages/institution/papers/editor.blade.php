<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\Test;
use App\Models\TestSection;
use App\Models\TestQuestion;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Chapter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.institution')] class extends Component {
    public Test $paper;

    // Navigation state
    public string $rightPanel  = 'bank';     // bank | write | edit
    public ?int   $targetSectionId = null;   // section we're adding to

    // Bank filters
    public string $bankSearch    = '';
    public string $bankSubject   = '';
    public string $bankChapter   = '';
    public string $bankDiff      = '';

    // Question write / edit form
    public string  $qText         = '';
    public string  $qOptionA      = '';
    public string  $qOptionB      = '';
    public string  $qOptionC      = '';
    public string  $qOptionD      = '';
    public string  $qCorrect      = 'A';
    public string  $qExplanation  = '';
    public string  $qSubjectId    = '';
    public string  $qChapterId    = '';
    public string  $qDifficulty   = 'medium';
    public ?int    $editingTqId   = null;     // TestQuestion id being edited

    // Title
    public string $paperTitle   = '';
    public bool   $editingTitle = false;

    // Schedule
    public bool   $showSchedule    = false;
    public string $scheduleStart   = '';
    public string $scheduleEnd     = '';

    public function mount(Test $paper): void
    {
        abort_if($paper->institution_id !== Auth::user()->institution_id, 403);
        $this->paper      = $paper;
        $this->paperTitle = $paper->title;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function nextQuestionNumber(): int
    {
        $max = TestQuestion::where('test_id', $this->paper->id)->max('question_number');
        return ($max ?? 0) + 1;
    }

    private function paperQuestionIds(): array
    {
        return TestQuestion::where('test_id', $this->paper->id)->pluck('question_id')->toArray();
    }

    private function clearForm(): void
    {
        $this->qText = $this->qOptionA = $this->qOptionB = $this->qOptionC = $this->qOptionD = $this->qExplanation = '';
        $this->qCorrect = 'A';
        $this->qSubjectId = $this->qChapterId = '';
        $this->qDifficulty = 'medium';
        $this->editingTqId = null;
    }

    // ── Title ─────────────────────────────────────────────────────────────────

    public function saveTitle(): void
    {
        $this->validate(['paperTitle' => 'required|string|max:255']);
        $this->paper->update(['title' => $this->paperTitle]);
        $this->editingTitle = false;
    }

    // ── Section management ───────────────────────────────────────────────────

    public function addSection(): void
    {
        $count = TestSection::where('test_id', $this->paper->id)->count();
        TestSection::create([
            'test_id'        => $this->paper->id,
            'name'           => 'Section ' . ($count + 1),
            'question_count' => 0,
            'positive_marks' => 4,
            'negative_marks' => 1,
            'display_order'  => $count + 1,
        ]);
    }

    public function renameSection(int $sectionId, string $name): void
    {
        TestSection::where('id', $sectionId)
            ->where('test_id', $this->paper->id)
            ->update(['name' => trim($name) ?: 'Section']);
    }

    public function deleteSection(int $sectionId): void
    {
        TestSection::where('id', $sectionId)
            ->where('test_id', $this->paper->id)
            ->delete();
        $this->renumberAll();
    }

    // ── Bank panel ───────────────────────────────────────────────────────────

    public function openBank(int $sectionId): void
    {
        $this->targetSectionId = $sectionId;
        $this->rightPanel      = 'bank';
        $this->clearForm();
    }

    public function openWrite(int $sectionId): void
    {
        $this->targetSectionId = $sectionId;
        $this->rightPanel      = 'write';
        $this->clearForm();
    }

    public function addFromBank(int $questionId): void
    {
        if (!$this->targetSectionId) return;

        $alreadyIn = TestQuestion::where('test_id', $this->paper->id)
            ->where('question_id', $questionId)->exists();
        if ($alreadyIn) return;

        $section = TestSection::where('id', $this->targetSectionId)
            ->where('test_id', $this->paper->id)->first();
        if (!$section) return;

        TestQuestion::create([
            'test_id'         => $this->paper->id,
            'section_id'      => $section->id,
            'question_id'     => $questionId,
            'question_number' => $this->nextQuestionNumber(),
            'positive_marks'  => $section->positive_marks,
            'negative_marks'  => $section->negative_marks,
        ]);

        $this->updatePaperStats();
    }

    // ── Write / Edit question ────────────────────────────────────────────────

    public function saveQuestion(): void
    {
        $this->validate([
            'qText'    => 'required|string|min:5',
            'qOptionA' => 'required|string',
            'qOptionB' => 'required|string',
            'qOptionC' => 'required|string',
            'qOptionD' => 'required|string',
            'qCorrect' => 'required|in:A,B,C,D',
        ]);

        $options = ['A' => $this->qOptionA, 'B' => $this->qOptionB,
                    'C' => $this->qOptionC, 'D' => $this->qOptionD];

        $institutionId = Auth::user()->institution_id;

        if ($this->editingTqId) {
            // Edit mode: update or clone the underlying question
            $tq       = TestQuestion::with('question')->find($this->editingTqId);
            $question = $tq?->question;

            if ($question && $question->institution_id === $institutionId) {
                // Own question — edit in place
                $question->update([
                    'question_text'  => $this->qText,
                    'options'        => $options,
                    'correct_answer' => $this->qCorrect,
                    'explanation'    => $this->qExplanation ?: null,
                    'difficulty'     => $this->qDifficulty,
                    'subject_id'     => $this->qSubjectId ?: null,
                    'chapter_id'     => $this->qChapterId ?: null,
                ]);
            } else {
                // Shared/system question — clone it
                $clone = Question::create([
                    'institution_id'       => $institutionId,
                    'created_by'           => Auth::id(),
                    'exam_type'            => $this->paper->exam_type,
                    'question_text'        => $this->qText,
                    'options'              => $options,
                    'correct_answer'       => $this->qCorrect,
                    'explanation'          => $this->qExplanation ?: null,
                    'difficulty'           => $this->qDifficulty,
                    'subject_id'           => $this->qSubjectId ?: null,
                    'chapter_id'           => $this->qChapterId ?: null,
                    'type'                 => 'single_mcq',
                    'status'               => 'active',
                    'original_question_id' => $question?->id,
                ]);
                $tq?->update(['question_id' => $clone->id]);
            }

            $this->rightPanel  = 'bank';
            $this->editingTqId = null;
        } else {
            // Write new
            if (!$this->targetSectionId) return;
            $section = TestSection::where('id', $this->targetSectionId)
                ->where('test_id', $this->paper->id)->first();
            if (!$section) return;

            $question = Question::create([
                'institution_id' => $institutionId,
                'created_by'     => Auth::id(),
                'exam_type'      => $this->paper->exam_type,
                'question_text'  => $this->qText,
                'options'        => $options,
                'correct_answer' => $this->qCorrect,
                'explanation'    => $this->qExplanation ?: null,
                'difficulty'     => $this->qDifficulty,
                'subject_id'     => $this->qSubjectId ?: null,
                'chapter_id'     => $this->qChapterId ?: null,
                'type'           => 'mcq',
                'status'         => 'active',
            ]);

            TestQuestion::create([
                'test_id'         => $this->paper->id,
                'section_id'      => $section->id,
                'question_id'     => $question->id,
                'question_number' => $this->nextQuestionNumber(),
                'positive_marks'  => $section->positive_marks,
                'negative_marks'  => $section->negative_marks,
            ]);

            $this->updatePaperStats();
        }

        $this->clearForm();
    }

    // ── Edit existing question ────────────────────────────────────────────────

    public function startEdit(int $tqId): void
    {
        $tq       = TestQuestion::with('question.subject', 'question.chapter')->find($tqId);
        $question = $tq?->question;
        if (!$question) return;

        $opts = is_array($question->options) ? $question->options : (json_decode($question->options ?? '{}', true) ?? []);

        $this->editingTqId  = $tqId;
        $this->qText        = $question->question_text ?? '';
        $this->qOptionA     = $opts['A'] ?? '';
        $this->qOptionB     = $opts['B'] ?? '';
        $this->qOptionC     = $opts['C'] ?? '';
        $this->qOptionD     = $opts['D'] ?? '';
        $this->qCorrect     = $question->correct_answer ?? 'A';
        $this->qExplanation = $question->explanation ?? '';
        $this->qDifficulty  = $question->difficulty ?? 'medium';
        $this->qSubjectId   = (string)($question->subject_id ?? '');
        $this->qChapterId   = (string)($question->chapter_id ?? '');
        $this->rightPanel   = 'edit';
    }

    // ── Remove question from paper ────────────────────────────────────────────

    public function removeQuestion(int $tqId): void
    {
        TestQuestion::where('id', $tqId)
            ->where('test_id', $this->paper->id)
            ->delete();
        $this->renumberAll();
        $this->updatePaperStats();
    }

    // ── Reorder ───────────────────────────────────────────────────────────────

    public function moveUp(int $tqId): void
    {
        $tq   = TestQuestion::where('id', $tqId)->where('test_id', $this->paper->id)->first();
        $prev = TestQuestion::where('test_id', $this->paper->id)
            ->where('question_number', '<', $tq->question_number)
            ->orderByDesc('question_number')->first();
        if ($tq && $prev) {
            [$tq->question_number, $prev->question_number] = [$prev->question_number, $tq->question_number];
            $tq->save(); $prev->save();
        }
    }

    public function moveDown(int $tqId): void
    {
        $tq   = TestQuestion::where('id', $tqId)->where('test_id', $this->paper->id)->first();
        $next = TestQuestion::where('test_id', $this->paper->id)
            ->where('question_number', '>', $tq->question_number)
            ->orderBy('question_number')->first();
        if ($tq && $next) {
            [$tq->question_number, $next->question_number] = [$next->question_number, $tq->question_number];
            $tq->save(); $next->save();
        }
    }

    private function renumberAll(): void
    {
        $tqs = TestQuestion::where('test_id', $this->paper->id)
            ->orderBy('question_number')->get();
        foreach ($tqs as $i => $tq) {
            $tq->update(['question_number' => $i + 1]);
        }
    }

    // ── Publish / Schedule ────────────────────────────────────────────────────

    public function schedulePaper(): void
    {
        $this->validate([
            'scheduleStart' => 'required|date|after:now',
            'scheduleEnd'   => 'required|date|after:scheduleStart',
        ]);

        $this->paper->update([
            'status'          => 'scheduled',
            'scheduled_start' => $this->scheduleStart,
            'scheduled_end'   => $this->scheduleEnd,
        ]);

        // Create TestLink if none
        if (!$this->paper->links()->exists()) {
            $examLabel = ['neet' => 'neet', 'jee_main' => 'jee', 'kcet' => 'kcet'][$this->paper->exam_type] ?? 'test';
            $this->paper->links()->create([
                'institution_id' => Auth::user()->institution_id,
                'slug'           => $examLabel . '-' . strtolower(\Illuminate\Support\Str::random(8)),
                'is_active'      => true,
                'expires_at'     => $this->scheduleEnd,
            ]);
        }

        $this->showSchedule = false;
        $this->paper->refresh();
    }

    // ── Stats update ──────────────────────────────────────────────────────────

    private function updatePaperStats(): void
    {
        $count = TestQuestion::where('test_id', $this->paper->id)->count();
        $this->paper->update(['total_questions' => $count]);
        $this->paper->refresh();
    }

    // ── Data for template ────────────────────────────────────────────────────

    public function with(): array
    {
        $sections = TestSection::with(['testQuestions' => function ($q) {
            $q->with('question.chapter')->orderBy('question_number');
        }])->where('test_id', $this->paper->id)
          ->orderBy('display_order')
          ->get();

        $inPaper = $this->paperQuestionIds();

        $bankQuestions = Question::where('exam_type', $this->paper->exam_type)
            ->where('status', 'active')
            ->whereNotIn('id', $inPaper)
            ->when($this->bankSearch, fn($q) => $q->where('question_text', 'like', "%{$this->bankSearch}%"))
            ->when($this->bankSubject, fn($q) => $q->where('subject_id', $this->bankSubject))
            ->when($this->bankChapter, fn($q) => $q->where('chapter_id', $this->bankChapter))
            ->when($this->bankDiff,    fn($q) => $q->where('difficulty', $this->bankDiff))
            ->with('chapter')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        $subjects = Subject::where('exam_type', $this->paper->exam_type)
            ->where('is_active', true)->orderBy('display_order')->get();

        $chapters = $this->bankSubject
            ? Chapter::where('subject_id', $this->bankSubject)->where('is_active', true)->orderBy('display_order')->get()
            : collect();

        $formChapters = $this->qSubjectId
            ? Chapter::where('subject_id', $this->qSubjectId)->where('is_active', true)->orderBy('display_order')->get()
            : collect();

        $totalInPaper = count($inPaper);

        return compact('sections', 'bankQuestions', 'subjects', 'chapters', 'formChapters', 'totalInPaper');
    }
}; ?>

@push('styles')
<style>
    .editor-shell { display: flex; flex-direction: column; height: 100%; }
    .editor-body  { display: flex; flex: 1; overflow: hidden; min-height: 0; }
    .q-list-pane  { flex: 1; overflow-y: auto; padding: 20px; min-width: 0; }
    .right-pane   { width: 380px; flex-shrink: 0; border-left: 1px solid hsl(var(--border)); display: flex; flex-direction: column; overflow: hidden; background: hsl(var(--card)); }
    .q-item       { border: 1px solid hsl(var(--border)); border-radius: 8px; padding: 12px 14px; background: hsl(var(--background)); margin-bottom: 8px; display: flex; gap: 12px; align-items: flex-start; }
    .q-num        { min-width: 28px; height: 28px; background: hsl(var(--primary)); color: hsl(var(--primary-foreground)); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0; }
    .diff-badge   { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; }
    .diff-easy    { background: #dcfce7; color: #15803d; }
    .diff-medium  { background: #fef9c3; color: #b45309; }
    .diff-hard    { background: #fee2e2; color: #b91c1c; }
    .pane-scroll  { overflow-y: auto; flex: 1; padding: 16px; }
    @media (max-width: 900px) {
        .editor-body  { flex-direction: column; }
        .right-pane   { width: 100%; border-left: none; border-top: 1px solid hsl(var(--border)); max-height: 50vh; }
    }
</style>
@endpush

<div class="editor-shell">

    {{-- ── HEADER ─────────────────────────────────────────────────────────── --}}
    <div class="border-b border-border bg-card px-5 py-3 flex items-center gap-3 flex-shrink-0">
        <a href="{{ route('institution.papers.list', $paper->exam_type) }}" wire:navigate
           class="h-8 w-8 rounded-lg border border-border flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors flex-shrink-0">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </a>

        {{-- Editable title --}}
        <div class="flex-1 min-w-0">
            @if($editingTitle)
            <form wire:submit="saveTitle" class="flex items-center gap-2">
                <input type="text" wire:model="paperTitle"
                       class="border border-primary rounded-md px-3 py-1.5 text-sm font-semibold w-full max-w-sm focus:outline-none focus:ring-1 focus:ring-primary">
                <button type="submit" class="px-3 py-1.5 bg-primary text-primary-foreground text-xs font-semibold rounded-md">Save</button>
                <button type="button" wire:click="$set('editingTitle', false)" class="px-3 py-1.5 border border-border text-xs font-semibold rounded-md hover:bg-muted">Cancel</button>
            </form>
            @else
            <div class="flex items-center gap-2">
                <h1 class="font-display font-bold text-lg truncate">{{ $paper->title }}</h1>
                <button wire:click="$set('editingTitle', true)"
                        class="text-muted-foreground hover:text-foreground transition-colors flex-shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <span class="text-xs text-muted-foreground border border-border rounded px-2 py-0.5">{{ $totalInPaper }} Q</span>
                @php
                    $cfg = match($paper->status) {
                        'draft'     => 'bg-warning/10 text-warning',
                        'scheduled' => 'bg-info/10 text-info',
                        'live'      => 'bg-success/10 text-success',
                        default     => 'bg-muted text-muted-foreground',
                    };
                @endphp
                <span class="text-xs font-semibold px-2 py-0.5 rounded {{ $cfg }}">{{ ucfirst($paper->status) }}</span>
            </div>
            @endif
        </div>

        {{-- Actions --}}
        <div class="flex items-center gap-2 flex-shrink-0">
            <button wire:click="addSection"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-xs font-semibold hover:bg-muted transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                Add Section
            </button>
            @if($paper->status === 'draft')
            <button wire:click="$set('showSchedule', true)"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-success px-3 py-1.5 text-xs font-semibold text-white hover:bg-success/90 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Schedule as Test
            </button>
            @else
            <a href="{{ route('institution.tests.show', $paper) }}" wire:navigate
               class="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-xs font-semibold hover:bg-muted transition-colors">
                View Test
            </a>
            @endif
        </div>
    </div>

    {{-- ── BODY ────────────────────────────────────────────────────────────── --}}
    <div class="editor-body">

        {{-- LEFT: Paper structure --}}
        <div class="q-list-pane">
            @if($sections->isEmpty())
            <div class="text-center py-20 text-muted-foreground">
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="mx-auto mb-3 opacity-30"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                <p class="text-sm">No sections yet. Click <strong>Add Section</strong> to start.</p>
            </div>
            @endif

            @foreach($sections as $section)
            @php $sectionQs = $section->testQuestions; @endphp
            <div class="mb-8">
                {{-- Section header --}}
                <div class="flex items-center gap-3 mb-3 group/sec">
                    <div class="h-1 w-1 rounded-full bg-primary flex-shrink-0"></div>
                    <span x-data="{ editing: false, name: '{{ addslashes($section->name) }}' }">
                        <span x-show="!editing"
                              @dblclick="editing = true"
                              class="font-bold text-base cursor-pointer hover:text-primary transition-colors"
                              title="Double-click to rename">{{ $section->name }}</span>
                        <input x-show="editing" x-ref="inp"
                               x-model="name"
                               @keydown.enter="editing = false; $wire.renameSection({{ $section->id }}, name)"
                               @keydown.escape="editing = false"
                               @blur="editing = false; $wire.renameSection({{ $section->id }}, name)"
                               x-effect="if (editing) $nextTick(() => $refs.inp.focus())"
                               class="border border-primary rounded px-2 py-0.5 text-sm font-bold w-40 focus:outline-none">
                    </span>
                    <span class="text-xs text-muted-foreground">{{ $sectionQs->count() }} questions</span>
                    <button wire:click="deleteSection({{ $section->id }})"
                            wire:confirm="Delete this section and all its questions?"
                            class="ml-auto opacity-0 group-hover/sec:opacity-100 transition-opacity text-muted-foreground hover:text-destructive">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                    </button>
                </div>

                {{-- Questions --}}
                @forelse($sectionQs as $tq)
                @php
                    $q     = $tq->question;
                    $dBadge = match($q?->difficulty ?? 'medium') { 'easy' => 'diff-easy', 'hard' => 'diff-hard', default => 'diff-medium' };
                @endphp
                <div class="q-item group/q">
                    <div class="q-num">{{ $tq->question_number }}</div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm leading-relaxed line-clamp-2">{{ $q?->question_text ?? '(Question deleted)' }}</p>
                        <div class="flex items-center gap-2 mt-2 flex-wrap">
                            @if($q?->chapter) <span class="text-xs text-muted-foreground">{{ $q->chapter->name }}</span> @endif
                            <span class="diff-badge {{ $dBadge }}">{{ ucfirst($q?->difficulty ?? 'medium') }}</span>
                            <span class="text-xs text-muted-foreground">+{{ $tq->positive_marks }} / –{{ $tq->negative_marks }}</span>
                            @if($q && $q->institution_id === Auth::user()->institution_id)
                            <span class="text-xs text-primary font-medium">Custom</span>
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-col gap-1 opacity-0 group-hover/q:opacity-100 transition-opacity flex-shrink-0">
                        <button wire:click="startEdit({{ $tq->id }})" title="Edit question"
                                class="h-6 w-6 rounded flex items-center justify-center text-muted-foreground hover:text-primary hover:bg-primary/10 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <button wire:click="moveUp({{ $tq->id }})" title="Move up"
                                class="h-6 w-6 rounded flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m18 15-6-6-6 6"/></svg>
                        </button>
                        <button wire:click="moveDown({{ $tq->id }})" title="Move down"
                                class="h-6 w-6 rounded flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <button wire:click="removeQuestion({{ $tq->id }})" title="Remove from paper"
                                class="h-6 w-6 rounded flex items-center justify-center text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                </div>
                @empty
                <div class="rounded-lg border border-dashed border-border px-4 py-5 text-center text-sm text-muted-foreground mb-3">
                    No questions yet in this section.
                </div>
                @endforelse

                {{-- Section action buttons --}}
                <div class="flex gap-2 mt-2">
                    <button wire:click="openBank({{ $section->id }})"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-xs font-semibold hover:bg-primary hover:text-primary-foreground hover:border-primary transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                        Add from Bank
                    </button>
                    <button wire:click="openWrite({{ $section->id }})"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-xs font-semibold hover:bg-muted transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                        Write Question
                    </button>
                </div>
            </div>
            @endforeach
        </div>

        {{-- RIGHT: Bank / Write / Edit panel --}}
        <div class="right-pane">

            {{-- Panel tabs --}}
            <div class="flex border-b border-border flex-shrink-0">
                <button wire:click="$set('rightPanel', 'bank')"
                        class="flex-1 py-2.5 text-xs font-semibold border-b-2 transition-colors {{ $rightPanel === 'bank' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}">
                    Question Bank
                </button>
                <button wire:click="$set('rightPanel', 'write')"
                        class="flex-1 py-2.5 text-xs font-semibold border-b-2 transition-colors {{ in_array($rightPanel, ['write','edit']) ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}">
                    {{ $rightPanel === 'edit' ? 'Edit Question' : 'Write Question' }}
                </button>
            </div>

            {{-- Target section indicator --}}
            @if($targetSectionId)
            @php $tSec = $sections->firstWhere('id', $targetSectionId); @endphp
            @if($tSec)
            <div class="px-4 py-2 bg-primary/5 border-b border-border/50 text-xs font-medium text-primary flex-shrink-0">
                Adding to: {{ $tSec->name }}
            </div>
            @endif
            @endif

            {{-- ── BANK PANEL ── --}}
            @if($rightPanel === 'bank')
            <div class="pane-scroll">

                {{-- Filters --}}
                <div class="space-y-2 mb-4">
                    <input wire:model.live.debounce.400ms="bankSearch"
                           type="text" placeholder="Search questions..."
                           class="w-full rounded-lg border border-input bg-background px-3 py-2 text-xs focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    <div class="grid grid-cols-3 gap-1.5">
                        <select wire:model.live="bankSubject"
                                class="rounded border border-input bg-background px-2 py-1.5 text-xs focus:border-primary focus:outline-none">
                            <option value="">All Subjects</option>
                            @foreach($subjects as $sub)
                            <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                            @endforeach
                        </select>
                        <select wire:model.live="bankChapter"
                                class="rounded border border-input bg-background px-2 py-1.5 text-xs focus:border-primary focus:outline-none" {{ $chapters->isEmpty() ? 'disabled' : '' }}>
                            <option value="">All Chapters</option>
                            @foreach($chapters as $ch)
                            <option value="{{ $ch->id }}">{{ $ch->name }}</option>
                            @endforeach
                        </select>
                        <select wire:model.live="bankDiff"
                                class="rounded border border-input bg-background px-2 py-1.5 text-xs focus:border-primary focus:outline-none">
                            <option value="">Any Level</option>
                            <option value="easy">Easy</option>
                            <option value="medium">Medium</option>
                            <option value="hard">Hard</option>
                        </select>
                    </div>
                </div>

                {{-- Results --}}
                @forelse($bankQuestions as $bq)
                @php $opts = is_array($bq->options) ? $bq->options : (json_decode($bq->options ?? '{}', true) ?? []); @endphp
                <div class="rounded-lg border border-border p-3 mb-3 hover:border-primary/40 transition-colors group/bq">
                    <p class="text-xs leading-relaxed line-clamp-3 mb-2">{{ $bq->question_text }}</p>
                    <div class="flex items-center gap-1.5 flex-wrap mb-2">
                        @if($bq->chapter) <span class="text-[10px] text-muted-foreground">{{ $bq->chapter->name }}</span> @endif
                        @php $dBadge = match($bq->difficulty ?? 'medium') { 'easy' => 'diff-easy', 'hard' => 'diff-hard', default => 'diff-medium' }; @endphp
                        <span class="diff-badge {{ $dBadge }}">{{ ucfirst($bq->difficulty ?? 'medium') }}</span>
                    </div>
                    @if(!empty($opts))
                    <div class="grid grid-cols-2 gap-1 text-[10px] text-muted-foreground mb-2">
                        @foreach($opts as $k => $v)
                        <div class="truncate {{ $bq->correct_answer === $k ? 'text-success font-semibold' : '' }}">{{ $k }}. {{ $v }}</div>
                        @endforeach
                    </div>
                    @endif
                    <button wire:click="addFromBank({{ $bq->id }})"
                            class="w-full py-1.5 rounded-md bg-primary/10 text-primary text-xs font-semibold hover:bg-primary hover:text-primary-foreground transition-colors">
                        + Add to {{ $tSec?->name ?? 'Paper' }}
                    </button>
                </div>
                @empty
                <div class="text-center py-10 text-muted-foreground text-xs">
                    @if($bankSearch || $bankSubject || $bankChapter || $bankDiff)
                        No questions match your filters.
                    @else
                        No questions in the bank for this exam type yet.
                    @endif
                    <div class="mt-3">
                        <button wire:click="$set('rightPanel', 'write')" class="text-primary underline text-xs">Write a question instead →</button>
                    </div>
                </div>
                @endforelse

            </div>
            @endif

            {{-- ── WRITE / EDIT PANEL ── --}}
            @if(in_array($rightPanel, ['write', 'edit']))
            <div class="pane-scroll">
                @if($rightPanel === 'edit')
                <div class="text-xs text-info font-medium mb-4 p-2 bg-info/10 rounded-lg">
                    ✏ Editing question — changes go to the question bank too.
                </div>
                @endif

                <div class="space-y-3" x-data="katexEditor()">

                    {{-- Question text with KaTeX preview --}}
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="text-xs font-semibold text-muted-foreground uppercase tracking-wide">Question</label>
                            <button type="button" @click="togglePreview()" class="text-[10px] font-medium text-primary hover:underline" x-text="showPreview ? '← Edit' : 'Preview ▶'"></button>
                        </div>
                        <textarea wire:model="qText" rows="4" x-show="!showPreview" x-ref="qtextarea"
                                  @input="srcText = $event.target.value"
                                  class="w-full rounded-lg border border-input bg-background px-3 py-2 text-xs focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary resize-none"
                                  placeholder="Type question. LaTeX: $E=mc^2$ or $$\frac{a}{b}$$"></textarea>
                        <div x-show="showPreview"
                             class="w-full min-h-[80px] rounded-lg border border-primary/30 bg-primary/5 px-3 py-2 text-sm leading-relaxed"
                             x-ref="qpreview"></div>
                        <p class="text-[10px] text-muted-foreground mt-1">Supports LaTeX math: <code>$x^2$</code> inline, <code>$$\frac{a}{b}$$</code> display</p>
                        @error('qText') <p class="text-[10px] text-destructive mt-1">{{ $message }}</p> @enderror
                    </div>

                    {{-- Options --}}
                    <div>
                        <label class="text-xs font-semibold text-muted-foreground uppercase tracking-wide mb-1 block">Options</label>
                        <div class="space-y-2">
                            @foreach(['A' => $qOptionA, 'B' => $qOptionB, 'C' => $qOptionC, 'D' => $qOptionD] as $key => $val)
                            <div class="flex items-center gap-2">
                                <label class="flex items-center gap-1.5 cursor-pointer flex-shrink-0">
                                    <input type="radio" wire:model="qCorrect" value="{{ $key }}"
                                           class="accent-success w-3 h-3">
                                    <span class="text-xs font-bold w-4 {{ $qCorrect === $key ? 'text-success' : 'text-muted-foreground' }}">{{ $key }}</span>
                                </label>
                                <input type="text" wire:model="qOption{{ $key }}"
                                       placeholder="Option {{ $key }}"
                                       class="flex-1 rounded border border-input bg-background px-2.5 py-1.5 text-xs focus:border-primary focus:outline-none {{ $qCorrect === $key ? 'border-success/50 bg-success/5' : '' }}">
                            </div>
                            @endforeach
                        </div>
                        @error('qCorrect') <p class="text-[10px] text-destructive mt-1">{{ $message }}</p> @enderror
                    </div>

                    {{-- Explanation --}}
                    <div>
                        <label class="text-xs font-semibold text-muted-foreground uppercase tracking-wide mb-1 block">Explanation <span class="font-normal normal-case">(optional)</span></label>
                        <textarea wire:model="qExplanation" rows="2"
                                  class="w-full rounded-lg border border-input bg-background px-3 py-2 text-xs focus:border-primary focus:outline-none resize-none"
                                  placeholder="Why is this the correct answer?"></textarea>
                    </div>

                    {{-- Meta --}}
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="text-xs font-semibold text-muted-foreground uppercase tracking-wide mb-1 block">Subject</label>
                            <select wire:model.live="qSubjectId"
                                    class="w-full rounded border border-input bg-background px-2 py-1.5 text-xs focus:border-primary focus:outline-none">
                                <option value="">— Select —</option>
                                @foreach($subjects as $sub)
                                <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-muted-foreground uppercase tracking-wide mb-1 block">Chapter</label>
                            <select wire:model="qChapterId"
                                    class="w-full rounded border border-input bg-background px-2 py-1.5 text-xs focus:border-primary focus:outline-none" {{ $formChapters->isEmpty() ? 'disabled' : '' }}>
                                <option value="">— Select —</option>
                                @foreach($formChapters as $ch)
                                <option value="{{ $ch->id }}">{{ $ch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-muted-foreground uppercase tracking-wide mb-1 block">Difficulty</label>
                        <div class="flex gap-2">
                            @foreach(['easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard'] as $val => $lbl)
                            <label class="flex-1 cursor-pointer">
                                <input type="radio" wire:model="qDifficulty" value="{{ $val }}" class="sr-only">
                                <div class="text-center py-1.5 rounded-md border text-xs font-semibold transition-colors
                                    {{ $qDifficulty === $val
                                        ? ($val === 'easy' ? 'bg-success/20 border-success text-success' : ($val === 'hard' ? 'bg-destructive/20 border-destructive text-destructive' : 'bg-warning/20 border-warning text-warning'))
                                        : 'border-border text-muted-foreground hover:bg-muted' }}">
                                    {{ $lbl }}
                                </div>
                            </label>
                            @endforeach
                        </div>
                    </div>

                    {{-- Save button --}}
                    <button wire:click="saveQuestion"
                            class="w-full py-2.5 rounded-lg bg-primary text-primary-foreground text-sm font-semibold hover:bg-primary/90 transition-colors">
                        {{ $rightPanel === 'edit' ? 'Save Changes' : 'Save & Add to Paper' }}
                    </button>

                    @if($rightPanel === 'edit')
                    <button wire:click="$set('rightPanel', 'bank'); $set('editingTqId', null)"
                            class="w-full py-2 rounded-lg border border-border text-xs font-semibold hover:bg-muted transition-colors">
                        Cancel Edit
                    </button>
                    @endif
                </div>
            </div>
            @endif

        </div>{{-- /right-pane --}}
    </div>{{-- /editor-body --}}

    {{-- ── SCHEDULE MODAL ────────────────────────────────────────────────── --}}
    {{-- KaTeX CDN --}}
    @once
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.10/dist/katex.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.10/dist/katex.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.10/dist/contrib/auto-render.min.js"
            onload="window.katexReady = true"></script>
    <script>
    function katexEditor() {
        return {
            showPreview: false,
            srcText: '',
            togglePreview() {
                this.showPreview = !this.showPreview;
                if (this.showPreview) {
                    this.$nextTick(() => this.renderPreview());
                }
            },
            renderPreview() {
                const el = this.$refs.qpreview;
                if (!el) return;
                const text = this.$refs.qtextarea?.value || this.srcText || '';
                el.innerHTML = text.replace(/\n/g, '<br>');
                const tryRender = () => {
                    if (window.renderMathInElement && window.katexReady) {
                        renderMathInElement(el, {
                            delimiters: [
                                { left: '$$', right: '$$', display: true },
                                { left: '$', right: '$', display: false },
                                { left: '\\[', right: '\\]', display: true },
                                { left: '\\(', right: '\\)', display: false },
                            ],
                            throwOnError: false,
                        });
                    } else {
                        setTimeout(tryRender, 100);
                    }
                };
                tryRender();
            }
        };
    }
    </script>
    @endonce

    @if($showSchedule)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-card rounded-xl shadow-xl max-w-md w-full overflow-hidden">
            <div class="bg-primary text-primary-foreground px-6 py-4 font-bold">Schedule as Live Test</div>
            <div class="p-6 space-y-4">
                <p class="text-sm text-muted-foreground">Set the start and end time. Students can access the test via the generated link during this window.</p>
                <div class="space-y-1">
                    <label class="text-sm font-medium">Start Time</label>
                    <input type="datetime-local" wire:model="scheduleStart"
                           class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none">
                    @error('scheduleStart') <p class="text-xs text-destructive">{{ $message }}</p> @enderror
                </div>
                <div class="space-y-1">
                    <label class="text-sm font-medium">End Time</label>
                    <input type="datetime-local" wire:model="scheduleEnd"
                           class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none">
                    @error('scheduleEnd') <p class="text-xs text-destructive">{{ $message }}</p> @enderror
                </div>
                <div class="flex gap-3 justify-end pt-2">
                    <button wire:click="$set('showSchedule', false)"
                            class="px-4 py-2 rounded-lg border border-border text-sm font-medium hover:bg-muted transition-colors">Cancel</button>
                    <button wire:click="schedulePaper"
                            class="px-4 py-2 rounded-lg bg-success text-white text-sm font-semibold hover:bg-success/90 transition-colors">
                        Schedule &amp; Create Link
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>
