<?php

use App\Models\ExamTemplate;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] class extends Component {
    public bool $showModal = false;
    public ?int $editingId = null;
    public string $search = '';

    public string $name = '';
    public string $examType = 'neet';
    public int $totalQuestions = 180;
    public int $totalDurationMinutes = 200;
    public int $totalMarks = 720;
    public string $instructions = '';

    public function open(?int $id = null): void
    {
        if ($id) {
            $t = ExamTemplate::find($id);
            $this->editingId = $id;
            $this->name = $t->name;
            $this->examType = $t->exam_type;
            $this->totalQuestions = $t->total_questions;
            $this->totalDurationMinutes = $t->total_duration_minutes;
            $this->totalMarks = (int) $t->total_marks;
            $this->instructions = $t->instructions ?? '';
        } else {
            $this->reset(['name','examType','totalQuestions','totalDurationMinutes','totalMarks','instructions','editingId']);
            $this->examType = 'neet';
            $this->totalQuestions = 180;
            $this->totalDurationMinutes = 200;
            $this->totalMarks = 720;
        }
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'name'                 => 'required|string|max:150',
            'examType'             => 'required|in:neet,jee_main,jee_advanced,kcet',
            'totalQuestions'       => 'required|integer|min:1',
            'totalDurationMinutes' => 'required|integer|min:1',
            'totalMarks'           => 'required|integer|min:1',
        ]);

        $data = [
            'name'                   => $this->name,
            'exam_type'              => $this->examType,
            'total_questions'        => $this->totalQuestions,
            'total_duration_minutes' => $this->totalDurationMinutes,
            'total_marks'            => $this->totalMarks,
            'instructions'           => $this->instructions ?: null,
        ];

        $this->editingId
            ? ExamTemplate::find($this->editingId)?->update($data)
            : ExamTemplate::create($data);

        $this->showModal = false;
        session()->flash('success', 'Template saved.');
    }

    public function delete(ExamTemplate $template): void { $template->delete(); session()->flash('success','Template deleted.'); }

    public function with(): array
    {
        $templates = ExamTemplate::query()
            ->withCount('tests')
            ->when($this->search, fn($q) => $q->where('name','like',"%{$this->search}%"))
            ->orderBy('exam_type')
            ->orderBy('name')
            ->get();
        return ['templates' => $templates];
    }
}; ?>

<div>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="font-display text-2xl font-bold">Exam Templates</h1>
            <p class="text-muted-foreground text-sm mt-1">Reusable test blueprints for NEET, JEE, and KCET</p>
        </div>
        <button wire:click="open" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
            New Template
        </button>
    </div>

    @if(session('success'))
    <div class="rounded-lg bg-success/10 border border-success/20 px-4 py-3 text-sm text-success">{{ session('success') }}</div>
    @endif

    <div class="relative max-w-xs">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search templates..." class="h-9 w-full rounded-md border border-input bg-background pl-9 pr-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
        @forelse($templates as $t)
        @php $etColors = ['neet'=>'bg-green-50 text-green-700','jee_main'=>'bg-blue-50 text-blue-700','jee_advanced'=>'bg-indigo-50 text-indigo-700','kcet'=>'bg-purple-50 text-purple-700']; @endphp
        <div class="group rounded-2xl border border-border bg-card p-5 hover:border-primary/40 hover:shadow-md transition-all">
            <div class="flex items-start justify-between mb-3">
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $etColors[$t->exam_type] ?? 'bg-muted text-muted-foreground' }}">{{ strtoupper($t->exam_type) }}</span>
                <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button wire:click="open({{ $t->id }})" class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                    </button>
                    <button wire:click="delete({{ $t->id }})" wire:confirm="Delete template '{{ $t->name }}'?" class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-destructive hover:bg-destructive/10">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="m19 6-.867 12.142A2 2 0 0 1 16.138 20H7.862a2 2 0 0 1-1.995-1.858L5 6"/></svg>
                    </button>
                </div>
            </div>
            <h3 class="font-display font-bold text-base mb-3">{{ $t->name }}</h3>
            <div class="grid grid-cols-2 gap-2 text-xs text-muted-foreground">
                <div><span class="block text-[10px] uppercase tracking-wider mb-0.5">Questions</span><span class="font-semibold text-foreground">{{ $t->total_questions }}</span></div>
                <div><span class="block text-[10px] uppercase tracking-wider mb-0.5">Duration</span><span class="font-semibold text-foreground">{{ $t->total_duration_minutes }}min</span></div>
                <div><span class="block text-[10px] uppercase tracking-wider mb-0.5">Total Marks</span><span class="font-semibold text-foreground">{{ $t->total_marks }}</span></div>
                <div><span class="block text-[10px] uppercase tracking-wider mb-0.5">Sectional</span><span class="font-semibold text-foreground">{{ $t->has_sectional_timing ? 'Yes' : 'No' }}</span></div>
            </div>
            <div class="mt-4 pt-3 border-t border-border text-xs text-muted-foreground">
                Used in <strong class="text-foreground">{{ $t->tests_count }}</strong> test{{ $t->tests_count !== 1 ? 's' : '' }}
            </div>
        </div>
        @empty
        <div class="col-span-3 text-center py-16 rounded-2xl border border-dashed border-border">
            <p class="text-muted-foreground">No templates yet. Create a NEET or JEE template to get started.</p>
        </div>
        @endforelse
    </div>
</div>

@if($showModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
    <div class="w-full max-w-lg rounded-2xl bg-card border border-border shadow-2xl p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-6">
            <h2 class="font-display text-xl font-bold">{{ $editingId ? 'Edit' : 'Create' }} Template</h2>
            <button wire:click="$set('showModal',false)" class="h-8 w-8 rounded flex items-center justify-center text-muted-foreground hover:bg-muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1.5">Template Name <span class="text-destructive">*</span></label>
                <input wire:model="name" type="text" placeholder="e.g. NEET Full Test 2026" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring @error('name') border-destructive @enderror">
                @error('name')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Exam Type <span class="text-destructive">*</span></label>
                <select wire:model="examType" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                    <option value="neet">NEET</option>
                    <option value="jee_main">JEE Main</option>
                    <option value="jee_advanced">JEE Advanced</option>
                    <option value="kcet">KCET</option>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium mb-1.5">Total Questions</label>
                    <input wire:model="totalQuestions" type="number" min="1" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Duration (min)</label>
                    <input wire:model="totalDurationMinutes" type="number" min="1" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium mb-1.5">Total Marks</label>
                    <input wire:model="totalMarks" type="number" min="1" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Instructions</label>
                <textarea wire:model="instructions" rows="2" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring resize-none" placeholder="Optional instructions for candidates..."></textarea>
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="flex-1 rounded-lg bg-primary py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90">Save Template</button>
                <button type="button" wire:click="$set('showModal',false)" class="flex-1 rounded-lg border border-border py-2 text-sm font-medium hover:bg-muted">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endif
</div>
