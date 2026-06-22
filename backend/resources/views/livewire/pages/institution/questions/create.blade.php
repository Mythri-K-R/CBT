<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithFileUploads;
use App\Models\Subject;
use App\Models\Chapter;
use App\Models\Question;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.institution')] class extends Component {
    use WithFileUploads;

    // ── Classification ──────────────────────────────────────────────────────
    public string $examType    = 'neet';
    public string $subjectId   = '';
    public string $chapterId   = '';
    public string $difficulty  = 'medium';
    public string $type        = 'single_mcq';

    // ── Content (synced from Alpine on submit via $wire.set) ────────────────
    public string $questionText  = '';
    public string $correctAnswer = '';
    public string $source        = '';
    public string $explanation   = '';
    public array  $options       = ['A' => '', 'B' => '', 'C' => '', 'D' => ''];

    // ── Marks ───────────────────────────────────────────────────────────────
    public float $positiveMarks = 4.0;
    public float $negativeMarks = 1.0;

    // ── Images (Livewire file uploads) ──────────────────────────────────────
    public $questionImage = null;
    public $optionImageA  = null;
    public $optionImageB  = null;
    public $optionImageC  = null;
    public $optionImageD  = null;

    public function with(): array
    {
        return [
            'subjects' => Subject::where('exam_type', $this->examType)->where('is_active', true)->get(),
            'chapters' => $this->subjectId
                ? Chapter::where('subject_id', $this->subjectId)->where('is_active', true)->get()
                : collect(),
        ];
    }

    public function updatedExamType(): void  { $this->subjectId = ''; $this->chapterId = ''; }
    public function updatedSubjectId(): void { $this->chapterId = ''; }

    public function removeQuestionImage(): void { $this->questionImage = null; }
    public function removeOptionImage(string $key): void
    {
        $prop = 'optionImage' . $key;
        $this->$prop = null;
    }

    public function save(): void
    {
        $this->validate([
            'subjectId'     => 'required',
            'chapterId'     => 'required',
            'questionText'  => 'required|string|min:5',
            'correctAnswer' => 'required',
            'questionImage' => 'nullable|image|max:3072',
            'optionImageA'  => 'nullable|image|max:2048',
            'optionImageB'  => 'nullable|image|max:2048',
            'optionImageC'  => 'nullable|image|max:2048',
            'optionImageD'  => 'nullable|image|max:2048',
        ], [
            'subjectId.required'    => 'Please select a subject.',
            'chapterId.required'    => 'Please select a chapter.',
            'questionText.required' => 'Question text cannot be empty.',
            'questionText.min'      => 'Question is too short.',
            'correctAnswer.required'=> 'Please mark the correct answer.',
        ]);

        $questionImagePath = $this->questionImage
            ? $this->questionImage->store('question-images', 'public')
            : null;

        $optionsData = null;
        if (in_array($this->type, ['single_mcq', 'multiple_mcq'])) {
            $imgs = [
                'A' => $this->optionImageA,
                'B' => $this->optionImageB,
                'C' => $this->optionImageC,
                'D' => $this->optionImageD,
            ];
            $optionsData = [];
            foreach (['A', 'B', 'C', 'D'] as $k) {
                $optionsData[] = [
                    'key'   => $k,
                    'text'  => $this->options[$k] ?? '',
                    'image' => $imgs[$k] ? $imgs[$k]->store('question-images', 'public') : null,
                ];
            }
        }

        $hasLatex = str_contains($this->questionText, '$')
            || str_contains($this->questionText, '\\');

        $hasImage = $questionImagePath !== null
            || collect($optionsData ?? [])->contains(fn ($o) => $o['image'] !== null);

        Question::create([
            'institution_id'  => Auth::user()->institution_id,
            'created_by'      => Auth::id(),
            'exam_type'       => $this->examType,
            'subject_id'      => $this->subjectId,
            'chapter_id'      => $this->chapterId,
            'difficulty'      => $this->difficulty,
            'type'            => $this->type,
            'question_text'   => $this->questionText,
            'question_image'  => $questionImagePath,
            'options'         => $optionsData,
            'correct_answer'  => strtoupper(trim($this->correctAnswer)),
            'positive_marks'  => $this->positiveMarks,
            'negative_marks'  => $this->negativeMarks,
            'source'          => $this->source ?: null,
            'explanation'     => $this->explanation ?: null,
            'has_latex'       => $hasLatex,
            'has_image'       => $hasImage,
            'source_type'     => 'manual',
            'ai_review_status'=> 'not_applicable',
            'status'          => 'active',
        ]);

        session()->flash('status', 'Question saved successfully.');
        $this->redirect(route('institution.questions'), navigate: true);
    }
}; ?>

