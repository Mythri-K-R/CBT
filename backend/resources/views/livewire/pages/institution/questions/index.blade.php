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
    public $chapterId = '';
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

        if ($this->chapterId) {
            $query->where('chapter_id', $this->chapterId);
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
        $this->chapterId = '';
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['examType', 'chapterId', 'difficulty', 'search'])) {
            $this->resetPage();
        }
    }

    public function deleteQuestion(Question $question): void
    {
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
        <div>
            <select wire:model.live="chapterId" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary text-foreground" {{ empty($chapters) ? 'disabled' : '' }}>
                <option value="">All Chapters</option>
                @foreach($chapters as $chapter)
                    <option value="{{ $chapter->id }}">{{ $chapter->name }}</option>
                @endforeach
            </select>
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
    <div class="rounded-xl border border-border bg-card shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-muted/50 text-muted-foreground font-medium">
                    <tr>
                        <th class="px-6 py-3 w-16 text-center">ID</th>
                        <th class="px-6 py-3 min-w-[300px]">Question Preview</th>
                        <th class="px-6 py-3">Details</th>
                        <th class="px-6 py-3">Type</th>
                        <th class="px-6 py-3">Difficulty</th>
                        <th class="px-6 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/50 relative">
                    <div wire:loading.delay class="absolute inset-0 bg-card/50 backdrop-blur-sm z-10 flex items-center justify-center">
                        <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-background border border-border shadow-sm text-primary font-medium text-sm">
                            <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            Loading...
                        </div>
                    </div>
                    
                    @forelse($questions as $question)
                        <tr class="hover:bg-muted/50 transition-colors">
                            <td class="px-6 py-4 text-center font-mono text-xs text-muted-foreground">#{{ $question->id }}</td>
                            <td class="px-6 py-4">
                                <p class="text-foreground line-clamp-2">{{ strip_tags($question->question_text) }}</p>
                                @if($question->has_image)
                                    <span class="inline-flex items-center gap-1 text-xs text-muted-foreground mt-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                                        Contains Image
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex flex-col gap-1">
                                    <span class="font-medium text-foreground">{{ $question->subject->name ?? 'N/A' }}</span>
                                    <span class="text-xs text-muted-foreground truncate max-w-[150px]">{{ $question->chapter->name ?? 'N/A' }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-md bg-secondary/50 px-2 py-1 text-xs font-medium text-secondary-foreground ring-1 ring-inset ring-secondary-foreground/10">
                                    {{ str_replace('_', ' ', strtoupper($question->type)) }}
                                </span>
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
                            <td colspan="6" class="px-6 py-12 text-center text-muted-foreground">
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
