<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Chapter;
use App\Models\Topic;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.institution')] class extends Component {
    use WithPagination;

    public string $examType  = '';
    public string $subjectId = '';
    public string $chapterId = '';
    public string $topicId   = '';
    public string $difficulty = '';
    public string $search     = '';

    public function selectExam(string $exam): void
    {
        $this->examType  = $exam;
        $this->subjectId = '';
        $this->chapterId = '';
        $this->topicId   = '';
        $this->resetPage();
    }

    public function selectSubject(string $id): void
    {
        $this->subjectId = ($this->subjectId === $id) ? '' : $id;
        $this->chapterId = '';
        $this->topicId   = '';
        $this->resetPage();
    }

    public function selectChapter(string $id): void
    {
        $this->chapterId = ($this->chapterId === $id) ? '' : $id;
        $this->topicId   = '';
        $this->resetPage();
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedDifficulty(): void { $this->resetPage(); }

    public function deleteQuestion(int $id): void
    {
        $question = Question::withoutGlobalScopes()->find($id);
        if (!$question) return;
        if ($question->institution_id && $question->institution_id !== Auth::user()->institution_id) abort(403);
        $question->delete();
        session()->flash('status', 'Question deleted.');
    }

    public function with(): array
    {
        // ── Hierarchy sidebar data ─────────────────────────────────────────────
        $examTypes = ['neet' => 'NEET', 'jee_main' => 'JEE Main', 'kcet' => 'KCET'];

        // Subject tree for selected exam type
        $subjectTree = [];
        if ($this->examType) {
            $subjects = Subject::withoutGlobalScopes()
                ->where('exam_type', $this->examType)
                ->where(function($q) {
                    $q->whereNull('institution_id')
                      ->orWhere('institution_id', Auth::user()->institution_id);
                })
                ->orderBy('display_order')
                ->withCount(['questions as q_count' => fn($q) => $q->withoutGlobalScopes()
                    ->where('exam_type', $this->examType)
                    ->where(function($sq) {
                        $sq->whereNull('institution_id')
                           ->orWhere('institution_id', Auth::user()->institution_id);
                    })
                ])
                ->get();

            foreach ($subjects as $sub) {
                $chapters = [];
                if ((string)$sub->id === $this->subjectId) {
                    $chapters = Chapter::where('subject_id', $sub->id)
                        ->withCount(['questions as q_count' => fn($q) => $q->withoutGlobalScopes()
                            ->where('exam_type', $this->examType)
                            ->where(function($sq) {
                                $sq->whereNull('institution_id')
                                   ->orWhere('institution_id', Auth::user()->institution_id);
                            })
                        ])
                        ->orderBy('display_order')
                        ->get();
                }
                $subjectTree[] = ['subject' => $sub, 'chapters' => $chapters];
            }
        }

        // ── Question list ──────────────────────────────────────────────────────
        $query = Question::withoutGlobalScopes()
            ->with(['subject', 'chapter', 'topic'])
            ->where(function($q) {
                $q->whereNull('institution_id')
                  ->orWhere('institution_id', Auth::user()->institution_id);
            });

        if ($this->examType)  $query->where('exam_type', $this->examType);
        if ($this->subjectId) $query->where('subject_id', $this->subjectId);
        if ($this->chapterId) $query->where('chapter_id', $this->chapterId);
        if ($this->topicId)   $query->where('topic_id',   $this->topicId);
        if ($this->difficulty) $query->where('difficulty', $this->difficulty);
        if ($this->search) {
            $query->where('question_text', 'like', '%' . $this->search . '%');
        }

        // Stat counts
        $totalByExam = DB::table('questions')
            ->whereNull('institution_id')
            ->orWhere('institution_id', Auth::user()->institution_id)
            ->selectRaw('exam_type, count(*) as cnt')
            ->groupBy('exam_type')
            ->pluck('cnt', 'exam_type')
            ->toArray();

        return [
            'questions'   => $query->orderByDesc('created_at')->paginate(15),
            'examTypes'   => $examTypes,
            'subjectTree' => $subjectTree,
            'totalByExam' => $totalByExam,
        ];
    }
}; ?>

