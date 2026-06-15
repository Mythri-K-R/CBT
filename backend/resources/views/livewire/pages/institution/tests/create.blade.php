<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\ExamTemplate;
use App\Models\Batch;
use App\Models\Test;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.institution')] class extends Component {
    public $title = '';
    public $description = '';
    public $templateId = '';
    public $scheduledStart = '';
    public $scheduledEnd = '';
    public $selectedBatches = [];

    public function with(): array
    {
        return [
            'templates' => ExamTemplate::where('is_active', true)->get(),
            'batches' => Batch::where('institution_id', Auth::user()->institution_id)->where('status', 'active')->get(),
        ];
    }

    public function save()
    {
        $this->validate([
            'title' => 'required|string|max:255',
            'templateId' => 'required|exists:exam_templates,id',
            'scheduledStart' => 'required|date',
            'scheduledEnd' => 'required|date|after:scheduledStart',
        ]);

        $template = ExamTemplate::findOrFail($this->templateId);

        $test = Test::create([
            'institution_id' => Auth::user()->institution_id,
            'created_by' => Auth::id(),
            'template_id' => $template->id,
            'title' => $this->title,
            'description' => $this->description,
            'exam_type' => $template->exam_type,
            'test_category' => 'mock_test',
            'duration_minutes' => $template->total_duration_minutes,
            'has_sectional_timing' => $template->has_sectional_timing,
            'allow_section_switching' => $template->allow_section_switching,
            'scheduled_start' => $this->scheduledStart,
            'scheduled_end' => $this->scheduledEnd,
            'status' => 'scheduled',
        ]);

        if (!empty($this->selectedBatches)) {
            $test->batches()->attach($this->selectedBatches);
        }

        // NOTE: In a real scenario, we would trigger a background job to populate TestSections and TestQuestions here based on the template.

        session()->flash('status', 'Test scheduled successfully.');
        $this->redirect(route('institution.tests'), navigate: true);
    }
}; ?>

<div>
<div class="space-y-6 max-w-5xl mx-auto">
    <!-- Header -->
    <div class="flex items-center gap-4">
        <a href="{{ route('institution.tests') }}" class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-secondary hover:bg-secondary/80 text-secondary-foreground transition-colors shrink-0">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div>
            <h1 class="text-3xl font-display font-bold tracking-tight">Schedule Test</h1>
            <p class="text-muted-foreground mt-1">Configure and schedule a new CBT exam from a template.</p>
        </div>
    </div>

    <form wire:submit="save" class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <!-- Left Column (Main Form) -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Basic Details -->
            <div class="rounded-xl border border-border bg-card p-6 shadow-sm space-y-6">
                <h2 class="text-lg font-semibold border-b border-border/50 pb-4 flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary/10 text-primary text-xs">1</span>
                    Basic Information
                </h2>
                
                <div class="space-y-2">
                    <label class="text-sm font-medium leading-none">Test Title</label>
                    <input type="text" wire:model="title" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" placeholder="e.g. Grand Mock Test 1 - NEET">
                    @error('title') <span class="text-[10px] text-destructive">{{ $message }}</span> @enderror
                </div>

                <div class="space-y-2">
                    <label class="text-sm font-medium leading-none">Description (Optional)</label>
                    <textarea wire:model="description" rows="3" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" placeholder="Instructions or details for the students..."></textarea>
                </div>

                <div class="space-y-2">
                    <label class="text-sm font-medium leading-none">Exam Template</label>
                    <select wire:model="templateId" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        <option value="">Select a template...</option>
                        @foreach($templates as $template)
                            <option value="{{ $template->id }}">{{ $template->name }} ({{ $template->total_duration_minutes }} mins)</option>
                        @endforeach
                    </select>
                    @error('templateId') <span class="text-[10px] text-destructive">{{ $message }}</span> @enderror
                    <p class="text-[11px] text-muted-foreground mt-1">The template dictates the sectional layout, marks, and duration.</p>
                </div>
            </div>

            <!-- Schedule -->
            <div class="rounded-xl border border-border bg-card p-6 shadow-sm space-y-6">
                <h2 class="text-lg font-semibold border-b border-border/50 pb-4 flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary/10 text-primary text-xs">2</span>
                    Schedule Window
                </h2>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-sm font-medium leading-none">Start Date & Time</label>
                        <input type="datetime-local" wire:model="scheduledStart" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        @error('scheduledStart') <span class="text-[10px] text-destructive">{{ $message }}</span> @enderror
                    </div>
                    
                    <div class="space-y-2">
                        <label class="text-sm font-medium leading-none">End Date & Time</label>
                        <input type="datetime-local" wire:model="scheduledEnd" class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        @error('scheduledEnd') <span class="text-[10px] text-destructive">{{ $message }}</span> @enderror
                    </div>
                </div>
                <p class="text-[11px] text-muted-foreground">Students must start and complete the exam within this time window.</p>
            </div>
        </div>

        <!-- Right Column (Sidebar Settings) -->
        <div class="space-y-6">
            <!-- Batches -->
            <div class="rounded-xl border border-border bg-card p-6 shadow-sm space-y-4">
                <h2 class="text-lg font-semibold flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary/10 text-primary text-xs">3</span>
                    Assign Batches
                </h2>
                <p class="text-xs text-muted-foreground">Select which batches should have access to this test.</p>
                
                <div class="space-y-3 mt-4 max-h-64 overflow-y-auto pr-2 custom-scrollbar">
                    @forelse($batches as $batch)
                        <label class="flex items-start gap-3 p-3 rounded-lg border border-border bg-muted/20 hover:bg-muted/50 transition-colors cursor-pointer">
                            <div class="mt-0.5">
                                <input type="checkbox" wire:model="selectedBatches" value="{{ $batch->id }}" class="rounded border-input text-primary focus:ring-primary h-4 w-4">
                            </div>
                            <div>
                                <p class="text-sm font-medium leading-none text-foreground">{{ $batch->name }}</p>
                                <p class="text-xs text-muted-foreground mt-1">{{ $batch->exam_type }}</p>
                            </div>
                        </label>
                    @empty
                        <p class="text-sm text-muted-foreground">No active batches available.</p>
                    @endforelse
                </div>
            </div>

            <!-- Actions -->
            <div class="rounded-xl border border-primary/20 bg-primary/5 p-6 shadow-sm flex flex-col gap-4">
                <button type="submit" class="w-full inline-flex items-center justify-center rounded-lg bg-primary px-4 py-3 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90 transition-all">
                    Schedule & Generate Exam
                </button>
                <p class="text-xs text-center text-muted-foreground">This will auto-generate questions from the Question Bank based on the selected template layout.</p>
            </div>
        </div>
    </form>
</div>
</div>
