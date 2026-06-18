<?php

use App\Models\Test;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.institution')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';

    public function with(): array
    {
        $tests = Test::query()
            ->withCount(['attempts as submissions_count' => fn($q) => $q->where('status','submitted')])
            ->when($this->search, fn($q) => $q->where('title','like',"%{$this->search}%"))
            ->when($this->statusFilter, fn($q) => $q->where('status', $this->statusFilter))
            ->orderByDesc('created_at')
            ->paginate(15);

        return ['tests' => $tests];
    }
}; ?>

<div>
<div class="space-y-6">
    <div>
        <h1 class="font-display text-2xl font-bold">Results</h1>
        <p class="text-muted-foreground text-sm mt-1">View and analyze student performance across all tests</p>
    </div>

    <div class="flex flex-wrap gap-3">
        <div class="relative flex-1 min-w-[200px] max-w-xs">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search tests..." class="h-9 w-full rounded-md border border-input bg-background pl-9 pr-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
        </div>
        <select wire:model.live="statusFilter" class="h-9 rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
            <option value="">All Status</option>
            <option value="completed">Completed</option>
            <option value="live">Live</option>
            <option value="scheduled">Scheduled</option>
        </select>
    </div>

    <div class="rounded-xl border border-border bg-card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="border-b border-border bg-muted/30">
                <tr>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Test</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground hidden sm:table-cell">Submissions</th>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground hidden md:table-cell">Date</th>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Status</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse($tests as $test)
                @php
                    $sc = match($test->status) {
                        'live' => 'bg-success/10 text-success',
                        'scheduled' => 'bg-info/10 text-info',
                        'completed' => 'bg-muted text-muted-foreground',
                        default => 'bg-warning/10 text-warning',
                    };
                @endphp
                <tr class="hover:bg-muted/20 transition-colors">
                    <td class="px-4 py-3">
                        <div class="font-medium">{{ $test->title }}</div>
                        <div class="text-xs text-muted-foreground mt-0.5">{{ strtoupper($test->exam_type) }} · {{ $test->duration_minutes }}min</div>
                    </td>
                    <td class="px-4 py-3 text-right hidden sm:table-cell">
                        <span class="font-bold text-lg">{{ $test->submissions_count }}</span>
                    </td>
                    <td class="px-4 py-3 text-muted-foreground text-xs hidden md:table-cell">
                        {{ $test->scheduled_end?->format('d M Y') ?? ($test->created_at->format('d M Y')) }}
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $sc }}">{{ ucfirst($test->status) }}</span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('institution.results.show', $test) }}" wire:navigate class="text-sm font-medium text-primary hover:underline">
                            View Results
                        </a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="px-4 py-16 text-center text-muted-foreground">No tests found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $tests->links() }}</div>
</div>
</div>
