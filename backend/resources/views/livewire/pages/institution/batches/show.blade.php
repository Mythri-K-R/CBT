<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\Batch;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.institution')] class extends Component {
    public Batch $batch;

    public function mount(Batch $batch)
    {
        // Ensure the batch belongs to the current user's institution
        if ($batch->institution_id !== Auth::user()->institution_id) {
            abort(403);
        }

        $this->batch = $batch->load(['students' => function ($query) {
            $query->orderBy('name');
        }]);
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
            <button class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90 transition-all">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>
                Add Student
            </button>
        </div>
    </div>

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
            <p class="text-sm font-medium text-muted-foreground">Total Tests Taken</p>
            <div class="mt-2 flex items-baseline gap-2">
                <span class="text-3xl font-display font-bold">0</span>
            </div>
        </div>
        <div class="rounded-xl border border-border bg-card p-6 shadow-sm">
            <p class="text-sm font-medium text-muted-foreground">Average Score</p>
            <div class="mt-2 flex items-baseline gap-2">
                <span class="text-3xl font-display font-bold">--%</span>
            </div>
        </div>
        <div class="rounded-xl border border-border bg-card p-6 shadow-sm">
            <p class="text-sm font-medium text-muted-foreground">Created</p>
            <div class="mt-2 flex items-baseline gap-2">
                <span class="text-xl font-display font-bold">{{ $batch->created_at->format('M d, Y') }}</span>
            </div>
        </div>
    </div>

    <!-- Students Table -->
    <div class="rounded-xl border border-border bg-card shadow-sm overflow-hidden">
        <div class="p-6 border-b border-border/50 flex items-center justify-between bg-card">
            <h2 class="text-lg font-semibold">Enrolled Students</h2>
            <div class="relative">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="text" placeholder="Search students..." class="w-64 rounded-lg border border-input bg-background py-2 pl-9 pr-4 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary placeholder:text-muted-foreground">
            </div>
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
                            <td class="px-6 py-4 text-muted-foreground">{{ $student->phone }}</td>
                            <td class="px-6 py-4 text-muted-foreground">{{ isset($student->pivot->enrolled_at) ? \Carbon\Carbon::parse($student->pivot->enrolled_at)->format('M d, Y') : '—' }}</td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('institution.students.show', $student) }}" wire:navigate class="text-muted-foreground hover:text-primary transition-colors">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-muted-foreground">
                                No students enrolled yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>
