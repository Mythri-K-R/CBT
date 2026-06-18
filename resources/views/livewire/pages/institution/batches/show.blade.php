<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\Batch;
use App\Models\Student;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.institution')] class extends Component {
    public Batch $batch;
    public string $studentSearch = '';
    public bool $showAddModal = false;

    public function mount(Batch $batch): void
    {
        abort_if($batch->institution_id !== Auth::user()->institution_id, 403);
        $this->batch = $batch->load(['students' => fn($q) => $q->orderBy('name')]);
    }

    public function openAddModal(): void
    {
        $this->studentSearch = '';
        $this->showAddModal = true;
    }

    public function addStudent(int $id): void
    {
        $student = Student::find($id);
        if (!$student || $student->institution_id !== Auth::user()->institution_id) return;

        // Attach only if not already enrolled
        if (!$this->batch->allStudents()->where('student_id', $id)->exists()) {
            $this->batch->students()->attach($id, [
                'enrolled_at' => now(),
                'status'      => 'active',
            ]);
        }

        $this->batch->load(['students' => fn($q) => $q->orderBy('name')]);
        $this->showAddModal = false;
        session()->flash('status', 'Student added to batch.');
    }

    public function removeStudent(int $id): void
    {
        $this->batch->students()->detach($id);
        $this->batch->load(['students' => fn($q) => $q->orderBy('name')]);
        session()->flash('status', 'Student removed from batch.');
    }

    public function with(): array
    {
        $enrolledIds = $this->batch->students->pluck('id')->toArray();

        $available = Student::where('institution_id', Auth::user()->institution_id)
            ->whereNotIn('id', $enrolledIds)
            ->when($this->studentSearch, fn($q) => $q->where(function($q2) {
                $q2->where('name', 'like', "%{$this->studentSearch}%")
                   ->orWhere('roll_number', 'like', "%{$this->studentSearch}%");
            }))
            ->orderBy('name')
            ->take(20)
            ->get();

        return ['available' => $available];
    }
}; ?>