{{-- ── MathJax (loaded once per page via @assets) ─────────────────────── --}}
@assets
<script>
window.MathJax = {
    tex: {
        inlineMath: [['$','$'],['\\(','\\)']],
        displayMath: [['$$','$$'],['\\[','\\]']],
        tags: 'none',
    },
    svg: { fontCache: 'global' },
    startup: { typeset: false },
};
</script>
<script src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-svg.js" async id="MathJax-script"></script>
<style>
    .q-editor-textarea {
        font-family: 'JetBrains Mono', 'Fira Code', 'Courier New', monospace;
        font-size: 13px;
        line-height: 1.7;
        resize: vertical;
    }
    .symbol-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 32px;
        height: 30px;
        padding: 0 6px;
        font-size: 13px;
        border-radius: 5px;
        border: 1px solid hsl(var(--border));
        background: hsl(var(--background));
        color: hsl(var(--foreground));
        cursor: pointer;
        transition: background 0.12s, border-color 0.12s;
        user-select: none;
    }
    .symbol-btn:hover { background: hsl(var(--accent)); border-color: hsl(var(--primary)/0.4); }
    .opt-card { transition: box-shadow 0.15s, border-color 0.15s; }
    .opt-card.correct { border-color: hsl(var(--success)); box-shadow: 0 0 0 3px hsl(var(--success)/0.12); }
    .mathjax-preview { min-height: 60px; line-height: 1.8; overflow-x: auto; }
    .mathjax-preview mjx-container { overflow-x: auto; }
</style>
@endassets

