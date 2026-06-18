<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\Test;
use App\Models\TestSection;
use App\Models\TestLink;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

new #[Layout('layouts.institution')] class extends Component {
    public string $examType = '';
    public ?int $confirmDelete = null;

    public function mount(string $examType): void
    {
        if (!in_array($examType, ['neet', 'jee_main', 'kcet'])) abort(404);
        $this->examType = $examType;
    }

    // ── Previous-years template definitions ─────────────────────────────────

    private function templates(): array
    {
        $neetSections = [
            ['name' => 'Physics',   'count' => 45, 'pos' => 4, 'neg' => 1],
            ['name' => 'Chemistry', 'count' => 45, 'pos' => 4, 'neg' => 1],
            ['name' => 'Botany',    'count' => 45, 'pos' => 4, 'neg' => 1],
            ['name' => 'Zoology',   'count' => 45, 'pos' => 4, 'neg' => 1],
        ];
        $jeeSections = [
            ['name' => 'Physics',     'count' => 30, 'pos' => 4, 'neg' => 1],
            ['name' => 'Chemistry',   'count' => 30, 'pos' => 4, 'neg' => 1],
            ['name' => 'Mathematics', 'count' => 30, 'pos' => 4, 'neg' => 1],
        ];
        $kcetSections = [
            ['name' => 'Mathematics', 'count' => 60, 'pos' => 1, 'neg' => 0],
            ['name' => 'Physics',     'count' => 60, 'pos' => 1, 'neg' => 0],
            ['name' => 'Chemistry',   'count' => 60, 'pos' => 1, 'neg' => 0],
        ];

        $all = [
            'neet'     => array_map(fn($y) => ['name' => "NEET {$y}", 'year' => $y, 'duration' => 200, 'sections' => $neetSections], [2024, 2023, 2022, 2021, 2020, 2019]),
            'jee_main' => array_map(fn($y) => ['name' => "JEE Main {$y}",  'year' => $y, 'duration' => 180, 'sections' => $jeeSections],  [2024, 2023, 2022, 2021, 2020, 2019]),
            'kcet'     => array_map(fn($y) => ['name' => "KCET {$y}",      'year' => $y, 'duration' => 80,  'sections' => $kcetSections],  [2024, 2023, 2022, 2021, 2020, 2019]),
        ];

        return $all[$this->examType] ?? [];
    }

    // ── Actions ──────────────────────────────────────────────────────────────

    public function createBlank(): void
    {
        $labels = ['neet' => 'NEET', 'jee_main' => 'JEE Main', 'kcet' => 'KCET'];
        $label  = $labels[$this->examType] ?? strtoupper($this->examType);

        $existing = Test::where('institution_id', Auth::user()->institution_id)
            ->where('exam_type', $this->examType)
            ->where('test_category', 'full_length')
            ->count();

        $test = Test::create([
            'institution_id'   => Auth::user()->institution_id,
            'created_by'       => Auth::id(),
            'title'            => "{$label} Full Length Paper " . ($existing + 1),
            'exam_type'        => $this->examType,
            'test_category'    => 'full_length',
            'duration_minutes' => 180,
            'status'           => 'draft',
            'total_marks'      => 0,
        ]);

        $this->redirect(route('institution.papers.editor', $test), navigate: false);
    }

    public function cloneTemplate(int $idx): void
    {
        $templates = $this->templates();
        $tpl = $templates[$idx] ?? null;
        if (!$tpl) return;

        $totalQ     = array_sum(array_column($tpl['sections'], 'count'));
        $totalMarks = array_sum(array_map(fn($s) => $s['count'] * $s['pos'], $tpl['sections']));

        DB::transaction(function () use ($tpl, $totalQ, $totalMarks) {
            $test = Test::create([
                'institution_id'   => Auth::user()->institution_id,
                'created_by'       => Auth::id(),
                'title'            => $tpl['name'],
                'exam_type'        => $this->examType,
                'test_category'    => 'full_length',
                'duration_minutes' => $tpl['duration'],
                'status'           => 'draft',
                'total_questions'  => $totalQ,
                'total_marks'      => $totalMarks,
            ]);

            foreach ($tpl['sections'] as $order => $sec) {
                TestSection::create([
                    'test_id'        => $test->id,
                    'name'           => $sec['name'],
                    'question_count' => $sec['count'],
                    'positive_marks' => $sec['pos'],
                    'negative_marks' => $sec['neg'],
                    'display_order'  => $order + 1,
                ]);
            }

            $this->redirect(route('institution.papers.editor', $test), navigate: false);
        });
    }

    public function confirmDeletePaper(int $id): void
    {
        $this->confirmDelete = $id;
    }

    public function deletePaper(): void
    {
        if (!$this->confirmDelete) return;
        $paper = Test::where('institution_id', Auth::user()->institution_id)
            ->where('test_category', 'full_length')
            ->find($this->confirmDelete);

        $paper?->delete();
        $this->confirmDelete = null;
    }

    public function with(): array
    {
        $papers = Test::withCount('testQuestions as question_count')
            ->where('institution_id', Auth::user()->institution_id)
            ->where('exam_type', $this->examType)
            ->where('test_category', 'full_length')
            ->orderByDesc('updated_at')
            ->get();

        return [
            'papers'    => $papers,
            'templates' => $this->templates(),
            'examLabel' => ['neet' => 'NEET', 'jee_main' => 'JEE Main', 'kcet' => 'KCET'][$this->examType] ?? strtoupper($this->examType),
        ];
    }
}; ?>

