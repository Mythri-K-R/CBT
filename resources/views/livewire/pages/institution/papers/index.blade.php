<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\Test;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.institution')] class extends Component {
    public function with(): array
    {
        $institutionId = Auth::user()->institution_id;
        $counts = Test::where('institution_id', $institutionId)
            ->where('test_category', 'full_length')
            ->selectRaw('exam_type, COUNT(*) as total')
            ->groupBy('exam_type')
            ->pluck('total', 'exam_type');

        return ['counts' => $counts];
    }
}; ?>

<div class="space-y-8">

    <div>
        <h1 class="text-3xl font-display font-bold tracking-tight">Full Length Papers</h1>
        <p class="text-muted-foreground mt-1">Build, edit, and schedule complete exam papers for your students.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

        @foreach([
            ['neet',     'NEET',     'Biology, Physics & Chemistry — 180Q / 720 marks / 200 min',  '#22c55e', 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 8v4l3 3'],
            ['jee_main', 'JEE Main', 'Physics, Chemistry & Mathematics — 90Q / 300 marks / 180 min', '#3b82f6', 'M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 0-2-2V9m0 0h18'],
            ['kcet',     'KCET',     'Physics, Chemistry & Mathematics — 180Q / 180 marks / 80 min',  '#f59e0b', 'M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5'],
        ] as [$type, $label, $desc, $color, $icon])
        <a href="{{ route('institution.papers.list', $type) }}" wire:navigate
           class="group rounded-2xl border border-border bg-card p-8 hover:shadow-lg transition-all hover:border-primary/40 cursor-pointer">
            <div class="flex items-start justify-between mb-6">
                <div class="h-14 w-14 rounded-xl flex items-center justify-center" style="background:{{ $color }}20;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="{{ $color }}" stroke-width="2"><path d="{{ $icon }}"/></svg>
                </div>
                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold" style="background:{{ $color }}15;color:{{ $color }};">
                    {{ $counts[$type] ?? 0 }} paper{{ ($counts[$type] ?? 0) != 1 ? 's' : '' }}
                </span>
            </div>
            <h2 class="text-2xl font-display font-bold mb-2 group-hover:text-primary transition-colors">{{ $label }}</h2>
            <p class="text-sm text-muted-foreground leading-relaxed">{{ $desc }}</p>
            <div class="mt-6 flex items-center gap-2 text-sm font-medium text-primary opacity-0 group-hover:opacity-100 transition-opacity">
                View Papers
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
            </div>
        </a>
        @endforeach

    </div>

    <div class="rounded-xl border border-border bg-muted/30 p-6">
        <h3 class="font-semibold mb-2">About Full Length Papers</h3>
        <ul class="text-sm text-muted-foreground space-y-1.5 list-disc list-inside">
            <li>Build complete exam papers with your question bank or write questions directly</li>
            <li>Each institution comes with default previous years papers (NEET / JEE / KCET)</li>
            <li>Papers can be scheduled as live tests — students get the full NTA exam experience</li>
            <li>Faculty can edit every question including options, correct answers, and explanations</li>
        </ul>
    </div>

</div>
