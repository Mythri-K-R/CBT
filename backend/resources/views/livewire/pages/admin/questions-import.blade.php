<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithFileUploads;
use App\Models\Subject;
use App\Models\Chapter;
use App\Models\Question;
use App\Services\PdfExtractionService;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.admin')] class extends Component {
    use WithFileUploads;

    public string $step = 'upload';
    public $pdf = null;
    public string $examType  = 'neet';
    public string $subjectId = '';
    public string $chapterId = '';
    public array  $questions = [];
    public string $errorMessage = '';
    public int    $importedCount = 0;

    public function updatedExamType(): void
    {
        $this->subjectId = '';
        $this->chapterId = '';
    }

    public function updatedSubjectId(): void
    {
        $this->chapterId = '';
    }

    public function extract(): void
    {
        $this->validate([
            'pdf'       => 'required|file|mimes:pdf|max:10240',
            'examType'  => 'required|in:neet,jee_main,kcet',
            'subjectId' => 'nullable|exists:subjects,id',
        ]);

        $this->errorMessage = '';

        try {
            $results = app(PdfExtractionService::class)->extract($this->pdf->getRealPath());
            $extracted = $results['clean'] ?? [];
        } catch (\Exception $e) {
            $this->errorMessage = $e->getMessage();
            return;
        }

        if (empty($extracted)) {
            $this->errorMessage = 'No questions could be extracted from this PDF.';
            return;
        }

        $subjectMap = Subject::where('exam_type', $this->examType)->get()->mapWithKeys(function ($s) {
            return [strtolower($s->name) => $s->id];
        })->toArray();

        $this->questions = array_values(array_map(function ($q, $i) use ($subjectMap) {
            $autoSubjectName = $q['subject_auto'] ?? 'general';
            $autoSubjectId = $subjectMap[$autoSubjectName] ?? $this->subjectId;
            
            return [
                'index'          => $i,
                'question_text'  => trim($q['question'] ?? ''),
                'question_image' => $q['diagram_url'] ?? null,
                'options'        => [
                    'A' => trim($q['options']['a'] ?? ''),
                    'B' => trim($q['options']['b'] ?? ''),
                    'C' => trim($q['options']['c'] ?? ''),
                    'D' => trim($q['options']['d'] ?? ''),
                ],
                'correct_answer' => isset($q['correct_answer']) ? strtoupper(trim((string)$q['correct_answer'])) : '',
                'difficulty'     => 'medium',
                'subject_id'     => $autoSubjectId,
                'subject_auto'   => $autoSubjectName,
                'selected'       => true,
            ];
        }, $extracted, array_keys($extracted)));

        $this->step = 'preview';
    }

    public function toggleAll(bool $val): void
    {
        foreach ($this->questions as &$q) {
            $q['selected'] = $val;
        }
    }

    public function importSelected(): void
    {
        $count = 0;
        foreach ($this->questions as $q) {
            if (!$q['selected'] || empty($q['question_text'])) continue;

            Question::create([
                'institution_id' => null,
                'created_by'     => Auth::id(),
                'exam_type'      => $this->examType,
                'subject_id'     => $q['subject_id'] ?: $this->subjectId,
                'chapter_id'     => $this->chapterId ?: null,
                'difficulty'     => $q['difficulty'],
                'type'           => 'single_mcq',
                'question_text'  => $q['question_text'],
                'question_image' => $q['question_image'],
                'options'        => $q['options'],
                'correct_answer' => $q['correct_answer'],
                'positive_marks' => 4.0,
                'negative_marks' => 1.0,
                'status'         => 'active',
            ]);
            $count++;
        }

        $this->importedCount = $count;
        $this->step = 'done';
    }

    public function selectedCount(): int
    {
        return count(array_filter($this->questions, fn($q) => $q['selected']));
    }

    public function with(): array
    {
        return [
            'subjects'    => $this->examType ? Subject::where('exam_type', $this->examType)->where('is_active', true)->get() : collect(),
            'chapters'    => $this->subjectId ? Chapter::where('subject_id', $this->subjectId)->get() : collect(),
            'subjectName' => $this->subjectId ? Subject::find($this->subjectId)?->name : '',
            'chapterName' => $this->chapterId ? Chapter::find($this->chapterId)?->name : '',
        ];
    }
}; ?>