<div class="space-y-8">

    {{-- Header --}}
    <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <a href="{{ route('institution.papers') }}" wire:navigate
               class="h-9 w-9 rounded-lg border border-border flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
            </a>
            <div>
                <h1 class="text-2xl font-display font-bold">{{ $examLabel }} — Full Length Papers</h1>
                <p class="text-sm text-muted-foreground mt-0.5">{{ $papers->count() }} paper{{ $papers->count() != 1 ? 's' : '' }} · Click any paper to edit questions</p>
            </div>
        </div>
        <button wire:click="createBlank"
                class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
            New Blank Paper
        </button>
    </div>

    {{-- My Papers --}}
    @if($papers->isNotEmpty())
    <div class="rounded-xl border border-border bg-card overflow-hidden shadow-sm">
        <div class="px-5 py-3 border-b border-border/50 bg-muted/20 flex items-center justify-between">
            <span class="font-semibold text-sm">My Papers</span>
        </div>
        <table class="w-full text-sm">
            <thead class="border-b border-border/50 bg-muted/30">
                <tr class="text-xs text-muted-foreground uppercase font-semibold">
                    <th class="text-left px-5 py-3">Title</th>
                    <th class="text-center px-4 py-3">Questions</th>
                    <th class="text-center px-4 py-3">Status</th>
                    <th class="text-right px-4 py-3">Last Updated</th>
                    <th class="text-right px-5 py-3">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border/40">
                @foreach($papers as $paper)
                <tr class="hover:bg-muted/30 transition-colors group">
                    <td class="px-5 py-4">
                        <a href="{{ route('institution.papers.editor', $paper) }}" wire:navigate
                           class="font-semibold text-foreground group-hover:text-primary transition-colors">
                            {{ $paper->title }}
                        </a>
                        <div class="text-xs text-muted-foreground mt-0.5">{{ strtoupper($paper->exam_type) }}</div>
                    </td>
                    <td class="px-4 py-4 text-center">
                        <span class="font-bold text-primary">{{ $paper->question_count }}</span>
                        @if($paper->total_questions > 0)
                        <span class="text-muted-foreground text-xs"> / {{ $paper->total_questions }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-4 text-center">
                        @php
                            $cfg = match($paper->status) {
                                'draft'     => ['bg-warning/10 text-warning ring-warning/20', 'Draft'],
                                'scheduled' => ['bg-info/10 text-info ring-info/20', 'Scheduled'],
                                'live'      => ['bg-success/10 text-success ring-success/20', 'Live'],
                                'completed' => ['bg-muted text-muted-foreground ring-border', 'Completed'],
                                default     => ['bg-muted text-muted-foreground ring-border', ucfirst($paper->status)],
                            };
                        @endphp
                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $cfg[0] }}">{{ $cfg[1] }}</span>
                    </td>
                    <td class="px-4 py-4 text-right text-xs text-muted-foreground">
                        {{ $paper->updated_at->diffForHumans() }}
                    </td>
                    <td class="px-5 py-4">
                        <div class="flex items-center justify-end gap-1">
                            <a href="{{ route('institution.papers.editor', $paper) }}" wire:navigate
                               class="h-7 px-3 rounded text-xs font-medium bg-primary/10 text-primary hover:bg-primary/20 transition-colors flex items-center gap-1">
                                <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                Edit
                            </a>
                            <button wire:click="confirmDeletePaper({{ $paper->id }})"
                                    class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                            </button>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- Previous Years Templates --}}
    <div class="rounded-xl border border-border bg-card overflow-hidden shadow-sm">
        <div class="px-5 py-3 border-b border-border/50 bg-muted/20">
            <div class="font-semibold text-sm">Previous Years Papers — Templates</div>
            <div class="text-xs text-muted-foreground mt-0.5">Clone a template to get the correct section structure. Add questions from your bank afterward.</div>
        </div>
        <div class="divide-y divide-border/40">
            @foreach($templates as $idx => $tpl)
            <div class="flex items-center justify-between px-5 py-4 hover:bg-muted/20 transition-colors">
                <div class="flex items-center gap-4">
                    <div class="h-10 w-10 rounded-lg bg-primary/10 flex items-center justify-center text-primary font-bold text-sm flex-shrink-0">
                        {{ $tpl['year'] }}
                    </div>
                    <div>
                        <div class="font-medium text-sm">{{ $tpl['name'] }}</div>
                        <div class="text-xs text-muted-foreground mt-0.5">
                            {{ count($tpl['sections']) }} sections ·
                            {{ array_sum(array_column($tpl['sections'], 'count')) }} questions ·
                            {{ $tpl['duration'] }} min ·
                            {{ array_sum(array_map(fn($s) => $s['count'] * $s['pos'], $tpl['sections'])) }} marks
                        </div>
                    </div>
                </div>
                <button wire:click="cloneTemplate({{ $idx }})"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-primary/40 px-3 py-1.5 text-xs font-semibold text-primary hover:bg-primary hover:text-primary-foreground transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    Clone &amp; Edit
                </button>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Delete Confirm Modal --}}
    @if($confirmDelete)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-card rounded-xl shadow-xl max-w-sm w-full p-6">
            <h3 class="font-bold text-lg mb-2">Delete Paper?</h3>
            <p class="text-sm text-muted-foreground mb-6">This will permanently delete the paper and all its questions. This cannot be undone.</p>
            <div class="flex gap-3 justify-end">
                <button wire:click="$set('confirmDelete', null)"
                        class="px-4 py-2 rounded-lg border border-border text-sm font-medium hover:bg-muted transition-colors">Cancel</button>
                <button wire:click="deletePaper"
                        class="px-4 py-2 rounded-lg bg-destructive text-destructive-foreground text-sm font-medium hover:bg-destructive/90 transition-colors">Delete</button>
            </div>
        </div>
    </div>
    @endif

</div>