<div class="space-y-5">

    {{-- Header --}}
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-display font-bold">Question Bank</h1>
            <p class="text-sm text-muted-foreground mt-0.5">Browse questions by exam type, subject, and chapter</p>
        </div>
        <a href="{{ route('institution.questions.create') }}" wire:navigate
           class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
            Add Question
        </a>
    </div>

    @if(session('status'))
    <div class="rounded-lg bg-success/10 border border-success/30 text-success px-4 py-2.5 text-sm">{{ session('status') }}</div>
    @endif

    {{-- Exam type pills --}}
    <div class="flex flex-wrap gap-2">
        <button wire:click="selectExam('')"
                class="px-4 py-1.5 rounded-full text-sm font-semibold transition-colors {{ $examType === '' ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-muted/80' }}">
            All <span class="ml-1 text-xs opacity-70">{{ array_sum($totalByExam) }}</span>
        </button>
        @foreach(['neet' => 'NEET', 'jee_main' => 'JEE Main', 'kcet' => 'KCET'] as $key => $label)
        <button wire:click="selectExam('{{ $key }}')"
                class="px-4 py-1.5 rounded-full text-sm font-semibold transition-colors {{ $examType === $key ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-muted/80' }}">
            {{ $label }} <span class="ml-1 text-xs opacity-70">{{ $totalByExam[$key] ?? 0 }}</span>
        </button>
        @endforeach
    </div>

    <div class="flex gap-5 items-start">

        {{-- ── LEFT: Subject / Chapter tree ─────────────────────────────── --}}
        @if($examType && !empty($subjectTree))
        <div class="w-56 flex-shrink-0 rounded-xl border border-border bg-card overflow-hidden shadow-sm">
            <div class="px-4 py-3 border-b border-border/50 text-xs font-bold uppercase text-muted-foreground tracking-wide">
                {{ ['neet'=>'NEET','jee_main'=>'JEE Main','kcet'=>'KCET'][$examType] }} Subjects
            </div>
            <div class="divide-y divide-border/30 max-h-[70vh] overflow-y-auto">
                @foreach($subjectTree as $entry)
                @php $sub = $entry['subject']; $isSubSel = (string)$sub->id === $subjectId; @endphp
                <div>
                    <button wire:click="selectSubject('{{ $sub->id }}')"
                            class="w-full text-left px-4 py-2.5 text-sm font-medium flex items-center justify-between hover:bg-muted/50 transition-colors
                                {{ $isSubSel ? 'text-primary bg-primary/5' : 'text-foreground' }}">
                        <span class="truncate">{{ $sub->name }}</span>
                        <span class="text-xs font-normal text-muted-foreground ml-1 shrink-0">{{ $sub->q_count }}</span>
                    </button>

                    {{-- Chapters --}}
                    @if($isSubSel && !empty($entry['chapters']))
                    <div class="bg-muted/20 border-t border-border/30">
                        @foreach($entry['chapters'] as $ch)
                        @php $isChSel = (string)$ch->id === $chapterId; @endphp
                        <button wire:click="selectChapter('{{ $ch->id }}')"
                                class="w-full text-left pl-7 pr-4 py-2 text-xs flex items-center justify-between hover:bg-muted/60 transition-colors
                                    {{ $isChSel ? 'text-primary font-semibold bg-primary/10' : 'text-muted-foreground' }}">
                            <span class="truncate">{{ $ch->name }}</span>
                            <span class="shrink-0 ml-1">{{ $ch->q_count }}</span>
                        </button>
                        @endforeach
                    </div>
                    @endif
                </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- ── RIGHT: Question list ──────────────────────────────────────── --}}
        <div class="flex-1 min-w-0 space-y-4">

            {{-- Search + filter bar --}}
            <div class="flex gap-2 flex-wrap">
                <input wire:model.live.debounce.400ms="search" type="text" placeholder="Search questions..."
                       class="flex-1 min-w-48 rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none">
                <select wire:model.live="difficulty"
                        class="rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none">
                    <option value="">Any difficulty</option>
                    <option value="easy">Easy</option>
                    <option value="medium">Medium</option>
                    <option value="hard">Hard</option>
                </select>
            </div>

            {{-- Breadcrumb --}}
            @if($examType)
            <div class="flex items-center gap-1.5 text-xs text-muted-foreground flex-wrap">
                <button wire:click="selectExam('')" class="hover:text-foreground transition-colors">All</button>
                <span>›</span>
                <button wire:click="selectExam('{{ $examType }}')" class="{{ !$subjectId ? 'text-foreground font-medium' : 'hover:text-foreground' }}">
                    {{ ['neet'=>'NEET','jee_main'=>'JEE Main','kcet'=>'KCET'][$examType] }}
                </button>
                @if($subjectId)
                @php
                    $selSubEntry = collect($subjectTree)->first(fn($e) => (string)$e['subject']->id === $subjectId);
                    $selSubName  = $selSubEntry ? $selSubEntry['subject']->name : '—';
                @endphp
                <span>›</span>
                <button wire:click="selectSubject('{{ $subjectId }}')" class="{{ !$chapterId ? 'text-foreground font-medium' : 'hover:text-foreground' }}">
                    {{ $selSubName }}
                </button>
                @if($chapterId)
                @php $selCh = collect($selSubEntry['chapters'] ?? [])->firstWhere(fn($c) => (string)$c->id === $chapterId); @endphp
                <span>›</span>
                <span class="text-foreground font-medium">{{ $selCh?->name ?? '—' }}</span>
                @endif
                @endif
            </div>
            @endif

            {{-- Questions --}}
            <div class="space-y-2">
                @forelse($questions as $question)
                @php
                    $opts = is_array($question->options) ? $question->options : (json_decode($question->options ?? '{}', true) ?? []);
                    $diffClr = match($question->difficulty) { 'easy' => 'bg-success/10 text-success', 'hard' => 'bg-destructive/10 text-destructive', default => 'bg-warning/10 text-warning' };
                    $isPlatform = is_null($question->institution_id);
                @endphp
                <div class="rounded-xl border border-border bg-card p-4 hover:border-primary/30 transition-colors group">
                    <div class="flex items-start gap-3">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm leading-relaxed line-clamp-2 mb-2">{!! strip_tags($question->question_text) !!}</p>

                            @if(!empty($opts))
                            <div class="grid grid-cols-2 gap-x-4 gap-y-0.5 text-xs text-muted-foreground mb-2">
                                @foreach($opts as $k => $v)
                                <div class="truncate {{ $question->correct_answer === $k ? 'text-success font-semibold' : '' }}">
                                    ({{ $k }}) {{ $v }}
                                </div>
                                @endforeach
                            </div>
                            @endif

                            <div class="flex items-center gap-2 flex-wrap">
                                @if($question->chapter)
                                <span class="text-[10px] text-muted-foreground bg-muted px-2 py-0.5 rounded-full">{{ $question->chapter->name }}</span>
                                @endif
                                @if($question->topic)
                                <span class="text-[10px] text-muted-foreground">{{ $question->topic->name }}</span>
                                @endif
                                <span class="text-[10px] px-2 py-0.5 rounded-full font-semibold {{ $diffClr }}">{{ ucfirst($question->difficulty ?? 'medium') }}</span>
                                @if($isPlatform)
                                <span class="text-[10px] px-2 py-0.5 rounded-full bg-info/10 text-info font-medium">Platform</span>
                                @endif
                                <span class="text-[10px] text-muted-foreground">{{ strtoupper($question->exam_type) }}</span>
                            </div>
                        </div>

                        <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity shrink-0">
                            @if(!$isPlatform)
                            <a href="{{ route('institution.questions.edit', $question) }}" wire:navigate
                               class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-primary hover:bg-primary/10 transition-colors" title="Edit">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </a>
                            <button wire:click="deleteQuestion({{ $question->id }})"
                                    wire:confirm="Delete this question?"
                                    class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors" title="Delete">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                            </button>
                            @else
                            <span class="text-[10px] text-muted-foreground italic px-2">Platform Q</span>
                            @endif
                        </div>
                    </div>
                </div>
                @empty
                <div class="rounded-xl border border-dashed border-border bg-card p-12 text-center">
                    <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="mx-auto text-muted-foreground mb-3 opacity-40"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <p class="text-muted-foreground text-sm">
                        @if($search || $difficulty || $chapterId || $subjectId)
                            No questions match your filters.
                        @else
                            {{ $examType ? 'No questions for this exam type yet.' : 'Select an exam type to browse questions.' }}
                        @endif
                    </p>
                    @if(!$search && !$difficulty)
                    <a href="{{ route('institution.questions.create') }}" wire:navigate
                       class="inline-block mt-4 text-sm font-medium text-primary hover:underline">Add your first question →</a>
                    @endif
                </div>
                @endforelse
            </div>

            {{-- Pagination --}}
            @if($questions->hasPages())
            <div class="mt-4">{{ $questions->links() }}</div>
            @endif

        </div>
    </div>

</div>
