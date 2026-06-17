<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Chapter;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.admin')] class extends Component {
    use WithPagination;

    public string $examType  = '';
    public string $subjectId = '';
    public string $chapterId = '';
    public string $difficulty = '';
    public string $search     = '';

    public function selectExam(string $exam): void
    {
        $this->examType  = $exam;
        $this->subjectId = '';
        $this->chapterId = '';
        $this->resetPage();
    }

    public function selectSubject(string $id): void
    {
        $this->subjectId = ($this->subjectId === $id) ? '' : $id;
        $this->chapterId = '';
        $this->resetPage();
    }

    public function selectChapter(string $id): void
    {
        $this->chapterId = ($this->chapterId === $id) ? '' : $id;
        $this->resetPage();
    }

    public function updatedSearch(): void   { $this->resetPage(); }
    public function updatedDifficulty(): void { $this->resetPage(); }

    public function deleteQuestion(int $id): void
    {
        Question::withoutGlobalScopes()->findOrFail($id)->forceDelete();
        session()->flash('status', 'Question deleted.');
    }

    public function with(): array
    {
        $subjectTree = [];
        if ($this->examType) {
            $subjects = Subject::withoutGlobalScopes()
                ->where('exam_type', $this->examType)
                ->whereNull('institution_id')
                ->orderBy('display_order')
                ->withCount(['questions as q_count' => fn($q) => $q->withoutGlobalScopes()
                    ->whereNull('institution_id')
                    ->where('exam_type', $this->examType)])
                ->get();

            foreach ($subjects as $sub) {
                $chapters = [];
                if ((string)$sub->id === $this->subjectId) {
                    $chapters = Chapter::where('subject_id', $sub->id)
                        ->withCount(['questions as q_count' => fn($q) => $q->withoutGlobalScopes()
                            ->whereNull('institution_id')
                            ->where('exam_type', $this->examType)])
                        ->orderBy('display_order')
                        ->get();
                }
                $subjectTree[] = ['subject' => $sub, 'chapters' => $chapters];
            }
        }

        $query = Question::withoutGlobalScopes()->with(['subject', 'chapter', 'topic']);
        if ($this->examType)   $query->where('exam_type', $this->examType);
        if ($this->subjectId)  $query->where('subject_id', $this->subjectId);
        if ($this->chapterId)  $query->where('chapter_id', $this->chapterId);
        if ($this->difficulty)  $query->where('difficulty', $this->difficulty);
        if ($this->search)      $query->where('question_text', 'like', '%' . $this->search . '%');

        $totalByExam = DB::table('questions')
            ->whereNull('institution_id')
            ->selectRaw('exam_type, count(*) as cnt')
            ->groupBy('exam_type')
            ->pluck('cnt', 'exam_type')
            ->toArray();

        return [
            'questions'   => $query->whereNull('institution_id')->orderByDesc('created_at')->paginate(20),
            'subjectTree' => $subjectTree,
            'totalByExam' => $totalByExam,
        ];
    }
}; ?>

<div class="space-y-5">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-display font-bold">Platform Question Bank</h1>
            <p class="text-sm text-muted-foreground mt-0.5">Browse and manage all platform-wide questions</p>
        </div>
    </div>

    @if(session('status'))
    <div class="rounded-lg bg-success/10 border border-success/30 text-success px-4 py-2.5 text-sm">{{ session('status') }}</div>
    @endif

    {{-- Exam type tabs --}}
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

        {{-- Subject / Chapter tree --}}
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
                            class="w-full text-left px-4 py-2.5 text-sm font-medium flex items-center justify-between hover:bg-muted/50 transition-colors {{ $isSubSel ? 'text-primary bg-primary/5' : 'text-foreground' }}">
                        <span class="truncate">{{ $sub->name }}</span>
                        <span class="text-xs font-normal text-muted-foreground ml-1 shrink-0">{{ $sub->q_count }}</span>
                    </button>
                    @if($isSubSel && !empty($entry['chapters']))
                    <div class="bg-muted/20 border-t border-border/30">
                        @foreach($entry['chapters'] as $ch)
                        @php $isChSel = (string)$ch->id === $chapterId; @endphp
                        <button wire:click="selectChapter('{{ $ch->id }}')"
                                class="w-full text-left pl-7 pr-4 py-2 text-xs flex items-center justify-between hover:bg-muted/60 transition-colors {{ $isChSel ? 'text-primary font-semibold bg-primary/10' : 'text-muted-foreground' }}">
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

        {{-- Question list --}}
        <div class="flex-1 min-w-0 space-y-4">
            <div class="flex gap-2 flex-wrap">
                <input wire:model.live.debounce.400ms="search" type="text" placeholder="Search questions..."
                       class="flex-1 min-w-48 rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none">
                <select wire:model.live="difficulty"
                        class="rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none">
                    <option value="">Any difficulty</option>
                    <option value="easy">Easy</option>
                    <option value="medium">Medium</option>
                    <option value="hard">Tough</option>
                </select>
            </div>

            <div class="space-y-2">
                @forelse($questions as $q)
                @php
                    $opts = is_array($q->options) ? $q->options : (json_decode($q->options ?? '{}', true) ?? []);
                    $diffClr = match($q->difficulty) { 'easy' => 'bg-success/10 text-success', 'hard' => 'bg-destructive/10 text-destructive', default => 'bg-warning/10 text-warning' };
                @endphp
                <div class="rounded-xl border border-border bg-card p-4 hover:border-primary/30 transition-colors group">
                    <div class="flex items-start gap-3">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm leading-relaxed line-clamp-2 mb-2">{!! strip_tags($q->question_text) !!}</p>
                            @if(!empty($opts))
                            <div class="grid grid-cols-2 gap-x-4 gap-y-0.5 text-xs text-muted-foreground mb-2">
                                @foreach($opts as $k => $v)
                                <div class="truncate {{ $q->correct_answer === $k ? 'text-success font-semibold' : '' }}">({{ $k }}) {{ $v }}</div>
                                @endforeach
                            </div>
                            @endif
                            <div class="flex items-center gap-2 flex-wrap">
                                @if($q->subject) <span class="text-[10px] bg-muted text-muted-foreground px-2 py-0.5 rounded-full">{{ $q->subject->name }}</span> @endif
                                @if($q->chapter) <span class="text-[10px] text-muted-foreground">{{ $q->chapter->name }}</span> @endif
                                <span class="text-[10px] px-2 py-0.5 rounded-full font-semibold {{ $diffClr }}">{{ $q->difficulty === 'hard' ? 'Tough' : ucfirst($q->difficulty ?? 'medium') }}</span>
                                <span class="text-[10px] text-muted-foreground uppercase">{{ $q->exam_type }}</span>
                                <span class="text-[10px] text-muted-foreground">+{{ $q->positive_marks }}/-{{ $q->negative_marks }}</span>
                            </div>
                        </div>
                        <div class="opacity-0 group-hover:opacity-100 transition-opacity shrink-0">
                            <button wire:click="deleteQuestion({{ $q->id }})"
                                    wire:confirm="Permanently delete this question?"
                                    class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors" title="Delete">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
                @empty
                <div class="rounded-xl border border-dashed border-border bg-card p-12 text-center text-muted-foreground text-sm">
                    {{ $examType ? 'No questions for this selection.' : 'Select an exam type to browse questions.' }}
                </div>
                @endforelse
            </div>

            @if($questions->hasPages())
            <div class="mt-4">{{ $questions->links() }}</div>
            @endif
        </div>
    </div>
</div>
