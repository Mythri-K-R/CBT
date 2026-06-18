<?php

use App\Models\Batch;
use App\Models\Student;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.institution')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $batchFilter = '';
    public string $examTypeFilter = '';
    public bool $showModal = false;
    public bool $showImportModal = false;
    public ?int $editingId = null;

    public string $name = '';
    public string $rollNumber = '';
    public string $dob = '';
    public string $phone = '';
    public string $batchId = '';

    public function openCreate(): void
    {
        $this->reset(['name','rollNumber','dob','phone','batchId','editingId']);
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $student = Student::with('batches')->find($id);
        if (!$student) return;
        $this->editingId = $student->id;
        $this->name = $student->name;
        $this->rollNumber = $student->roll_number;
        $this->dob = $student->date_of_birth?->format('Y-m-d') ?? '';
        $this->phone = $student->phone ?? '';
        $this->batchId = (string) ($student->batches->first()?->id ?? '');
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'name'        => 'required|string|max:150',
            'rollNumber'  => 'required|string|max:50',
            'dob'         => 'nullable|date',
            'phone'       => 'nullable|string|max:20',
            'batchId'     => 'nullable|exists:batches,id',
        ]);

        $data = [
            'name'          => $this->name,
            'roll_number'   => $this->rollNumber,
            'date_of_birth' => $this->dob ?: null,
            'phone'         => $this->phone ?: null,
        ];

        if ($this->editingId) {
            $student = Student::find($this->editingId);
            $student?->update($data);
            if ($student && $this->batchId) {
                // Update primary batch (detach others, attach new)
                $student->batches()->detach();
                $student->batches()->attach($this->batchId, ['enrolled_at' => now(), 'status' => 'active']);
            }
            session()->flash('success', 'Student updated.');
        } else {
            $student = auth()->user()->institution->students()->create($data);
            if ($this->batchId) {
                $student->batches()->attach($this->batchId, ['enrolled_at' => now(), 'status' => 'active']);
            }
            session()->flash('success', 'Student added.');
        }

        $this->showModal = false;
        $this->resetPage();
    }

    public function deleteStudent(int $id): void
    {
        Student::find($id)?->delete();
        session()->flash('success', 'Student removed.');
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingBatchFilter(): void { $this->resetPage(); }

    public function with(): array
    {
        $batches = Batch::orderBy('name')->get(['id','name','exam_type']);

        $students = Student::query()
            ->with('batches')
            ->when($this->search, fn($q) => $q->where(function($q2) {
                $q2->where('name','like',"%{$this->search}%")
                   ->orWhere('roll_number','like',"%{$this->search}%")
                   ->orWhere('phone','like',"%{$this->search}%");
            }))
            ->when($this->batchFilter, fn($q) => $q->whereHas('batches', fn($q2) => $q2->where('batches.id', $this->batchFilter)))
            ->when($this->examTypeFilter, fn($q) => $q->whereHas('batches', fn($q2) => $q2->where('exam_type', $this->examTypeFilter)))
            ->orderBy('name')
            ->paginate(20);

        return compact('students', 'batches');
    }
}; ?>