{{-- ── Page ────────────────────────────────────────────────────────────── --}}
<div class="max-w-5xl mx-auto space-y-6 pb-12"
     x-data="questionCreator()"
     x-init="init()">

    {{-- Header --}}
    <div class="flex items-center gap-4">
        <a href="{{ route('institution.questions') }}"
           class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-secondary hover:bg-secondary/80 text-secondary-foreground transition-colors shrink-0">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div>
            <h1 class="text-2xl font-display font-bold tracking-tight">Add Question</h1>
            <p class="text-sm text-muted-foreground mt-0.5">Supports LaTeX equations, chemical formulas, and images.</p>
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════
         SECTION 1 — Classification
         (wire:model.live here — triggers re-renders which is fine because
          the editor sections below are inside wire:ignore blocks)
    ═══════════════════════════════════════════════════════════════════ --}}
    <div class="rounded-xl border border-border bg-card p-6 shadow-sm space-y-5">
        <div class="flex items-center gap-3 border-b border-border/50 pb-4">
            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground">1</span>
            <h2 class="text-base font-semibold">Classification</h2>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="space-y-1.5">
                <label class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Exam</label>
                <select wire:model.live="examType"
                        class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    <option value="neet">NEET</option>
                    <option value="jee_main">JEE Main</option>
                    <option value="kcet">KCET</option>
                </select>
            </div>

            <div class="space-y-1.5">
                <label class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Subject</label>
                <select wire:model.live="subjectId"
                        class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    <option value="">Select...</option>
                    @foreach($subjects as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
                @error('subjectId') <p class="text-[11px] text-destructive">{{ $message }}</p> @enderror
            </div>

            <div class="space-y-1.5">
                <label class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Chapter</label>
                <select wire:model="chapterId"
                        {{ empty($chapters->toArray()) ? 'disabled' : '' }}
                        class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary disabled:opacity-50">
                    <option value="">Select...</option>
                    @foreach($chapters as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
                @error('chapterId') <p class="text-[11px] text-destructive">{{ $message }}</p> @enderror
            </div>

            <div class="space-y-1.5">
                <label class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Difficulty</label>
                <select wire:model="difficulty"
                        class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    <option value="easy">Easy</option>
                    <option value="medium">Medium</option>
                    <option value="hard">Hard / Tough</option>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div class="space-y-1.5">
                <label class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Question Type</label>
                <select wire:model.live="type" @change="onTypeChange($event.target.value)"
                        class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    <option value="single_mcq">Single Choice MCQ</option>
                    <option value="multiple_mcq">Multiple Correct MCQ</option>
                    <option value="numerical">Numerical / Integer</option>
                </select>
            </div>
            <div class="space-y-1.5">
                <label class="text-xs font-medium text-muted-foreground uppercase tracking-wide">PYQ Source (optional)</label>
                <input type="text" wire:model="source"
                       placeholder="e.g. NEET 2023, JEE 2022 Shift-1"
                       class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary placeholder:text-muted-foreground">
            </div>
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════
         SECTION 2 — Question Editor
         Entire block is wire:ignore — Alpine manages editor state.
         Values synced to Livewire on save via $wire.set().
    ═══════════════════════════════════════════════════════════════════ --}}
    <div class="rounded-xl border border-border bg-card shadow-sm overflow-hidden" wire:ignore>
        <div class="flex items-center gap-3 px-6 py-4 border-b border-border/50">
            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground">2</span>
            <h2 class="text-base font-semibold flex-1">Question Editor</h2>
            {{-- Preview toggle --}}
            <button type="button" @click="showPreview = !showPreview"
                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium border border-border hover:bg-accent transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                <span x-text="showPreview ? 'Hide Preview' : 'Show Preview'">Show Preview</span>
            </button>
        </div>

        {{-- ── Symbol / LaTeX Toolbar ──────────────────────────────────── --}}
        <div class="border-b border-border/50 bg-muted/30 px-4 py-2">
            {{-- Tab switcher --}}
            <div class="flex items-center gap-1 mb-2">
                <button type="button" @click="toolbarTab = 'math'"
                        :class="toolbarTab === 'math' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground hover:bg-accent'"
                        class="px-2.5 py-1 rounded text-xs font-medium transition-colors">Math</button>
                <button type="button" @click="toolbarTab = 'greek'"
                        :class="toolbarTab === 'greek' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground hover:bg-accent'"
                        class="px-2.5 py-1 rounded text-xs font-medium transition-colors">Greek</button>
                <button type="button" @click="toolbarTab = 'chem'"
                        :class="toolbarTab === 'chem' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground hover:bg-accent'"
                        class="px-2.5 py-1 rounded text-xs font-medium transition-colors">Chemistry</button>
                <button type="button" @click="toolbarTab = 'tmpl'"
                        :class="toolbarTab === 'tmpl' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground hover:bg-accent'"
                        class="px-2.5 py-1 rounded text-xs font-medium transition-colors">Templates</button>
                <div class="h-4 w-px bg-border mx-1"></div>
                {{-- Wrap in $ --}}
                <button type="button" @click="wrapMath()"
                        title="Wrap selection in $…$ (inline math)"
                        class="symbol-btn text-primary font-bold">$</button>
                <button type="button" @click="wrapDisplayMath()"
                        title="Wrap selection in $$…$$ (display math)"
                        class="symbol-btn text-primary font-bold">$$</button>
            </div>

            {{-- Math symbols --}}
            <div x-show="toolbarTab === 'math'" class="flex flex-wrap gap-1">
                <template x-for="s in symbols.math" :key="s.l">
                    <button type="button" @click="insert(s.l)" :title="s.t" class="symbol-btn" x-text="s.d"></button>
                </template>
            </div>

            {{-- Greek letters --}}
            <div x-show="toolbarTab === 'greek'" class="flex flex-wrap gap-1">
                <template x-for="s in symbols.greek" :key="s.l">
                    <button type="button" @click="insert(s.l)" :title="s.t" class="symbol-btn" x-text="s.d"></button>
                </template>
            </div>

            {{-- Chemistry --}}
            <div x-show="toolbarTab === 'chem'" class="flex flex-wrap gap-1">
                <template x-for="s in symbols.chem" :key="s.l">
                    <button type="button" @click="insert(s.l)" :title="s.t" class="symbol-btn text-xs leading-tight" x-html="s.d"></button>
                </template>
            </div>

            {{-- LaTeX templates --}}
            <div x-show="toolbarTab === 'tmpl'" class="flex flex-wrap gap-1.5">
                <template x-for="t in templates" :key="t.l">
                    <button type="button" @click="insertTemplate(t.l, t.cur)"
                            :title="t.t"
                            class="symbol-btn px-2 text-xs font-mono text-primary">
                        <span x-text="t.d"></span>
                    </button>
                </template>
            </div>
        </div>

        {{-- ── Editor + Preview ─────────────────────────────────────── --}}
        <div :class="showPreview ? 'grid grid-cols-2 divide-x divide-border' : 'block'">

            {{-- Textarea --}}
            <div class="p-4">
                <textarea x-ref="qtext"
                          @input="schedulePreview()"
                          rows="10"
                          placeholder="Type your question here. Use $...$ for inline math (e.g. $H_2SO_4$) and $$...$$ for display equations."
                          class="q-editor-textarea w-full rounded-lg border border-input bg-background px-3 py-2.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary placeholder:text-muted-foreground"></textarea>
                <p class="mt-1.5 text-[11px] text-muted-foreground">
                    Use <code class="bg-muted px-1 rounded">$...$</code> for inline math · <code class="bg-muted px-1 rounded">$$...$$</code> for equations · <code class="bg-muted px-1 rounded">\frac{a}{b}</code> for fractions
                </p>
            </div>

            {{-- Live preview --}}
            <div x-show="showPreview" class="p-4 bg-muted/20">
                <p class="text-[11px] font-medium text-muted-foreground mb-3 uppercase tracking-wide">Live Preview</p>
                <div x-ref="preview"
                     class="mathjax-preview text-sm text-foreground"
                     x-html="previewHtml">
                    <span class="text-muted-foreground italic text-xs">Start typing to see rendered preview…</span>
                </div>
            </div>
        </div>

        {{-- ── Question image upload ─────────────────────────────────── --}}
        <div class="border-t border-border/50 px-4 py-4">
            <p class="text-xs font-medium text-muted-foreground mb-2 uppercase tracking-wide">Question Image (optional)</p>

            {{-- No image yet --}}
            <div x-show="!questionImagePreview && !hasServerQImage"
                 class="relative flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-border hover:border-primary/50 bg-muted/20 hover:bg-muted/40 transition-colors cursor-pointer p-6 text-center"
                 @click="$refs.qimgInput.click()"
                 @dragover.prevent
                 @drop.prevent="handleQImageDrop($event)">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="text-muted-foreground mb-2"><rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                <p class="text-sm font-medium">Drag image here or <span class="text-primary underline">browse</span></p>
                <p class="text-xs text-muted-foreground mt-1">PNG, JPG, WEBP — max 3 MB</p>
            </div>

            {{-- Image preview (after Alpine-side drop or Livewire upload) --}}
            <div x-show="questionImagePreview || hasServerQImage" class="relative inline-block">
                <img :src="questionImagePreview || serverQImageUrl"
                     class="max-h-52 rounded-lg border border-border object-contain bg-muted/30"
                     alt="Question image">
                <button type="button"
                        @click="removeQImage()"
                        class="absolute -top-2 -right-2 h-6 w-6 flex items-center justify-center rounded-full bg-destructive text-white shadow-sm hover:bg-destructive/80 text-xs font-bold">✕</button>
            </div>

            {{-- Hidden Livewire file input --}}
            <input type="file" x-ref="qimgInput" accept="image/*" class="hidden"
                   wire:model="questionImage"
                   @change="onQImageSelected($event)">
            @error('questionImage') <p class="text-[11px] text-destructive mt-1">{{ $message }}</p> @enderror

            {{-- Livewire upload progress --}}
            <div wire:loading wire:target="questionImage" class="mt-2 text-xs text-muted-foreground flex items-center gap-1.5">
                <svg class="animate-spin h-3 w-3" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                Uploading…
            </div>

            @if($questionImage)
                <div class="mt-2">
                    <p class="text-[11px] text-success font-medium">✓ Image ready (will save with question)</p>
                </div>
            @endif
        </div>
    </div>

    {{-- Validation errors that live OUTSIDE wire:ignore so Livewire can update them --}}
    @error('questionText')
        <p class="text-sm text-destructive flex items-center gap-1.5 -mt-3">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            {{ $message }}
        </p>
    @enderror

    {{-- ════════════════════════════════════════════════════════════════
         SECTION 3 — Answer Options  (wire:ignore; Alpine manages UI)
    ═══════════════════════════════════════════════════════════════════ --}}
    <div class="rounded-xl border border-border bg-card p-6 shadow-sm space-y-4" wire:ignore>
        <div class="flex items-center gap-3 border-b border-border/50 pb-4">
            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground">3</span>
            <h2 class="text-base font-semibold flex-1">Answer Options</h2>
            <span class="text-xs text-muted-foreground" x-text="currentType === 'numerical' ? 'Enter the numerical answer below' : 'Click an option border to mark it correct'"></span>
        </div>

        {{-- MCQ options grid — rendered statically by Blade (no x-for inside wire:ignore) --}}
        <div x-show="currentType !== 'numerical'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            @foreach(['A','B','C','D'] as $optKey)
            <div class="opt-card rounded-xl border-2 cursor-pointer transition-all"
                 :class="localCorrect === '{{ $optKey }}' ? 'correct border-success bg-success/5' : 'border-border hover:border-primary/40'"
                 @click="setCorrect('{{ $optKey }}')">

                {{-- Option header --}}
                <div class="flex items-center gap-2 px-4 pt-3 pb-2">
                    <div class="h-5 w-5 rounded-full border-2 flex items-center justify-center shrink-0 transition-colors"
                         :class="localCorrect === '{{ $optKey }}' ? 'border-success bg-success' : 'border-muted-foreground/40'">
                        <svg x-show="localCorrect === '{{ $optKey }}'" xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <span class="flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-bold transition-colors"
                          :class="localCorrect === '{{ $optKey }}' ? 'bg-success text-white' : 'bg-secondary text-secondary-foreground'">{{ $optKey }}</span>
                    <span class="text-xs font-medium" :class="localCorrect === '{{ $optKey }}' ? 'text-success' : 'text-muted-foreground'"
                          x-text="localCorrect === '{{ $optKey }}' ? 'Correct Answer' : 'Click to mark correct'"></span>
                </div>

                {{-- Option text --}}
                <div class="px-4 pb-3">
                    <textarea @click.stop
                              @input="optValues['{{ $optKey }}'] = $el.value; scheduleOptPreview('{{ $optKey }}', $el.value)"
                              rows="2"
                              placeholder="Option {{ $optKey }} — supports $LaTeX$ inline"
                              class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary resize-none placeholder:text-muted-foreground font-mono text-xs"></textarea>

                    {{-- Mini LaTeX preview --}}
                    <div x-show="optPreviews['{{ $optKey }}']"
                         class="mt-1.5 px-2 py-1 bg-muted/30 rounded text-xs text-foreground mathjax-preview"
                         data-optprev="{{ $optKey }}"
                         x-html="optPreviews['{{ $optKey }}']"></div>
                </div>

                {{-- Option image --}}
                <div class="px-4 pb-3" @click.stop>
                    <div x-show="!optImages['{{ $optKey }}']"
                         @click="document.getElementById('opt-img-{{ $optKey }}').click()"
                         class="flex items-center gap-2 text-xs text-muted-foreground hover:text-primary cursor-pointer transition-colors w-fit">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                        Add image to this option
                    </div>
                    <div x-show="optImages['{{ $optKey }}']" class="relative inline-block mt-1">
                        <img :src="optImages['{{ $optKey }}']" class="max-h-24 rounded border border-border object-contain" alt="Option {{ $optKey }} image">
                        <button type="button" @click.stop="removeOptImage('{{ $optKey }}')"
                                class="absolute -top-1.5 -right-1.5 h-5 w-5 flex items-center justify-center rounded-full bg-destructive text-white text-[10px]">✕</button>
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        {{-- Numerical answer --}}
        <div x-show="currentType === 'numerical'" class="space-y-2">
            <label class="text-sm font-medium">Correct Answer <span class="text-muted-foreground font-normal">(numeric value)</span></label>
            <input type="text" x-ref="numericalAns"
                   @input="localCorrect = $el.value"
                   placeholder="e.g. 42 or 3.14 or -9.8"
                   class="w-full max-w-xs rounded-lg border border-success/50 bg-success/5 px-3 py-2 text-sm font-mono focus:border-success focus:outline-none focus:ring-1 focus:ring-success">
            <p class="text-xs text-muted-foreground">For ranges or tolerance: enter the exact expected value.</p>
        </div>

        {{-- Correct answer badge (always visible as feedback) --}}
        <div x-show="localCorrect" class="flex items-center gap-2 pt-1">
            <span class="text-xs text-muted-foreground">Marked correct:</span>
            <span class="inline-flex items-center gap-1 rounded-full bg-success/10 border border-success/30 text-success px-3 py-1 text-xs font-bold" x-text="localCorrect ? 'Option ' + localCorrect : ''"></span>
        </div>
    </div>

    {{-- Hidden file inputs for option images — OUTSIDE wire:ignore so Livewire manages them --}}
    <div class="hidden">
        <input type="file" id="opt-img-A" accept="image/*" wire:model="optionImageA">
        <input type="file" id="opt-img-B" accept="image/*" wire:model="optionImageB">
        <input type="file" id="opt-img-C" accept="image/*" wire:model="optionImageC">
        <input type="file" id="opt-img-D" accept="image/*" wire:model="optionImageD">
    </div>

    @error('correctAnswer')
        <p class="text-sm text-destructive flex items-center gap-1.5 -mt-3">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            {{ $message }}
        </p>
    @enderror

    {{-- ════════════════════════════════════════════════════════════════
         SECTION 4 — Marks & Explanation
    ═══════════════════════════════════════════════════════════════════ --}}
    <div class="rounded-xl border border-border bg-card p-6 shadow-sm space-y-5">
        <div class="flex items-center gap-3 border-b border-border/50 pb-4">
            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground">4</span>
            <h2 class="text-base font-semibold">Marks & Explanation</h2>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div class="space-y-1.5">
                <label class="text-xs font-medium text-muted-foreground uppercase tracking-wide flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-success inline-block"></span>
                    Correct (+marks)
                </label>
                <input type="number" step="0.5" min="0" wire:model="positiveMarks"
                       class="w-full rounded-lg border border-success/40 bg-success/5 px-3 py-2 text-sm font-medium focus:border-success focus:outline-none focus:ring-1 focus:ring-success">
            </div>
            <div class="space-y-1.5">
                <label class="text-xs font-medium text-muted-foreground uppercase tracking-wide flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-destructive inline-block"></span>
                    Wrong (−marks)
                </label>
                <input type="number" step="0.25" min="0" wire:model="negativeMarks"
                       class="w-full rounded-lg border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm font-medium focus:border-destructive focus:outline-none focus:ring-1 focus:ring-destructive">
            </div>
        </div>

        <div class="space-y-1.5">
            <label class="text-xs font-medium text-muted-foreground uppercase tracking-wide">Solution / Explanation <span class="normal-case font-normal">(optional — shown to students after test)</span></label>
            <textarea wire:model="explanation" rows="3"
                      placeholder="Write the step-by-step solution or key concept explanation here…"
                      class="w-full rounded-lg border border-input bg-background px-3 py-2.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary placeholder:text-muted-foreground resize-none"></textarea>
        </div>
    </div>

    {{-- ── Action Bar ─────────────────────────────────────────────────── --}}
    <div class="flex items-center justify-between rounded-xl border border-border bg-card px-6 py-4 shadow-sm sticky bottom-4">
        <div class="text-xs text-muted-foreground" wire:loading wire:target="save">
            <span class="flex items-center gap-1.5">
                <svg class="animate-spin h-3.5 w-3.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                Saving question…
            </span>
        </div>
        <div class="ml-auto flex items-center gap-4">
            <a href="{{ route('institution.questions') }}"
               class="text-sm font-semibold text-muted-foreground hover:text-foreground transition-colors">Cancel</a>
            <button type="button"
                    @click="submitQuestion()"
                    wire:loading.attr="disabled" wire:target="save"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-6 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90 disabled:opacity-60 transition-all">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Save Question
            </button>
        </div>
    </div>
