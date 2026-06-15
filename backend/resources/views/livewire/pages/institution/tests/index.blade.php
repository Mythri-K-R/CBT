<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\Test;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.institution')] class extends Component {
    public string $search = '';

    public function with(): array
    {
        $tests = Test::with(['template', 'batches'])
            ->withCount(['attempts as submissions_count' => fn($q) => $q->where('status', 'submitted')])
            ->where('institution_id', Auth::user()->institution_id)
            ->when($this->search, fn($q) => $q->where('title', 'like', "%{$this->search}%"))
            ->orderBy('created_at', 'desc')
            ->get();

        return ['tests' => $tests];
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-display font-bold tracking-tight">Tests & Exams</h1>
            <p class="text-muted-foreground mt-1">Manage, schedule, and monitor computer-based tests.</p>
        </div>
        <a href="{{ route('institution.tests.create') }}" class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90 transition-all">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
            Schedule Test
        </a>
    </div>

    <!-- Quick Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-xl border border-border bg-card p-6 shadow-sm flex flex-col justify-between">
            <div class="flex items-center justify-between text-muted-foreground">
                <p class="text-sm font-medium">Total Tests</p>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            </div>
            <div class="mt-2 text-3xl font-display font-bold">{{ $tests->count() }}</div>
        </div>
        <div class="rounded-xl border border-border bg-card p-6 shadow-sm flex flex-col justify-between">
            <div class="flex items-center justify-between text-muted-foreground">
                <p class="text-sm font-medium">Live Tests</p>
                <div class="h-2 w-2 rounded-full bg-success animate-pulse"></div>
            </div>
            <div class="mt-2 text-3xl font-display font-bold">{{ $tests->where('status', 'live')->count() }}</div>
        </div>
        <div class="rounded-xl border border-border bg-card p-6 shadow-sm flex flex-col justify-between">
            <div class="flex items-center justify-between text-muted-foreground">
                <p class="text-sm font-medium">Scheduled</p>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
            </div>
            <div class="mt-2 text-3xl font-display font-bold">{{ $tests->where('status', 'scheduled')->count() }}</div>
        </div>
        <div class="rounded-xl border border-border bg-card p-6 shadow-sm flex flex-col justify-between">
            <div class="flex items-center justify-between text-muted-foreground">
                <p class="text-sm font-medium">Total Submissions</p>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <div class="mt-2 text-3xl font-display font-bold">{{ $tests->sum('submissions_count') }}</div>
        </div>
    </div>

    <!-- Tests List -->
    <div class="rounded-xl border border-border bg-card shadow-sm overflow-hidden">
        <div class="p-4 border-b border-border/50 flex items-center justify-between bg-muted/20">
            <div class="relative">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search tests..." class="w-64 rounded-lg border border-input bg-background py-2 pl-9 pr-4 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary placeholder:text-muted-foreground">
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-muted/50 text-muted-foreground font-medium">
                    <tr>
                        <th class="px-6 py-3">Test Name & Template</th>
                        <th class="px-6 py-3">Schedule</th>
                        <th class="px-6 py-3">Assigned To</th>
                        <th class="px-6 py-3">Status</th>
                        <th class="px-6 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/50">
                    @forelse($tests as $test)
                        <tr class="hover:bg-muted/50 transition-colors">
                            <td class="px-6 py-4">
                                <div class="font-medium text-foreground">{{ $test->title }}</div>
                                <div class="text-xs text-muted-foreground mt-0.5">{{ $test->template->name }} • {{ $test->duration_minutes }} mins</div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex flex-col gap-1 text-xs">
                                    <span class="text-foreground">Starts: {{ $test->scheduled_start ? $test->scheduled_start->format('M d, g:i A') : 'Manual' }}</span>
                                    <span class="text-muted-foreground">Ends: {{ $test->scheduled_end ? $test->scheduled_end->format('M d, g:i A') : 'Manual' }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex -space-x-2">
                                    @if($test->batches->count() > 0)
                                        @foreach($test->batches->take(3) as $batch)
                                            <div class="inline-flex h-7 w-7 rounded-full bg-secondary ring-2 ring-background flex items-center justify-center text-[10px] font-medium text-secondary-foreground shadow-sm" title="{{ $batch->name }}">
                                                {{ substr($batch->name, 0, 2) }}
                                            </div>
                                        @endforeach
                                        @if($test->batches->count() > 3)
                                            <div class="inline-flex h-7 w-7 rounded-full bg-muted ring-2 ring-background flex items-center justify-center text-[10px] font-medium text-muted-foreground shadow-sm">
                                                +{{ $test->batches->count() - 3 }}
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-xs text-muted-foreground">No batches</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                @php
                                    $statusConfig = match($test->status) {
                                        'live' => ['bg-success/10 text-success ring-success/20', 'Live'],
                                        'scheduled' => ['bg-info/10 text-info ring-info/20', 'Scheduled'],
                                        'completed' => ['bg-muted text-muted-foreground ring-border', 'Completed'],
                                        default => ['bg-warning/10 text-warning ring-warning/20', ucfirst($test->status)]
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $statusConfig[0] }}">
                                    @if($test->status === 'live')
                                        <span class="h-1.5 w-1.5 rounded-full bg-success mr-1.5 animate-pulse"></span>
                                    @endif
                                    {{ $statusConfig[1] }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('institution.tests.show', $test) }}" wire:navigate class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors" title="View Details">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </a>
                                    <a href="{{ route('institution.results.show', $test) }}" wire:navigate class="px-2 h-7 rounded text-xs font-medium text-primary hover:bg-primary/10 transition-colors flex items-center">Results</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-muted-foreground">
                                No tests have been created yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
