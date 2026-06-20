<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\Subject;
use App\Models\Chapter;
use App\Models\Question;
use App\Services\PdfExtractionService;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.institution')] class extends Component {

    public string $step         = 'upload';
    public string $uploadedPath = '';   // set by XHR upload; replaces WithFileUploads
    public string $examType     = 'neet';
    public string $subjectId    = '';
    public string $chapterId    = '';
    public array  $questions    = [];
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

    // Path is passed directly from Alpine — no $wire.set() round-trip needed.
    public function extract(string $serverPath = ''): void
    {
        $this->validate(['examType' => 'required|in:neet,jee_main,kcet']);

        $allowed = \Illuminate\Support\Facades\Storage::disk('local')->path('pdf-temp');
        if (empty($serverPath) || !str_starts_with($serverPath, $allowed) || !file_exists($serverPath)) {
            $this->errorMessage = 'No PDF uploaded. Please select a file first.';
            return;
        }

        $this->errorMessage = '';
        $this->runExtraction($serverPath);
    }

    // Process a PDF that already lives on the server (backend/files/*.pdf).
    // Bypasses the HTTP upload entirely — instant start, no upload wait.
    public function extractFromServer(string $filename): void
    {
        $path = base_path('files/' . basename($filename));
        if (!file_exists($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
            $this->errorMessage = 'File not found in the server files directory.';
            return;
        }
        $this->errorMessage = '';
        $this->runExtraction($path);
    }

    private function runExtraction(string $pdfPath): void
    {
        try {
            $results   = app(PdfExtractionService::class)->extract($pdfPath);
            $extracted = $results['clean'] ?? [];
        } catch (\Exception $e) {
            $this->errorMessage = $e->getMessage();
            return;
        }

        if (empty($extracted)) {
            $this->errorMessage = 'No questions could be extracted. Ensure the PDF contains standard 4-option MCQs.';
            return;
        }

        $subjectMap = Subject::where('exam_type', $this->examType)
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(fn ($s) => [strtolower($s->name) => $s->id])
            ->toArray();

        $this->questions = array_values(array_map(function ($q, $i) use ($subjectMap) {
            $autoName = $q['subject_auto'] ?? '';
            $sid      = $subjectMap[$autoName] ?? ($this->subjectId ?: '');

            return [
                'index'         => $i,
                'question_text' => trim($q['question'] ?? ''),
                'question_image'=> $q['diagram_url'] ?? null,
                'has_latex'     => (bool) ($q['has_latex'] ?? false),
                'options' => [
                    'A' => trim($q['options']['a'] ?? ''),
                    'B' => trim($q['options']['b'] ?? ''),
                    'C' => trim($q['options']['c'] ?? ''),
                    'D' => trim($q['options']['d'] ?? ''),
                ],
                'correct_answer' => isset($q['correct_answer'])
                    ? strtoupper(trim((string) $q['correct_answer']))
                    : '',
                'difficulty'   => $q['difficulty'] ?? 'medium',
                'subject_id'   => (string) $sid,
                'subject_auto' => $autoName,
                'selected'     => true,
            ];
        }, $extracted, array_keys($extracted)));

        $this->step = 'preview';
        $this->dispatch('questions-extracted');
    }

    public function toggleAll(bool $val): void
    {
        foreach ($this->questions as &$q) {
            $q['selected'] = $val;
        }
    }

    public function importSelected(): void
    {
        $chapterId     = $this->chapterId ?: null;
        $institutionId = Auth::user()->institution_id;
        $count = 0;

        foreach ($this->questions as $q) {
            if (!$q['selected'] || empty($q['question_text'])) continue;

            $text     = $q['question_text'];
            $hasLatex = $q['has_latex'] || str_contains($text, '$');
            $hasImage = !empty($q['question_image']);

            Question::create([
                'institution_id' => $institutionId,
                'created_by'     => Auth::id(),
                'exam_type'      => $this->examType,
                'subject_id'     => $q['subject_id'] ?: null,
                'chapter_id'     => $chapterId,
                'difficulty'     => $q['difficulty'],
                'type'           => 'single_mcq',
                'question_text'  => $text,
                'question_image' => $q['question_image'],
                'options'        => $q['options'],
                'correct_answer' => $q['correct_answer'],
                'has_latex'      => $hasLatex,
                'has_image'      => $hasImage,
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
        $filesDir    = base_path('files');
        $serverFiles = collect(is_dir($filesDir) ? glob($filesDir . '/*.pdf') ?: [] : [])
            ->map(fn ($p) => basename($p))
            ->values();

        return [
            'subjects'    => $this->examType
                ? Subject::where('exam_type', $this->examType)->where('is_active', true)->get()
                : collect(),
            'chapters'    => $this->subjectId
                ? Chapter::where('subject_id', $this->subjectId)->get()
                : collect(),
            'chapterName' => $this->chapterId ? Chapter::find($this->chapterId)?->name : '',
            'serverFiles' => $serverFiles,
        ];
    }
}; ?>

{{-- KaTeX for rendering LaTeX math inside question previews --}}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.10/dist/katex.min.css" crossorigin="anonymous">
<script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.10/dist/katex.min.js" crossorigin="anonymous"></script>
<script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.10/dist/contrib/auto-render.min.js"
    crossorigin="anonymous"
    onload="window._katexReady = true; window._renderAllMath && window._renderAllMath()">
</script>

<script>
window._katexOpts = {
    delimiters: [
        { left: '$$', right: '$$', display: true  },
        { left: '$',  right: '$',  display: false },
    ],
    throwOnError: false,
};

// Render math inside all .math-content elements
window._renderAllMath = function () {
    if (!window._katexReady || !window.renderMathInElement) return;
    document.querySelectorAll('.math-content').forEach(function (el) {
        renderMathInElement(el, window._katexOpts);
    });
};

// Re-render after each Livewire DOM update
document.addEventListener('livewire:updated', function () {
    setTimeout(window._renderAllMath, 80);
});

// Render when Livewire signals extraction is done
document.addEventListener('questions-extracted', function () {
    setTimeout(window._renderAllMath, 150);
});
</script>

<div class="space-y-6 max-w-4xl">

    {{-- Header --}}
    <div class="flex items-center gap-4">
        <a href="{{ route('institution.questions') }}" wire:navigate
           class="h-9 w-9 rounded-lg border border-border flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div>
            <h1 class="text-2xl font-display font-bold">Import Questions from PDF</h1>
            <p class="text-sm text-muted-foreground mt-0.5">
                Upload any question bank PDF — our AI extracts all MCQs, math formulas, diagrams, and auto-detects subjects
            </p>
        </div>
    </div>

    {{-- ── STEP: UPLOAD ──────────────────────────────────────────────── --}}
    @if($step === 'upload')
    <div class="rounded-xl border border-border bg-card p-6 space-y-5">

        {{-- Exam type --}}
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

        {{-- Optional subject / chapter filter --}}
        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-semibold mb-1.5">
                    Default Subject
                    <span class="text-xs font-normal text-muted-foreground">(AI auto-detects per question)</span>
                </label>
                <select wire:model.live="subjectId"
                        class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    <option value="">Auto-detect from each question</option>
                    @foreach($subjects as $sub)
                    <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-semibold mb-1.5">
                    Chapter
                    <span class="text-xs font-normal text-muted-foreground">(optional)</span>
                </label>
                <select wire:model.live="chapterId"
                        class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        @disabled(!$subjectId)>
                    <option value="">All chapters / Not specified</option>
                    @foreach($chapters as $ch)
                    <option value="{{ $ch->id }}">{{ $ch->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Livewire-reactive error block OUTSIDE wire:ignore so it updates on re-render --}}
        @if($errorMessage)
        <div class="rounded-lg bg-destructive/10 border border-destructive/30 text-destructive px-4 py-3 text-sm flex gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="shrink-0 mt-0.5"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
            {{ $errorMessage }}
        </div>
        @endif

        {{-- wire:ignore keeps Alpine state alive across every Livewire re-render.
             XHR upload — no $wire.upload() (session-lock) and no $wire.set() (race condition). --}}
        <div wire:ignore
            x-data="{
                csrfToken: '{{ csrf_token() }}',
                uploadUrl: '{{ route('institution.questions.upload-pdf-temp') }}',
                serverPath: '',
                fileName: '',
                fileSize: '',
                uploading: false,
                uploaded: false,
                extracting: false,
                progress: 0,
                localError: '',
                runExtract() {
                    if (!this.uploaded || !this.serverPath || this.extracting) return;
                    this.extracting = true;
                    this.localError = '';
                    $wire.extract(this.serverPath)
                        .then(() => { this.extracting = false; })
                        .catch(() => {
                            this.extracting = false;
                            this.localError = 'Extraction failed — please try again.';
                        });
                },
                selectFile(files) {
                    const file = files && files[0];
                    if (!file) return;
                    if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
                        this.localError = 'Only PDF files are accepted.';
                        return;
                    }
                    if (file.size > 25 * 1024 * 1024) {
                        this.localError = 'File exceeds the 25 MB limit.';
                        return;
                    }
                    this.localError  = '';
                    this.uploading   = true;
                    this.uploaded    = false;
                    this.serverPath  = '';
                    this.progress    = 0;
                    this.fileName    = file.name;
                    this.fileSize    = file.size > 1048576
                        ? (file.size / 1048576).toFixed(1) + ' MB'
                        : Math.round(file.size / 1024) + ' KB';

                    const fd = new FormData();
                    fd.append('pdf', file);
                    fd.append('_token', this.csrfToken);

                    const xhr = new XMLHttpRequest();

                    xhr.upload.addEventListener('progress', e => {
                        if (e.lengthComputable) this.progress = Math.round((e.loaded / e.total) * 100);
                    });

                    xhr.onload = () => {
                        this.uploading = false;
                        if (xhr.status === 200) {
                            const data    = JSON.parse(xhr.responseText);
                            this.serverPath = data.path;  // kept in Alpine only — passed to Livewire on click
                            this.uploaded  = true;
                        } else {
                            this.localError = 'Upload failed (HTTP ' + xhr.status + ') — please try again.';
                        }
                    };

                    xhr.onerror = () => {
                        this.uploading  = false;
                        this.localError = 'Upload failed — network error. Please try again.';
                    };

                    xhr.open('POST', this.uploadUrl);
                    xhr.send(fd);
                }
            }"
        >
            <label class="block text-sm font-semibold mb-1.5">
                Question Bank PDF <span class="text-destructive">*</span>
            </label>

            {{-- Hidden real file input --}}
            <input type="file" accept=".pdf" x-ref="pdfInput" class="sr-only"
                   @change="selectFile($event.target.files)">

            {{-- Dropzone --}}
            <div class="rounded-xl border-2 border-dashed px-6 py-10 flex flex-col items-center gap-3 text-center transition-all duration-200"
                 :class="{
                     'border-green-400 bg-green-50/50 cursor-pointer'                                         : uploaded && !uploading,
                     'border-primary/40 bg-primary/5 cursor-wait'                                             : uploading,
                     'border-border bg-muted/30 hover:border-primary/50 hover:bg-primary/5 cursor-pointer'    : !uploaded && !uploading
                 }"
                 @click="if (!uploading) $refs.pdfInput.click()"
                 @dragover.prevent
                 @drop.prevent="selectFile($event.dataTransfer.files)">

                {{-- Empty state --}}
                <div x-show="!uploading && !uploaded" class="flex flex-col items-center gap-3">
                    <div class="h-12 w-12 rounded-full bg-primary/10 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="text-primary">
                            <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <path d="M12 12v6"/><path d="M9 15l3 3 3-3"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold">Drop PDF here or click to browse</p>
                        <p class="text-xs text-muted-foreground mt-1">PDF only · Max 25 MB · Drag & drop supported</p>
                    </div>
                </div>

                {{-- Uploading state --}}
                <div x-show="uploading" class="flex flex-col items-center gap-4 w-full">
                    <div class="h-12 w-12 rounded-full bg-primary/10 flex items-center justify-center">
                        <svg class="animate-spin h-6 w-6 text-primary" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                    </div>
                    <div class="text-center">
                        <p class="text-sm font-semibold text-primary" x-text="fileName"></p>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            Uploading… <span class="font-semibold" x-text="progress + '%'"></span>
                        </p>
                    </div>
                    <div class="w-48 h-1.5 bg-primary/20 rounded-full overflow-hidden">
                        <div class="h-full bg-primary rounded-full transition-all duration-200"
                             :style="'width:' + progress + '%'"></div>
                    </div>
                </div>

                {{-- Uploaded state --}}
                <div x-show="uploaded && !uploading" class="flex flex-col items-center gap-3">
                    <div class="h-12 w-12 rounded-full bg-green-100 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-green-600">
                            <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <path d="M20 6 9 17l-5-5"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-green-700" x-text="fileName"></p>
                        <p class="text-xs text-muted-foreground mt-0.5" x-text="fileSize + ' · Click to change'"></p>
                    </div>
                    <span class="inline-flex items-center gap-1.5 text-xs text-green-700 bg-green-50 ring-1 ring-green-200 rounded-full px-3 py-1 font-semibold">
                        <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg>
                        Ready to extract
                    </span>
                </div>
            </div>

            {{-- Client-side errors (upload type/size/network — Alpine only, no Livewire needed) --}}
            <p x-show="localError" x-text="localError" class="text-xs text-destructive mt-1.5"></p>

            {{-- Server file picker — PDFs placed in backend/files/ appear here instantly (no upload needed) --}}
            @if($serverFiles->count() > 0)
            <div class="mt-3 pt-3 border-t border-border">
                <p class="text-xs font-semibold text-muted-foreground mb-2">
                    Or extract directly from a server file (no upload):
                </p>
                <div class="flex flex-wrap gap-2">
                    @foreach($serverFiles as $file)
                    <button wire:click="extractFromServer('{{ $file }}')"
                            wire:loading.attr="disabled"
                            wire:target="extractFromServer"
                            type="button"
                            class="inline-flex items-center gap-1.5 text-xs rounded-lg border border-border bg-background px-3 py-1.5 hover:bg-primary/5 hover:border-primary/40 transition-colors font-medium text-foreground">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                        {{ $file }}
                        <span wire:loading wire:target="extractFromServer('{{ $file }}')" class="ml-1">
                            <svg class="animate-spin h-3 w-3 text-primary" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        </span>
                    </button>
                    @endforeach
                </div>
                <p class="text-[11px] text-muted-foreground/60 mt-1.5">
                    Drop any PDF into <code class="font-mono bg-muted px-1 rounded">backend/files/</code> and reload to see it here.
                </p>
            </div>
            @endif

            {{-- Capabilities info --}}
            <div class="rounded-lg bg-primary/5 border border-primary/15 px-4 py-3 space-y-1.5 mt-4">
                <p class="text-xs font-semibold text-primary">What this extractor handles</p>
                <div class="grid grid-cols-2 gap-x-6 gap-y-1">
                    @foreach([
                        'Typed / digital PDFs',
                        'Scanned / image PDFs (OCR)',
                        'Complex math & formulas (LaTeX)',
                        'Chemical structures & equations',
                        'Figures, graphs & circuit diagrams',
                        'Auto subject detection per question',
                        'Answer key extraction',
                        'Mixed-language papers',
                    ] as $cap)
                    <div class="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-success shrink-0"><path d="M20 6 9 17l-5-5"/></svg>
                        {{ $cap }}
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Extract button — loading managed by Alpine so it works inside wire:ignore --}}
            <button type="button"
                    @click="runExtract()"
                    :disabled="!uploaded || extracting"
                    class="mt-4 inline-flex items-center gap-2 rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                <span x-show="!extracting" class="flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21 11-8-8-8 8"/><path d="M3 12v7a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M12 3v13"/></svg>
                    Extract Questions
                </span>
                <span x-show="extracting" class="flex items-center gap-2">
                    <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    Analysing PDF with AI… 1–3 minutes
                </span>
            </button>
        </div>
    </div>
    @endif

    {{-- ── STEP: PREVIEW ─────────────────────────────────────────────── --}}
    @if($step === 'preview')
    <div class="space-y-4" x-data="{ selectAll: true }">

        {{-- Summary bar --}}
        <div class="rounded-xl border border-border bg-card px-5 py-4 flex flex-wrap items-center gap-4 justify-between">
            <div>
                <p class="font-semibold text-base">{{ count($questions) }} questions extracted</p>
                <p class="text-sm text-muted-foreground mt-0.5">
                    <span class="uppercase text-xs font-semibold text-primary">{{ strtoupper(str_replace('_', ' ', $examType)) }}</span>
                    @if($chapterName) · <span class="font-medium text-foreground">{{ $chapterName }}</span>@endif
                    · Review, adjust subjects, then import
                </p>
            </div>
            <div class="flex items-center gap-3 flex-wrap">
                <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
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

        {{-- Question cards --}}
        <div class="space-y-3">
        @foreach($questions as $qi => $q)
        <div wire:key="qcard-{{ $qi }}"
             class="rounded-xl border border-border bg-card p-4 flex gap-3 transition-opacity {{ !$q['selected'] ? 'opacity-50' : '' }}">

            {{-- Checkbox --}}
            <input type="checkbox"
                   wire:model.live="questions.{{ $qi }}.selected"
                   class="mt-1 rounded border-input text-primary shrink-0 cursor-pointer">

            <div class="flex-1 min-w-0 space-y-3">

                {{-- Question text — wire:ignore so Livewire doesn't overwrite KaTeX-rendered HTML --}}
                <div wire:ignore
                     class="text-sm font-medium leading-relaxed math-content"
                     data-raw="{{ $q['question_text'] }}">
                    <span class="text-muted-foreground font-normal">Q{{ $qi + 1 }}.</span>
                    {{ $q['question_text'] ?: '(empty)' }}
                </div>

                {{-- Diagram image --}}
                @if($q['question_image'])
                <div class="border rounded-lg p-1.5 bg-slate-50 inline-block max-w-[320px]">
                    <img src="{{ asset($q['question_image']) }}"
                         alt="Extracted diagram for Q{{ $qi + 1 }}"
                         class="max-w-full h-auto rounded">
                    <p class="text-[10px] text-muted-foreground mt-1 text-center">Extracted diagram</p>
                </div>
                @endif

                {{-- Options --}}
                <div wire:ignore class="grid sm:grid-cols-2 gap-1.5 math-content">
                    @foreach(['A','B','C','D'] as $opt)
                    <div class="flex items-start gap-1.5 text-xs rounded-lg px-2.5 py-2
                                {{ $q['correct_answer'] === $opt
                                    ? 'bg-success/10 text-success font-semibold ring-1 ring-success/20'
                                    : 'bg-muted/40 text-muted-foreground' }}">
                        <span class="font-bold shrink-0 mt-px">{{ $opt }}.</span>
                        <span class="leading-relaxed">{{ $q['options'][$opt] ?? '—' }}</span>
                        @if($q['correct_answer'] === $opt)
                        <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" class="shrink-0 ml-auto mt-0.5"><path d="M20 6 9 17l-5-5"/></svg>
                        @endif
                    </div>
                    @endforeach
                </div>

                {{-- Meta row --}}
                <div class="flex items-center gap-2 flex-wrap pt-0.5">

                    {{-- Answer missing badge --}}
                    @if(!$q['correct_answer'])
                    <span class="inline-flex items-center gap-1 text-xs text-amber-700 bg-amber-50 ring-1 ring-amber-200 rounded px-2 py-0.5">
                        <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>
                        Answer not found
                    </span>
                    @endif

                    {{-- LaTeX badge --}}
                    @if($q['has_latex'])
                    <span class="text-xs text-purple-700 bg-purple-50 ring-1 ring-purple-200 rounded px-2 py-0.5">∑ LaTeX</span>
                    @endif

                    {{-- Subject dropdown --}}
                    <div class="flex items-center gap-1 text-xs">
                        <span class="text-muted-foreground">Subject:</span>
                        <select wire:model.live="questions.{{ $qi }}.subject_id"
                                class="rounded border-border bg-background px-1.5 py-0.5 text-xs focus:border-primary focus:outline-none font-medium">
                            <option value="">— Unassigned —</option>
                            @foreach($subjects as $sub)
                            <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                            @endforeach
                        </select>
                        @if($q['subject_auto'])
                        <span class="text-muted-foreground/60">(detected: {{ ucfirst($q['subject_auto']) }})</span>
                        @endif
                    </div>

                    {{-- Difficulty dropdown --}}
                    <div class="flex items-center gap-1 text-xs text-muted-foreground">
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

        {{-- Sticky import bar --}}
        <div class="sticky bottom-4 rounded-xl border border-border bg-card/95 backdrop-blur shadow-lg px-5 py-4 flex items-center justify-between gap-4">
            <p class="text-sm text-muted-foreground">
                <span class="font-semibold text-foreground">{{ $this->selectedCount() }}</span>
                of {{ count($questions) }} questions selected
            </p>
            <button wire:click="importSelected"
                    wire:loading.attr="disabled"
                    wire:target="importSelected"
                    @disabled($this->selectedCount() === 0)
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                <span wire:loading.remove wire:target="importSelected">
                    Import {{ $this->selectedCount() }} Questions
                </span>
                <span wire:loading wire:target="importSelected" class="flex items-center gap-2">
                    <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    Importing…
                </span>
            </button>
        </div>
    </div>
    @endif

    {{-- ── STEP: DONE ─────────────────────────────────────────────────── --}}
    @if($step === 'done')
    <div class="rounded-xl border border-border bg-card p-10 flex flex-col items-center text-center gap-4">
        <div class="h-16 w-16 rounded-full bg-success/15 flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-success"><path d="M20 6 9 17l-5-5"/></svg>
        </div>
        <div>
            <h2 class="text-xl font-display font-bold">{{ $importedCount }} Questions Imported!</h2>
            <p class="text-sm text-muted-foreground mt-1">They are now in your question bank, ready to use in tests</p>
        </div>
        <div class="flex items-center gap-3 mt-2">
            <a href="{{ route('institution.questions') }}" wire:navigate
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