<div class="space-y-6 max-w-4xl">

    <div class="flex items-center gap-4">
        <a href="{{ route('admin.questions') }}" wire:navigate
           class="h-9 w-9 rounded-lg border border-border flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div>
            <h1 class="text-2xl font-display font-bold">Import Questions from PDF</h1>
            <p class="text-sm text-muted-foreground mt-0.5">Upload a question bank PDF — our system will extract all MCQs automatically</p>
        </div>
    </div>

    @if($step === 'upload')
    <div class="rounded-xl border border-border bg-card p-6 space-y-5">

        <div>
            <label class="block text-sm font-semibold mb-2">Exam Type</label>
            <div class="flex flex-wrap gap-2">
                @foreach(['neet' => 'NEET', 'jee_main' => 'JEE Main', 'kcet' => 'KCET'] as $key => $label)
                <button wire:click="$set('examType','{{ $key }}')" type="button"
                        class="px-4 py-1.5 rounded-full text-sm font-semibold border transition-colors
                               {{ $examType === $key ? 'bg-primary text-primary-foreground border-primary' : 'bg-background border-border text-muted-foreground hover:border-primary/50' }}">
                    {{ $label }}
                </button>
                @endforeach
            </div>
        </div>

        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-semibold mb-1.5">Subject <span class="text-xs font-normal text-muted-foreground">(Optional - Auto Detected)</span></label>
                <select wire:model.live="subjectId" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    <option value="">Auto-detect from questions…</option>
                    @foreach($subjects as $sub)
                    <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                    @endforeach
                </select>
                @error('subjectId')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm font-semibold mb-1.5">Chapter <span class="text-xs font-normal text-muted-foreground">(optional)</span></label>
                <select wire:model.live="chapterId" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" @disabled(!$subjectId)>
                    <option value="">All chapters / Not specified</option>
                    @foreach($chapters as $ch)
                    <option value="{{ $ch->id }}">{{ $ch->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <label class="block text-sm font-semibold mb-1.5">Question Bank PDF <span class="text-destructive">*</span></label>
            <label for="pdf-upload"
                   class="relative flex flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed border-border bg-muted/30 px-6 py-10 cursor-pointer hover:border-primary/50 hover:bg-primary/5 transition-colors group">
                <div class="h-12 w-12 rounded-full bg-primary/10 flex items-center justify-center group-hover:bg-primary/15 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="text-primary"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><path d="M12 12v6"/><path d="M9 15l3 3 3-3"/></svg>
                </div>
                @if($pdf)
                <div class="text-center">
                    <p class="text-sm font-semibold text-primary">{{ $pdf->getClientOriginalName() }}</p>
                    <p class="text-xs text-muted-foreground mt-0.5">{{ round($pdf->getSize() / 1024) }} KB · Click to change</p>
                </div>
                @else
                <div class="text-center">
                    <p class="text-sm font-semibold">Drop PDF here or click to browse</p>
                    <p class="text-xs text-muted-foreground mt-1">PDF only · Max 10 MB</p>
                </div>
                @endif
                <input id="pdf-upload" wire:model="pdf" type="file" accept=".pdf" class="absolute inset-0 opacity-0 cursor-pointer">
            </label>
            @error('pdf')<p class="text-xs text-destructive mt-1.5">{{ $message }}</p>@enderror
            <div wire:loading wire:target="pdf" class="mt-1.5 text-xs text-primary flex items-center gap-1.5">
                <svg class="animate-spin h-3 w-3" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                Uploading…
            </div>
        </div>

        @if($errorMessage)
        <div class="rounded-lg bg-destructive/10 border border-destructive/30 text-destructive px-4 py-3 text-sm">{{ $errorMessage }}</div>
        @endif

        <div class="rounded-lg bg-info/10 border border-info/20 px-4 py-3 flex gap-3">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-info shrink-0 mt-0.5"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
            <div class="text-xs text-info/90">
                <p class="font-semibold">Powered by ExamSphere PDF Extractor</p>
                <p class="mt-0.5 text-info/70">Works best with typed PDFs. Scanned images may have lower accuracy. Questions will be extracted with options and correct answers. Review before importing.</p>
            </div>
        </div>

        <button wire:click="extract"
                wire:loading.attr="disabled"
                wire:target="extract"
                class="inline-flex items-center gap-2 rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
            <span wire:loading.remove wire:target="extract">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="inline -mt-0.5 mr-1"><path d="m21 11-8-8-8 8"/><path d="M3 12v7a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M12 3v13"/></svg>
                Extract Questions
            </span>
            <span wire:loading wire:target="extract" class="flex items-center gap-2">
                <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                Reading your PDF… this may take 15–30 seconds
            </span>
        </button>
    </div>
    @endif

    @if($step === 'preview')
    <div class="space-y-4" x-data="{ selectAll: true }">
        <div class="rounded-xl border border-border bg-card px-5 py-4 flex flex-wrap items-center gap-4 justify-between">
            <div>
                <p class="font-semibold text-base">{{ count($questions) }} questions extracted</p>
                <p class="text-sm text-muted-foreground mt-0.5">
                    Importing to: <span class="font-medium text-foreground">{{ $subjectName }}</span>
                    @if($chapterName) <span class="text-muted-foreground"> › </span><span class="font-medium text-foreground">{{ $chapterName }}</span>@endif
                    · <span class="uppercase text-xs font-semibold text-primary">{{ strtoupper(str_replace('_', ' ', $examType)) }}</span>
                    · <span class="text-xs text-muted-foreground">Platform-wide</span>
                </p>
            </div>
            <div class="flex items-center gap-3 flex-wrap">
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="checkbox" x-model="selectAll"
                           @change="$wire.toggleAll($el.checked)"
                           class="rounded border-input text-primary">
                    Select all
                </label>
                <button wire:click="$set('step','upload')" type="button"
                        class="text-sm text-muted-foreground hover:text-foreground transition-colors underline underline-offset-2">
                    ← Back
                </button>
            </div>
        </div>

        <div class="space-y-3">
            @foreach($questions as $qi => $q)
            <div class="rounded-xl border border-border bg-card p-4 flex gap-3 {{ !$q['selected'] ? 'opacity-50' : '' }}">
                <input type="checkbox"
                       wire:model.live="questions.{{ $qi }}.selected"
                       class="mt-1 rounded border-input text-primary shrink-0">
                <div class="flex-1 min-w-0 space-y-2.5">
                    <p class="text-sm font-medium leading-relaxed">
                        <span class="text-muted-foreground font-normal mr-1">Q{{ $qi + 1 }}.</span>
                        {{ $q['question_text'] ?: '(empty question)' }}
                    </p>
                    @if($q['question_image'])
                    <div class="my-2 border rounded p-1 bg-white inline-block max-w-[200px]">
                        <img src="{{ asset($q['question_image']) }}" alt="Extracted diagram" class="max-w-full h-auto">
                    </div>
                    @endif
                    <div class="grid sm:grid-cols-2 gap-1.5">
                        @foreach(['A','B','C','D'] as $opt)
                        <div class="flex items-start gap-1.5 text-xs rounded-lg px-2.5 py-1.5
                                    {{ $q['correct_answer'] === $opt ? 'bg-success/10 text-success font-semibold' : 'bg-muted/40 text-muted-foreground' }}">
                            <span class="font-bold shrink-0">{{ $opt }}.</span>
                            <span>{{ $q['options'][$opt] ?? '—' }}</span>
                            @if($q['correct_answer'] === $opt)
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="shrink-0 ml-auto mt-0.5"><path d="M20 6 9 17l-5-5"/></svg>
                            @endif
                        </div>
                        @endforeach
                    </div>
                    <div class="flex items-center gap-3 flex-wrap">
                        @if(!$q['correct_answer'])
                        <span class="text-xs text-warning bg-warning/10 rounded px-2 py-0.5">Answer not found</span>
                        @endif
                        <span class="text-xs text-primary bg-primary/10 rounded px-2 py-0.5 capitalize flex items-center gap-1"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/></svg> {{ $q['subject_auto'] }}</span>
                        <div class="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <span>Difficulty:</span>
                            <select wire:model.live="questions.{{ $qi }}.difficulty"
                                    class="rounded border-border bg-background px-1.5 py-0.5 text-xs focus:border-primary focus:outline-none">
                                <option value="easy">Easy</option>
                                <option value="medium">Medium</option>
                                <option value="hard">Hard</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        <div class="sticky bottom-4 rounded-xl border border-border bg-card/95 backdrop-blur shadow-lg px-5 py-4 flex items-center justify-between gap-4">
            <p class="text-sm text-muted-foreground">
                <span class="font-semibold text-foreground">{{ $this->selectedCount() }}</span> of {{ count($questions) }} questions selected
            </p>
            <button wire:click="importSelected"
                    wire:loading.attr="disabled"
                    wire:target="importSelected"
                    @disabled($this->selectedCount() === 0)
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                <span wire:loading.remove wire:target="importSelected">Import {{ $this->selectedCount() }} Questions</span>
                <span wire:loading wire:target="importSelected" class="flex items-center gap-2">
                    <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    Importing…
                </span>
            </button>
        </div>
    </div>
    @endif

    @if($step === 'done')
    <div class="rounded-xl border border-border bg-card p-10 flex flex-col items-center text-center gap-4">
        <div class="h-16 w-16 rounded-full bg-success/15 flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-success"><path d="M20 6 9 17l-5-5"/></svg>
        </div>
        <div>
            <h2 class="text-xl font-display font-bold">{{ $importedCount }} Questions Imported!</h2>
            <p class="text-sm text-muted-foreground mt-1">They are now in the platform question bank</p>
        </div>
        <div class="flex items-center gap-3 mt-2">
            <a href="{{ route('admin.questions') }}" wire:navigate
               class="inline-flex items-center gap-2 rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">
                View Question Bank
            </a>
            <button wire:click="$set('step','upload')" type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-border px-5 py-2.5 text-sm font-semibold hover:bg-muted transition-colors">
                Import Another PDF
            </button>
        </div>
    </div>
    @endif

</div>
