<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\Subject;
use App\Models\Chapter;
use App\Models\Question;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.institution')] class extends Component {
    public $examType = 'neet';
    public $subjectId = '';
    public $chapterId = '';
    public $difficulty = 'medium';
    public $type = 'single_mcq';
    
    public $questionText = '';
    public $correctAnswer = '';
    public $positiveMarks = 4.0;
    public $negativeMarks = 1.0;

    // For MCQ
    public $options = [
        'A' => '',
        'B' => '',
        'C' => '',
        'D' => ''
    ];

    public function with(): array
    {
        return [
            'subjects' => Subject::where('exam_type', $this->examType)->where('is_active', true)->get(),
            'chapters' => $this->subjectId ? Chapter::where('subject_id', $this->subjectId)->get() : [],
        ];
    }

    public function updatedExamType()
    {
        $this->subjectId = '';
        $this->chapterId = '';
    }

    public function updatedSubjectId()
    {
        $this->chapterId = '';
    }

    public function save()
    {
        $this->validate([
            'subjectId' => 'required',
            'chapterId' => 'required',
            'questionText' => 'required',
            'correctAnswer' => 'required',
        ]);

        $optionsJson = null;
        if (in_array($this->type, ['single_mcq', 'multiple_mcq'])) {
            $optionsJson = $this->options;
        }

        Question::create([
            'institution_id' => Auth::user()->institution_id,
            'created_by' => Auth::id(),
            'exam_type' => $this->examType,
            'subject_id' => $this->subjectId,
            'chapter_id' => $this->chapterId,
            'difficulty' => $this->difficulty,
            'type' => $this->type,
            'question_text' => $this->questionText,
            'options' => $optionsJson,
            'correct_answer' => $this->correctAnswer,
            'positive_marks' => $this->positiveMarks,
            'negative_marks' => $this->negativeMarks,
            'status' => 'active',
        ]);

        session()->flash('status', 'Question successfully created.');
        $this->redirect(route('institution.questions'), navigate: true);
    }
}; ?>

<div class="space-y-6 max-w-4xl mx-auto">
    <!-- Header -->
    <div class="flex items-center gap-4">
        <a href="{{ route('institution.questions') }}" class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-secondary hover:bg-secondary/80 text-secondary-foreground transition-colors shrink-0">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div>
            <h1 class="text-3xl font-display font-bold tracking-tight">Create Question</h1>
            <p class="text-muted-foreground mt-1">Add a new question to the institutional bank.</p>
        </div>
    </div>

    <form wire:submit="save" class="space-y-8">
        <!-- Classification -->
        <div class="rounded-xl border border-border bg-card p-6 shadow-sm space-y-6">
            <h2 class="text-lg font-semibold border-b border-border/50 pb-4">1. Classification</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="space-y-2">
                    <label class="text-sm font-medium leading-none">Exam Type</label>
                    <select wire:model.live="examType" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        <option value="neet">NEET</option>
                        <option value="jee_main">JEE Main</option>
                        <option value="kcet">KCET</option>
                    </select>
                </div>
                
                <div class="space-y-2">
                    <label class="text-sm font-medium leading-none">Subject</label>
                    <select wire:model.live="subjectId" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        <option value="">Select Subject...</option>
                        @foreach($subjects as $subject)
                            <option value="{{ $subject->id }}">{{ $subject->name }}</option>
                        @endforeach
                    </select>
                    @error('subjectId') <span class="text-[10px] text-destructive">{{ $message }}</span> @enderror
                </div>

                <div class="space-y-2">
                    <label class="text-sm font-medium leading-none">Chapter</label>
                    <select wire:model="chapterId" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" {{ empty($chapters) ? 'disabled' : '' }}>
                        <option value="">Select Chapter...</option>
                        @foreach($chapters as $chapter)
                            <option value="{{ $chapter->id }}">{{ $chapter->name }}</option>
                        @endforeach
                    </select>
                    @error('chapterId') <span class="text-[10px] text-destructive">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-sm font-medium leading-none">Question Type</label>
                    <select wire:model.live="type" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        <option value="single_mcq">Single Choice MCQ</option>
                        <option value="multiple_mcq">Multiple Choice MCQ</option>
                        <option value="numerical">Numerical / Integer</option>
                    </select>
                </div>

                <div class="space-y-2">
                    <label class="text-sm font-medium leading-none">Difficulty</label>
                    <select wire:model="difficulty" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        <option value="easy">Easy</option>
                        <option value="medium">Medium</option>
                        <option value="hard">Tough</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="rounded-xl border border-border bg-card p-6 shadow-sm space-y-6">
            <h2 class="text-lg font-semibold border-b border-border/50 pb-4">2. Question Content</h2>
            
            <div class="space-y-2">
                <label class="text-sm font-medium leading-none">Question Text (Supports HTML & MathJax)</label>
                <textarea wire:model="questionText" rows="6" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary placeholder:text-muted-foreground" placeholder="Enter the question here..."></textarea>
                @error('questionText') <span class="text-[10px] text-destructive">{{ $message }}</span> @enderror
            </div>

            @if(in_array($type, ['single_mcq', 'multiple_mcq']))
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    @foreach(['A', 'B', 'C', 'D'] as $opt)
                        <div class="space-y-2">
                            <label class="text-sm font-medium leading-none flex items-center gap-2">
                                <span class="flex h-5 w-5 items-center justify-center rounded-full bg-secondary text-[10px] font-bold text-secondary-foreground">{{ $opt }}</span>
                                Option {{ $opt }}
                            </label>
                            <input type="text" wire:model="options.{{ $opt }}" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 pt-4 border-t border-border/50">
                <div class="space-y-2">
                    <label class="text-sm font-medium leading-none text-success">Correct Answer</label>
                    <input type="text" wire:model="correctAnswer" placeholder="{{ $type === 'single_mcq' ? 'e.g. A' : ($type === 'multiple_mcq' ? 'e.g. A,C' : 'e.g. 42.5') }}" class="w-full rounded-lg border border-success/50 bg-success/5 px-3 py-2 text-sm focus:border-success focus:outline-none focus:ring-1 focus:ring-success">
                    @error('correctAnswer') <span class="text-[10px] text-destructive">{{ $message }}</span> @enderror
                </div>
                <div class="space-y-2">
                    <label class="text-sm font-medium leading-none">Positive Marks</label>
                    <input type="number" step="0.5" wire:model="positiveMarks" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                </div>
                <div class="space-y-2">
                    <label class="text-sm font-medium leading-none">Negative Marks</label>
                    <input type="number" step="0.25" wire:model="negativeMarks" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-4">
            <a href="{{ route('institution.questions') }}" class="text-sm font-semibold text-muted-foreground hover:text-foreground transition-colors">Cancel</a>
            <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-primary px-8 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90 transition-all">
                Save Question
            </button>
        </div>
    </form>
</div>
