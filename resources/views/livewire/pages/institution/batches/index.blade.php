<?php

use App\Models\Batch;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.institution')] class extends Component {
    use WithPagination;

    public string $search = '';
    public bool $showModal = false;
    public string $name = '';
    public string $examType = 'neet';
    public string $batchType = 'regular';
    public string $targetYear = '';
    public ?int $editingId = null;

    public function openCreate(): void
    {
        $this->reset(['name', 'examType', 'batchType', 'targetYear', 'editingId']);
        $this->examType = 'neet';
        $this->batchType = 'regular';
        $this->targetYear = (string) (now()->year + 1);
        $this->showModal = true;
    }

    public function openEdit(Batch $batch): void
    {
        $this->editingId = $batch->id;
        $this->name = $batch->name;
        $this->examType = $batch->exam_type;
        $this->batchType = $batch->batch_type ?? 'regular';
        $this->targetYear = (string) ($batch->target_year ?? '');
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'name'       => 'required|string|max:100',
            'examType'   => 'required|in:neet,jee_main,jee_advanced,kcet,general',
            'batchType'  => 'required|in:regular,crash_course,dropper,repeater,weekend,online,combined',
            'targetYear' => 'nullable|digits:4',
        ]);

        $data = [
            'name'        => $this->name,
            'exam_type'   => $this->examType,
            'batch_type'  => $this->batchType,
            'target_year' => $this->targetYear ?: null,
        ];

        if ($this->editingId) {
            Batch::find($this->editingId)?->update($data);
            session()->flash('success', 'Batch updated.');
        } else {
            auth()->user()->institution->batches()->create($data);
            session()->flash('success', 'Batch created.');
        }

        $this->showModal = false;
    }

    public function deleteBatch(Batch $batch): void
    {
        $batch->delete();
        session()->flash('success', 'Batch deleted.');
    }

    public function with(): array
    {
        $batches = Batch::query()
            ->withCount('students')
            ->when($this->search, fn($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->latest()
            ->paginate(12);

        return ['batches' => $batches];
    }
}; ?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="font-display text-2xl font-bold">Batches</h1>
            <p class="text-muted-foreground text-sm mt-1">Organize your students by batch and program</p>
        </div>
        <button wire:click="openCreate" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
            New Batch
        </button>
    </div>

    @if(session('success'))
    <div class="rounded-lg bg-success/10 border border-success/20 px-4 py-3 text-sm text-success font-medium">{{ session('success') }}</div>
    @endif

    <div class="relative max-w-sm">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search batches..." class="h-9 w-full rounded-md border border-input bg-background pl-9 pr-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
    </div>

    @if($batches->isEmpty())
    <div class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-border py-20 text-center">
        <div class="rounded-full bg-muted p-4 mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="text-muted-foreground"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <p class="font-semibold text-lg mb-1">No batches yet</p>
        <p class="text-muted-foreground text-sm mb-6">Create your first batch to start organizing students</p>
        <button wire:click="openCreate" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
            Create First Batch
        </button>
    </div>
    @else
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
        @foreach($batches as $batch)
        @php
            $colors = [
                'neet'  => 'bg-green-50 border-green-200 text-green-700',
                'jee'   => 'bg-blue-50 border-blue-200 text-blue-700',
                'kcet'  => 'bg-purple-50 border-purple-200 text-purple-700',
            ];
            $color = $colors[$batch->exam_type] ?? 'bg-muted border-border text-foreground';
        @endphp
        <div class="group relative rounded-2xl border border-border bg-card p-5 hover:border-primary/40 hover:shadow-md transition-all">
            <div class="flex items-start justify-between mb-4">
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $color }}">{{ strtoupper($batch->exam_type) }}</span>
                <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button wire:click="openEdit({{ $batch->id }})" class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                    </button>
                    <button wire:click="deleteBatch({{ $batch->id }})" wire:confirm="Delete this batch?" class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="m19 6-.867 12.142A2 2 0 0 1 16.138 20H7.862a2 2 0 0 1-1.995-1.858L5 6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                    </button>
                </div>
            </div>

            <a href="{{ route('institution.batches.show', $batch) }}" wire:navigate class="block">
                <h3 class="font-display font-bold text-lg mb-1 hover:text-primary transition-colors">{{ $batch->name }}</h3>
                @if($batch->target_year)
                <p class="text-sm text-muted-foreground mb-4">{{ ucfirst(str_replace('_',' ',$batch->batch_type ?? '')) }} · {{ $batch->target_year }}</p>
                @else
                <div class="mb-4"></div>
                @endif

                <div class="flex items-center gap-2 text-sm text-muted-foreground">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    <span>{{ $batch->students_count }} student{{ $batch->students_count !== 1 ? 's' : '' }}</span>
                </div>
            </a>

            <a href="{{ route('institution.batches.show', $batch) }}" wire:navigate class="mt-4 flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                View Batch
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            </a>
        </div>
        @endforeach
    </div>
    <div class="mt-4">{{ $batches->links() }}</div>
    @endif
</div>

<!-- Create / Edit Modal -->
@if($showModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" x-data>
    <div class="w-full max-w-md rounded-2xl bg-card border border-border shadow-2xl p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="font-display text-xl font-bold">{{ $editingId ? 'Edit' : 'Create' }} Batch</h2>
            <button wire:click="$set('showModal',false)" class="h-8 w-8 rounded flex items-center justify-center text-muted-foreground hover:bg-muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1.5">Batch Name <span class="text-destructive">*</span></label>
                <input wire:model="name" type="text" placeholder="e.g. NEET 2026 – Morning Batch" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring @error('name') border-destructive @enderror">
                @error('name')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium mb-1.5">Exam Type <span class="text-destructive">*</span></label>
                    <select wire:model="examType" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                        <option value="neet">NEET</option>
                        <option value="jee_main">JEE Main</option>
                        <option value="jee_advanced">JEE Advanced</option>
                        <option value="kcet">KCET</option>
                        <option value="general">General</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Batch Type</label>
                    <select wire:model="batchType" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                        <option value="regular">Regular</option>
                        <option value="crash_course">Crash Course</option>
                        <option value="dropper">Dropper</option>
                        <option value="repeater">Repeater</option>
                        <option value="weekend">Weekend</option>
                        <option value="online">Online</option>
                        <option value="combined">Combined</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Target Year</label>
                <input wire:model="targetYear" type="text" placeholder="{{ now()->year + 1 }}" maxlength="4" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="flex-1 rounded-lg bg-primary py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">{{ $editingId ? 'Save Changes' : 'Create Batch' }}</button>
                <button type="button" wire:click="$set('showModal',false)" class="flex-1 rounded-lg border border-border py-2 text-sm font-medium hover:bg-muted transition-colors">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endif
</div>