<div>
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center gap-4">
        <a href="{{ route('institution.batches') }}" wire:navigate class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-secondary hover:bg-secondary/80 text-secondary-foreground transition-colors shrink-0">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div class="flex-1 flex items-center justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-3xl font-display font-bold tracking-tight">{{ $batch->name }}</h1>
                    <span class="inline-flex items-center rounded-md bg-success/10 px-2.5 py-0.5 text-sm font-medium text-success ring-1 ring-inset ring-success/20">Active</span>
                </div>
                <p class="text-muted-foreground mt-1">{{ strtoupper($batch->exam_type) }}</p>
            </div>
            <button wire:click="openAddModal" class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90 transition-all">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>
                Add Student
            </button>
        </div>
    </div>

    @if(session('status'))
    <div class="rounded-lg bg-success/10 border border-success/20 px-4 py-3 text-sm text-success">{{ session('status') }}</div>
    @endif

    <!-- Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-xl border border-border bg-card p-6 shadow-sm">
            <p class="text-sm font-medium text-muted-foreground">Total Students</p>
            <div class="mt-2 flex items-baseline gap-2">
                <span class="text-3xl font-display font-bold">{{ $batch->students->count() }}</span>
                <span class="text-sm text-muted-foreground">enrolled</span>
            </div>
        </div>
        <div class="rounded-xl border border-border bg-card p-6 shadow-sm">
            <p class="text-sm font-medium text-muted-foreground">Exam Type</p>
            <div class="mt-2">
                <span class="text-xl font-display font-bold">{{ strtoupper($batch->exam_type) }}</span>
            </div>
        </div>
        <div class="rounded-xl border border-border bg-card p-6 shadow-sm">
            <p class="text-sm font-medium text-muted-foreground">Max Students</p>
            <div class="mt-2">
                <span class="text-3xl font-display font-bold">{{ $batch->max_students ?? '—' }}</span>
            </div>
        </div>
        <div class="rounded-xl border border-border bg-card p-6 shadow-sm">
            <p class="text-sm font-medium text-muted-foreground">Created</p>
            <div class="mt-2">
                <span class="text-xl font-display font-bold">{{ $batch->created_at->format('M d, Y') }}</span>
            </div>
        </div>
    </div>

    <!-- Students Table -->
    <div class="rounded-xl border border-border bg-card shadow-sm overflow-hidden">
        <div class="p-6 border-b border-border/50 flex items-center justify-between bg-card">
            <h2 class="text-lg font-semibold">Enrolled Students ({{ $batch->students->count() }})</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-muted/50 text-muted-foreground font-medium">
                    <tr>
                        <th class="px-6 py-3">Student Name</th>
                        <th class="px-6 py-3">Roll Number</th>
                        <th class="px-6 py-3">Phone</th>
                        <th class="px-6 py-3">Enrolled On</th>
                        <th class="px-6 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/50">
                    @forelse($batch->students as $student)
                        <tr class="hover:bg-muted/50 transition-colors">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="h-8 w-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-medium text-xs">
                                        {{ substr($student->name, 0, 2) }}
                                    </div>
                                    <div class="font-medium text-foreground">{{ $student->name }}</div>
                                </div>
                            </td>
                            <td class="px-6 py-4 font-mono text-xs">{{ $student->roll_number }}</td>
                            <td class="px-6 py-4 text-muted-foreground">{{ $student->phone ?? '—' }}</td>
                            <td class="px-6 py-4 text-muted-foreground">
                                {{ isset($student->pivot->enrolled_at) ? \Carbon\Carbon::parse($student->pivot->enrolled_at)->format('M d, Y') : '—' }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('institution.students.show', $student) }}" wire:navigate class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors" title="View">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </a>
                                    <button wire:click="removeStudent({{ $student->id }})" wire:confirm="Remove {{ $student->name }} from this batch?" class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors" title="Remove">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-muted-foreground">
                                No students enrolled yet.
                                <button wire:click="openAddModal" class="block mx-auto mt-2 text-primary hover:underline text-sm">Add the first student</button>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Student Modal -->
@if($showAddModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
    <div class="w-full max-w-lg rounded-2xl bg-card border border-border shadow-2xl p-6 max-h-[80vh] flex flex-col">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-display text-xl font-bold">Add Student to Batch</h2>
            <button wire:click="$set('showAddModal',false)" class="h-8 w-8 rounded flex items-center justify-center text-muted-foreground hover:bg-muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <div class="mb-4">
            <input wire:model.live.debounce.300ms="studentSearch" type="text" placeholder="Search by name or roll number..." class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
        </div>
        <div class="overflow-y-auto flex-1 -mx-2">
            @forelse($available as $s)
            <div class="flex items-center justify-between px-2 py-2.5 rounded-lg hover:bg-muted/50 transition-colors">
                <div class="flex items-center gap-3">
                    <div class="h-8 w-8 rounded-full bg-primary/10 flex items-center justify-center text-primary text-xs font-bold">{{ substr($s->name,0,1) }}</div>
                    <div>
                        <p class="text-sm font-medium">{{ $s->name }}</p>
                        <p class="text-xs text-muted-foreground">{{ $s->roll_number }}</p>
                    </div>
                </div>
                <button wire:click="addStudent({{ $s->id }})" class="rounded-lg bg-primary/10 text-primary hover:bg-primary hover:text-primary-foreground px-3 py-1 text-xs font-semibold transition-colors">
                    Add
                </button>
            </div>
            @empty
            <p class="text-center text-muted-foreground text-sm py-8">
                {{ $studentSearch ? 'No students found.' : 'All institution students are already enrolled.' }}
            </p>
            @endforelse
        </div>
        <div class="pt-4 border-t border-border mt-4">
            <button wire:click="$set('showAddModal',false)" class="w-full rounded-lg border border-border py-2 text-sm font-medium hover:bg-muted">Close</button>
        </div>
    </div>
</div>
@endif
</div>
