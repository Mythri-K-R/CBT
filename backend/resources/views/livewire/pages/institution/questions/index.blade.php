<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Chapter;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

new #[Layout('layouts.institution')] class extends Component {
    use WithPagination;

    public $examType = '';
    public $subjectId = '';
    public array $chapterIds = [];
    public $difficulty = '';
    public $search = '';

    public function with(): array
    {
        $query = Question::with(['subject', 'chapter']);

        if ($this->search) {
            $query->where('question_text', 'like', '%' . $this->search . '%');
        }

        if ($this->examType) {
            $query->where('exam_type', $this->examType);
        }

        if ($this->subjectId) {
            $query->where('subject_id', $this->subjectId);
        }

        if (!empty($this->chapterIds)) {
            $query->whereIn('chapter_id', $this->chapterIds);
        }

        if ($this->difficulty) {
            $query->where('difficulty', $this->difficulty);
        }

        return [
            'questions' => $query->orderBy('created_at', 'desc')->paginate(10),
            'subjects' => Subject::where('is_active', true)->get(),
            'chapters' => $this->subjectId ? Chapter::where('subject_id', $this->subjectId)->get() : [],
        ];
    }

    public function updatedSubjectId(): void
    {
        $this->chapterIds = [];
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['examType', 'difficulty', 'search']) || str_starts_with($property, 'chapterIds')) {
            $this->resetPage();
        }
    }

    public function deleteQuestion(int $id): void
    {
        $question = Question::find($id);
        if (!$question) return;
        abort_if($question->institution_id !== auth()->user()->institution_id, 403);
        $question->delete();
        session()->flash('status', 'Question deleted.');
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-display font-bold tracking-tight">Question Bank</h1>
            <p class="text-muted-foreground mt-1">Manage and organize your repository of examination questions.</p>
        </div>
        <div class="flex items-center gap-3">
            <button class="inline-flex items-center justify-center rounded-lg border border-border bg-background px-4 py-2 text-sm font-semibold shadow-sm hover:bg-accent hover:text-accent-foreground transition-all">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
                Import
            </button>
            <a href="{{ route('institution.questions.create') }}" wire:navigate class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90 transition-all">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                Create Question
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4 p-4 rounded-xl border border-border bg-card shadow-sm">
        <div class="relative col-span-1 md:col-span-1">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search questions..." class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary placeholder:text-muted-foreground">
        </div>
        <div>
            <select wire:model.live="examType" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary text-foreground">
                <option value="">All Exams</option>
                <option value="neet">NEET</option>
                <option value="jee_main">JEE Main</option>
            </select>
        </div>
        <div>
            <select wire:model.live="subjectId" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary text-foreground">
                <option value="">All Subjects</option>
                @foreach($subjects as $subject)
                    <option value="{{ $subject->id }}">{{ $subject->name }} ({{ strtoupper($subject->exam_type) }})</option>
                @endforeach
            </select>
        </div>
        <div class="relative group">
            <button type="button" class="flex w-full items-center justify-between rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary text-foreground disabled:opacity-50" {{ empty($chapters) ? 'disabled' : '' }}>
                <span class="truncate">{{ empty($chapterIds) ? 'All Chapters' : count($chapterIds) . ' Chapters Selected' }}</span>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="opacity-50"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            @if(!empty($chapters))
            <div class="absolute left-0 top-full z-50 mt-1 hidden w-full md:w-64 max-h-60 overflow-y-auto rounded-md border border-border bg-popover p-1 text-popover-foreground shadow-md group-hover:block hover:block">
                @foreach($chapters as $chapter)
                    <label class="flex cursor-pointer items-start gap-2 rounded-sm px-2 py-1.5 text-sm hover:bg-accent hover:text-accent-foreground">
                        <input type="checkbox" wire:model.live="chapterIds" value="{{ $chapter->id }}" class="mt-0.5 rounded border-input text-primary focus:ring-primary">
                        <span class="leading-none">{{ $chapter->name }}</span>
                    </label>
                @endforeach
            </div>
            @endif
        </div>
        <div>
            <select wire:model.live="difficulty" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary text-foreground">
                <option value="">All Difficulties</option>
                <option value="easy">Easy</option>
                <option value="medium">Medium</option>
                <option value="hard">Hard</option>
            </select>
        </div>
    </div>

    <!-- Questions Table -->
    <div class="rounded-xl border border-border bg-card shadow-sm overflow-hidden relative">
        <div wire:loading.delay class="absolute inset-0 bg-card/50 backdrop-blur-sm z-10 flex items-center justify-center">
            <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-background border border-border shadow-sm text-primary font-medium text-sm">
                <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                Loading...
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-muted/50 text-muted-foreground font-medium">
                    <tr>
                        <th class="px-6 py-3 w-16 text-center">ID</th>
                        <th class="px-6 py-3">Question</th>
                        <th class="px-6 py-3 w-28">Difficulty</th>
                        <th class="px-6 py-3 w-24 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/50">
                    
                    @forelse($questions as $question)
                        <tr class="hover:bg-muted/50 transition-colors">
                            <td class="px-6 py-4 text-center font-mono text-xs text-muted-foreground">#{{ $question->id }}</td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="text-xs font-medium px-2 py-0.5 bg-primary/10 text-primary rounded-full">{{ $question->subject->name ?? 'N/A' }}</span>
                                    <span class="text-xs text-muted-foreground">{{ $question->chapter->name ?? 'N/A' }}</span>
                                    <span class="text-xs text-muted-foreground border-l border-border pl-2 ml-1">{{ str_replace('_', ' ', strtoupper($question->type)) }}</span>
                                </div>
                                
                                <p class="text-foreground font-medium text-base mb-3 leading-relaxed">
                                    {!! nl2br(e(strip_tags($question->question_text))) !!}
                                </p>

                                @if(is_array($question->options) && count($question->options) > 0)
                                    <div class="grid grid-cols-1 xl:grid-cols-2 gap-2 mt-2">
                                        @foreach($question->options as $key => $value)
                                            @php 
                                                $isCorrect = false;
                                                if ($question->type === 'multiple_mcq') {
                                                    $isCorrect = in_array((string)$key, array_map('trim', explode(',', strtoupper($question->correct_answer))));
                                                } else {
                                                    $isCorrect = strtoupper(trim($question->correct_answer)) === strtoupper(trim((string)$key));
                                                }
                                            @endphp
                                            <div class="flex items-start gap-2 p-2 rounded-md border {{ $isCorrect ? 'bg-success/10 border-success/30' : 'bg-muted/30 border-border/50' }}">
                                                <span class="flex-shrink-0 flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold {{ $isCorrect ? 'bg-success text-success-foreground' : 'bg-muted-foreground/20 text-muted-foreground' }}">
                                                    {{ $key }}
                                                </span>
                                                <span class="text-sm pt-0.5 {{ $isCorrect ? 'text-success-foreground font-medium' : 'text-foreground' }}">
                                                    {{ $value }}
                                                </span>
                                                @if($isCorrect)
                                                    <svg class="ml-auto w-4 h-4 text-success flex-shrink-0 mt-1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @elseif($question->type === 'numerical' || $question->type === 'integer')
                                    <div class="mt-2 p-3 rounded-md bg-success/5 border border-success/20 inline-flex items-center gap-2">
                                        <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider">Correct Answer:</span>
                                        <span class="text-sm font-semibold text-success">{{ $question->correct_answer }}</span>
                                        @if($question->answer_tolerance)
                                            <span class="text-xs text-muted-foreground">(±{{ $question->answer_tolerance }})</span>
                                        @endif
                                    </div>
                                @endif
                                
                                @if($question->explanation)
                                    <div class="mt-3 p-3 rounded-md bg-accent/30 border border-border/50">
                                        <span class="text-xs font-bold text-muted-foreground uppercase tracking-wider block mb-1">Explanation:</span>
                                        <p class="text-sm text-foreground/80 leading-relaxed">{{ strip_tags($question->explanation) }}</p>
                                    </div>
                                @endif

                                @if($question->has_image)
                                    <span class="inline-flex items-center gap-1 text-xs text-muted-foreground mt-3">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                                        Contains Image attached
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                @php
                                    $color = match($question->difficulty) {
                                        'easy' => 'text-success bg-success/10 ring-success/20',
                                        'medium' => 'text-warning bg-warning/10 ring-warning/20',
                                        'hard' => 'text-destructive bg-destructive/10 ring-destructive/20',
                                        default => 'text-muted-foreground bg-muted ring-border'
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $color }}">
                                    {{ ucfirst($question->difficulty) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('institution.questions.edit', $question) }}" wire:navigate class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                    </a>
                                    <button wire:click="deleteQuestion({{ $question->id }})" wire:confirm="Delete this question permanently?" class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="m19 6-.867 12.142A2 2 0 0 1 16.138 20H7.862a2 2 0 0 1-1.995-1.858L5 6"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-12 text-center text-muted-foreground">
                                No questions found matching your filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        
        @if($questions->hasPages())
            <div class="px-6 py-4 border-t border-border/50">
                {{ $questions->links(data: ['scrollTo' => false]) }}
            </div>
        @endif
    </div>
</div>