<div>
<div class="space-y-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="font-display text-2xl font-bold">Students</h1>
            <p class="text-muted-foreground text-sm mt-1">All students across your batches</p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="$set('showImportModal',true)" class="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm font-medium hover:bg-muted transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
                Import
            </button>
            <button wire:click="openCreate" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                Add Student
            </button>
        </div>
    </div>

    @if(session('success'))
    <div class="rounded-lg bg-success/10 border border-success/20 px-4 py-3 text-sm text-success font-medium">{{ session('success') }}</div>
    @endif

    <div class="flex flex-wrap gap-3">
        <div class="relative flex-1 min-w-[200px] max-w-xs">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search name, roll no., phone..." class="h-9 w-full rounded-md border border-input bg-background pl-9 pr-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
        </div>
        <select wire:model.live="batchFilter" class="h-9 rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
            <option value="">All Batches</option>
            @foreach($batches as $b)
            <option value="{{ $b->id }}">{{ $b->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="examTypeFilter" class="h-9 rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
            <option value="">All Exam Types</option>
            <option value="neet">NEET</option>
            <option value="jee">JEE</option>
            <option value="kcet">KCET</option>
        </select>
    </div>

    <div class="rounded-xl border border-border bg-card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="border-b border-border bg-muted/30">
                <tr>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Student</th>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground hidden sm:table-cell">Roll Number</th>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground hidden md:table-cell">Date of Birth</th>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground hidden lg:table-cell">Batch</th>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground hidden xl:table-cell">Contact</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse($students as $student)
                <tr class="hover:bg-muted/20 transition-colors">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <div class="h-8 w-8 rounded-full bg-primary/10 text-primary flex items-center justify-center text-xs font-bold shrink-0">{{ substr($student->name, 0, 1) }}</div>
                            <div>
                                <a href="{{ route('institution.students.show', $student) }}" wire:navigate class="font-medium hover:text-primary transition-colors">{{ $student->name }}</a>
                                <p class="text-xs text-muted-foreground sm:hidden">{{ $student->roll_number }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-muted-foreground hidden sm:table-cell font-mono text-xs">{{ $student->roll_number }}</td>
                    <td class="px-4 py-3 hidden md:table-cell text-xs">
                        @if($student->date_of_birth)
                        <span class="font-mono text-foreground">{{ $student->date_of_birth->format('d/m/Y') }}</span>
                        <span class="block text-muted-foreground text-[10px]">{{ $student->date_of_birth->format('dmY') }} (pin)</span>
                        @else
                        <span class="text-muted-foreground italic">Not set</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 hidden lg:table-cell">
                        @php $primaryBatch = $student->batches->first(); @endphp
                        @if($primaryBatch)
                        <span class="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-xs font-medium">{{ $primaryBatch->name }}</span>
                        @else
                        <span class="text-muted-foreground text-xs">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-muted-foreground text-xs hidden xl:table-cell">
                        {{ $student->phone ?? '—' }}
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-1">
                            <a href="{{ route('institution.students.show', $student) }}" wire:navigate class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                            </a>
                            <button wire:click="openEdit({{ $student->id }})" class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                            </button>
                            <button wire:click="deleteStudent({{ $student->id }})" wire:confirm="Remove this student permanently?" class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="m19 6-.867 12.142A2 2 0 0 1 16.138 20H7.862a2 2 0 0 1-1.995-1.858L5 6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                            </button>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-16 text-center text-muted-foreground">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="mx-auto mb-3 text-muted-foreground/40"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                        <p class="font-medium">No students found</p>
                        <p class="text-sm mt-1">Try adjusting your search or filters</p>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $students->links() }}</div>
</div>

<!-- Add/Edit Student Modal -->
@if($showModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
    <div class="w-full max-w-md rounded-2xl bg-card border border-border shadow-2xl p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="font-display text-xl font-bold">{{ $editingId ? 'Edit' : 'Add' }} Student</h2>
            <button wire:click="$set('showModal',false)" class="h-8 w-8 rounded flex items-center justify-center text-muted-foreground hover:bg-muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1.5">Full Name <span class="text-destructive">*</span></label>
                <input wire:model="name" type="text" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring @error('name') border-destructive @enderror">
                @error('name')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Roll Number <span class="text-destructive">*</span></label>
                <input wire:model="rollNumber" type="text" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring font-mono @error('rollNumber') border-destructive @enderror">
                @error('rollNumber')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Date of Birth <span class="text-xs text-muted-foreground font-normal">(used as test password)</span></label>
                <input wire:model="dob" type="date" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring @error('dob') border-destructive @enderror">
                @error('dob')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Phone</label>
                <input wire:model="phone" type="tel" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Batch</label>
                <select wire:model="batchId" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                    <option value="">No batch assigned</option>
                    @foreach($batches as $b)
                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="flex-1 rounded-lg bg-primary py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90">{{ $editingId ? 'Save Changes' : 'Add Student' }}</button>
                <button type="button" wire:click="$set('showModal',false)" class="flex-1 rounded-lg border border-border py-2 text-sm font-medium hover:bg-muted">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endif
</div>
