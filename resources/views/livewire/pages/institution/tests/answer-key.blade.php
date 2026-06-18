<?php

use App\Models\Test;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.institution')] class extends Component {
    public Test $test;

    public function mount(Test $test): void
    {
        abort_if($test->institution_id !== auth()->user()->institution_id, 403);
        $this->test = $test->load(['sections' => fn($q) => $q->orderBy('display_order'),
            'sections.testQuestions' => fn($q) => $q->orderBy('question_number')->with('question')]);
    }
}; ?>

<div>

<style>
@media print {
    .no-print { display: none !important; }
    .print-break { page-break-after: always; }
    body { background: white !important; }

    /* Hide sidebar, top header, and overlay */
    aside,
    header,
    body > div > div:first-child { display: none !important; }

    /* Make the content pane fill the full page */
    body > div { display: block !important; }
    body > div > div.flex-1 { width: 100% !important; padding: 0 !important; }
    main { padding: 12px 24px !important; max-width: 100% !important; }

    .answer-key-wrap { padding: 0 !important; max-width: 100% !important; }
    .ak-card { border: 1px solid #ddd !important; box-shadow: none !important; }
}
@media screen {
    .answer-key-wrap { max-width: 900px; margin: 0 auto; }
}
.correct-opt {
    background: #d1fae5;
    color: #065f46;
    border-radius: 6px;
    font-weight: 600;
    padding: 4px 10px;
}
.wrong-opt {
    color: #374151;
    padding: 4px 10px;
}
.ak-qnum {
    font-weight: 700;
    color: #1d4ed8;
    width: 36px;
    flex-shrink: 0;
    padding-top: 2px;
}
</style>

{{-- Top bar --}}
<div class="no-print flex items-center justify-between gap-4 mb-6">
    <div class="flex items-center gap-3">
        <a href="{{ route('institution.tests.show', $test) }}" wire:navigate
           class="h-9 w-9 rounded-lg border border-border flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div>
            <h1 class="text-xl font-display font-bold">Answer Key</h1>
            <p class="text-sm text-muted-foreground">{{ $test->title }}</p>
        </div>
    </div>
    <button onclick="window.print()"
            class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Download PDF
    </button>
</div>

<div class="answer-key-wrap space-y-8">

    {{-- Document Header --}}
    <div class="text-center py-6 border-b border-border">
        <h2 class="text-2xl font-bold">{{ $test->title }}</h2>
        <p class="text-muted-foreground mt-1">
            {{ strtoupper(str_replace('_',' ',$test->exam_type)) }} ·
            {{ $test->duration_minutes }} min ·
            {{ $test->total_marks ?? $test->sections->sum(fn($s) => $s->testQuestions->sum('positive_marks')) }} marks
        </p>
        <div class="mt-2 text-sm font-semibold text-primary">ANSWER KEY</div>
    </div>

    {{-- Quick Reference Table --}}
    <div class="ak-card rounded-xl border border-border bg-card overflow-hidden shadow-sm">
        <div class="px-5 py-3 border-b border-border bg-muted/20 font-semibold text-sm">Quick Reference</div>
        <div class="p-5">
            @foreach($test->sections as $section)
            <div class="mb-4 last:mb-0">
                <div class="text-xs font-bold uppercase text-muted-foreground mb-2">{{ $section->name }}</div>
                <div class="flex flex-wrap gap-2">
                    @foreach($section->testQuestions->sortBy('question_number') as $tq)
                    @php $ans = $tq->question?->correct_answer ?? '?'; @endphp
                    <div class="flex flex-col items-center text-xs">
                        <span class="text-muted-foreground">{{ $tq->question_number }}</span>
                        <span class="h-7 w-7 rounded-full bg-success/15 text-success font-bold flex items-center justify-center border border-success/30 mt-0.5">{{ $ans }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Full Questions with Answers --}}
    @foreach($test->sections as $section)
    <div class="ak-card rounded-xl border border-border bg-card overflow-hidden shadow-sm">
        <div class="px-5 py-3 border-b border-border bg-muted/20 flex items-center justify-between">
            <div class="font-semibold">{{ $section->name }}</div>
            <div class="text-xs text-muted-foreground">
                +{{ $section->positive_marks ?? $section->testQuestions->first()?->positive_marks ?? 4 }} /
                -{{ $section->negative_marks ?? $section->testQuestions->first()?->negative_marks ?? 1 }}
                per question
            </div>
        </div>

        <div class="divide-y divide-border/50">
            @foreach($section->testQuestions->sortBy('question_number') as $tq)
            @php $q = $tq->question; @endphp
            @if($q)
            <div class="p-5">
                {{-- Question --}}
                <div class="flex gap-2">
                    <span class="ak-qnum">Q{{ $tq->question_number }}.</span>
                    <div class="flex-1 text-sm leading-relaxed">{!! $q->question_text !!}</div>
                </div>

                {{-- Options --}}
                @if($q->options)
                <div class="mt-3 ml-9 grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                    @foreach($q->options as $key => $text)
                    <div class="{{ $key === $q->correct_answer ? 'correct-opt' : 'wrong-opt' }} text-sm flex items-start gap-1.5">
                        <span class="font-semibold shrink-0">({{ $key }})</span>
                        <span>{{ $text }}</span>
                        @if($key === $q->correct_answer)
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" class="shrink-0 mt-0.5"><polyline points="20 6 9 17 4 12"/></svg>
                        @endif
                    </div>
                    @endforeach
                </div>
                @endif

                {{-- Explanation --}}
                @if($q->explanation)
                <div class="mt-3 ml-9 text-xs text-muted-foreground bg-muted/40 rounded-lg px-3 py-2 leading-relaxed">
                    <span class="font-semibold text-foreground">Explanation: </span>{{ $q->explanation }}
                </div>
                @endif
            </div>
            @endif
            @endforeach
        </div>
    </div>
    @endforeach

</div>
</div>