</div>

{{-- ── Alpine component ──────────────────────────────────────────────── --}}
<script>
function questionCreator() {
    return {
        // Editor state
        showPreview:  false,
        toolbarTab:   'math',
        previewHtml:  '',
        previewTimer: null,
        currentType:  '{{ $type }}',

        // Correct answer (local Alpine copy, synced to $wire on save)
        localCorrect: '',

        // Option texts — tracked here so submitQuestion() can read them reliably
        optValues: { A: '', B: '', C: '', D: '' },

        // Question image
        questionImagePreview: null,
        hasServerQImage: false,
        serverQImageUrl: '',

        // Option images (local preview URLs)
        optImages: { A: null, B: null, C: null, D: null },

        // Option previews (rendered LaTeX)
        optPreviews: { A: '', B: '', C: '', D: '' },
        optPreviewTimers: {},

        // ── Symbol tables ────────────────────────────────────────────────
        symbols: {
            math: [
                {d:'±', l:'±', t:'Plus-minus'},
                {d:'×', l:'×', t:'Multiply'},
                {d:'÷', l:'÷', t:'Divide'},
                {d:'≠', l:'≠', t:'Not equal'},
                {d:'≤', l:'≤', t:'Less or equal'},
                {d:'≥', l:'≥', t:'Greater or equal'},
                {d:'≈', l:'≈', t:'Approximately equal'},
                {d:'∞', l:'∞', t:'Infinity'},
                {d:'√', l:'$\\sqrt{}$', t:'Square root (LaTeX)'},
                {d:'∫', l:'$\\int$', t:'Integral'},
                {d:'∂', l:'$\\partial$', t:'Partial derivative'},
                {d:'∑', l:'$\\sum$', t:'Summation'},
                {d:'∏', l:'$\\prod$', t:'Product'},
                {d:'→', l:'→', t:'Arrow right'},
                {d:'←', l:'←', t:'Arrow left'},
                {d:'↔', l:'↔', t:'Arrow both'},
                {d:'⇒', l:'⇒', t:'Implies'},
                {d:'°', l:'°', t:'Degree'},
            ],
            greek: [
                {d:'α',l:'α',t:'Alpha'},{d:'β',l:'β',t:'Beta'},
                {d:'γ',l:'γ',t:'Gamma'},{d:'δ',l:'δ',t:'Delta'},
                {d:'ε',l:'ε',t:'Epsilon'},{d:'θ',l:'θ',t:'Theta'},
                {d:'λ',l:'λ',t:'Lambda'},{d:'μ',l:'μ',t:'Mu'},
                {d:'ν',l:'ν',t:'Nu'},{d:'π',l:'π',t:'Pi'},
                {d:'ρ',l:'ρ',t:'Rho'},{d:'σ',l:'σ',t:'Sigma'},
                {d:'τ',l:'τ',t:'Tau'},{d:'φ',l:'φ',t:'Phi'},
                {d:'ω',l:'ω',t:'Omega'},{d:'Δ',l:'Δ',t:'Delta (upper)'},
                {d:'Λ',l:'Λ',t:'Lambda (upper)'},{d:'Σ',l:'Σ',t:'Sigma (upper)'},
                {d:'Ω',l:'Ω',t:'Omega (upper)'},{d:'Φ',l:'Φ',t:'Phi (upper)'},
            ],
            chem: [
                {d:'⇌',l:'⇌',t:'Equilibrium arrow'},
                {d:'↑',l:'↑',t:'Gas evolved'},
                {d:'↓',l:'↓',t:'Precipitate'},
                {d:'Δ',l:'Δ',t:'Heat (Delta)'},
                {d:'H₂O',l:'H₂O',t:'Water'},
                {d:'CO₂',l:'CO₂',t:'Carbon dioxide'},
                {d:'H₂SO₄',l:'H₂SO₄',t:'Sulphuric acid'},
                {d:'HCl',l:'HCl',t:'Hydrochloric acid'},
                {d:'NaOH',l:'NaOH',t:'Sodium hydroxide'},
                {d:'NH₃',l:'NH₃',t:'Ammonia'},
                {d:'Ca²⁺',l:'Ca²⁺',t:'Calcium ion'},
                {d:'Na⁺',l:'Na⁺',t:'Sodium ion'},
                {d:'Cl⁻',l:'Cl⁻',t:'Chloride ion'},
                {d:'OH⁻',l:'OH⁻',t:'Hydroxide ion'},
                {d:'°C',l:'°C',t:'Celsius'},
                {d:'mol/L',l:'mol/L',t:'Molarity unit'},
            ],
        },

        templates: [
            {d:'a/b (fraction)',  l:'\\frac{a}{b}',      cur:6,  t:'Fraction — replace a and b'},
            {d:'√x (root)',       l:'\\sqrt{x}',          cur:6,  t:'Square root'},
            {d:'ⁿ√x (nth root)', l:'\\sqrt[n]{x}',       cur:7,  t:'nth root'},
            {d:'x² (power)',      l:'x^{2}',              cur:3,  t:'Superscript / power'},
            {d:'xₙ (subscript)', l:'x_{n}',              cur:3,  t:'Subscript'},
            {d:'∫ dx',            l:'\\int_{a}^{b} x\\,dx',cur:5, t:'Definite integral'},
            {d:'lim',             l:'\\lim_{x \\to 0}',   cur:13, t:'Limit'},
            {d:'A→B (rxn)',       l:'A \\xrightarrow{} B',cur:17, t:'Chemical reaction arrow'},
            {d:'A⇌B (eq)',        l:'A \\rightleftharpoons B',cur:23,t:'Chemical equilibrium'},
            {d:'⟨vec⟩',          l:'\\vec{v}',           cur:5,  t:'Vector'},
            {d:'|x| (abs)',       l:'|x|',                cur:1,  t:'Absolute value'},
            {d:'x̄ (bar)',         l:'\\bar{x}',           cur:5,  t:'Bar (mean)'},
            {d:'matrix',          l:'\\begin{pmatrix} a & b \\\\ c & d \\end{pmatrix}',cur:20,t:'2×2 matrix'},
        ],

        // ── Lifecycle ────────────────────────────────────────────────────
        init() {
            // Wire up option image file inputs (outside wire:ignore) to Alpine for preview
            ['A','B','C','D'].forEach(k => {
                const el = document.getElementById('opt-img-' + k);
                if (el) {
                    el.addEventListener('change', (e) => {
                        const file = e.target.files[0];
                        if (file) this.optImages[k] = URL.createObjectURL(file);
                    });
                }
            });

            // Render preview whenever the panel is toggled on
            this.$watch('showPreview', val => {
                if (val) this.$nextTick(() => this.renderPreview());
            });
        },

        onTypeChange(val) {
            this.currentType = val;
            if (val === 'numerical') this.localCorrect = '';
        },

        // ── Symbol / template insertion ──────────────────────────────────
        insert(text) {
            const ta = this.$refs.qtext;
            if (!ta) return;
            ta.focus();
            const s = ta.selectionStart, e = ta.selectionEnd;
            ta.value = ta.value.slice(0, s) + text + ta.value.slice(e);
            ta.selectionStart = ta.selectionEnd = s + text.length;
            ta.dispatchEvent(new Event('input'));
        },

        wrapMath() {
            const ta = this.$refs.qtext;
            if (!ta) return;
            ta.focus();
            const s = ta.selectionStart, e = ta.selectionEnd;
            const sel = ta.value.slice(s, e) || 'x';
            const wrap = '$' + sel + '$';
            ta.value = ta.value.slice(0, s) + wrap + ta.value.slice(e);
            ta.selectionStart = s + 1;
            ta.selectionEnd   = s + 1 + sel.length;
            ta.dispatchEvent(new Event('input'));
        },

        wrapDisplayMath() {
            const ta = this.$refs.qtext;
            if (!ta) return;
            ta.focus();
            const s = ta.selectionStart, e = ta.selectionEnd;
            const sel = ta.value.slice(s, e) || 'x';
            const wrap = '\n$$\n' + sel + '\n$$\n';
            ta.value = ta.value.slice(0, s) + wrap + ta.value.slice(e);
            ta.dispatchEvent(new Event('input'));
        },

        insertTemplate(text, cursorOffset) {
            const ta = this.$refs.qtext;
            if (!ta) return;
            ta.focus();
            const s = ta.selectionStart;
            ta.value = ta.value.slice(0, s) + text + ta.value.slice(ta.selectionEnd);
            ta.selectionStart = ta.selectionEnd = s + (cursorOffset || text.length);
            ta.dispatchEvent(new Event('input'));
        },

        // ── Live preview ─────────────────────────────────────────────────
        schedulePreview() {
            clearTimeout(this.previewTimer);
            this.previewTimer = setTimeout(() => this.renderPreview(), 400);
        },

        renderPreview() {
            const ta = this.$refs.qtext;
            if (!ta || !this.showPreview) return;
            const raw = ta.value;
            // Convert newlines to <br> for display
            this.previewHtml = raw.replace(/\n/g, '<br>');
            this.$nextTick(() => {
                if (window.MathJax) {
                    MathJax.typesetClear([this.$refs.preview]);
                    MathJax.typesetPromise([this.$refs.preview]).catch(console.error);
                }
            });
        },

        // init() also sets up a watcher for showPreview → re-renders when toggled on

        // ── Option previews ──────────────────────────────────────────────
        scheduleOptPreview(key, value) {
            clearTimeout(this.optPreviewTimers[key]);
            this.optPreviewTimers[key] = setTimeout(() => this.renderOptPreview(key, value), 400);
        },

        renderOptPreview(key, value) {
            if (!value || !value.includes('$')) {
                this.optPreviews[key] = '';
                return;
            }
            this.optPreviews[key] = value.replace(/\n/g, '<br>');
            this.$nextTick(() => {
                const el = document.querySelector('[data-optprev="' + key + '"]');
                if (el && window.MathJax) {
                    MathJax.typesetClear([el]);
                    MathJax.typesetPromise([el]).catch(console.error);
                }
            });
        },

        // ── Correct answer ───────────────────────────────────────────────
        setCorrect(key) {
            this.localCorrect = key;
            // Immediately sync to Livewire (safe — editor is inside wire:ignore)
            $wire.set('correctAnswer', key);
        },

        // ── Question image handling ──────────────────────────────────────
        onQImageSelected(event) {
            const file = event.target.files[0];
            if (file) this.questionImagePreview = URL.createObjectURL(file);
        },

        handleQImageDrop(event) {
            const file = event.dataTransfer.files[0];
            if (!file || !file.type.startsWith('image/')) return;
            // Assign to the Livewire input
            const dt = new DataTransfer();
            dt.items.add(file);
            this.$refs.qimgInput.files = dt.files;
            this.$refs.qimgInput.dispatchEvent(new Event('change', { bubbles: true }));
            this.questionImagePreview = URL.createObjectURL(file);
        },

        removeQImage() {
            this.questionImagePreview = null;
            this.hasServerQImage = false;
            this.$refs.qimgInput.value = '';
            $wire.removeQuestionImage();
        },

        // ── Option images ────────────────────────────────────────────────
        removeOptImage(key) {
            this.optImages[key] = null;
            const el = document.getElementById('opt-img-' + key);
            if (el) {
                el.value = '';
                // Trigger Livewire to clear the property
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
            $wire.removeOptionImage(key);
        },

        // ── Submit — syncs all Alpine-managed values to Livewire ─────────
        submitQuestion() {
            // Sync question text from textarea ref
            const ta = this.$refs.qtext;
            if (ta) $wire.set('questionText', ta.value);

            // Sync option texts from tracked optValues (reliable, no DOM queries)
            ['A','B','C','D'].forEach(k => {
                $wire.set('options.' + k, this.optValues[k] || '');
            });

            // Sync correct answer (already synced on click, but ensure once more)
            $wire.set('correctAnswer', this.localCorrect);

            // Let $wire.set calls propagate, then call save
            this.$nextTick(() => $wire.save());
        },
    };
}
</script>
