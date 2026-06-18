<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('layouts.institution')] class extends Component {
    public array $week = [];

    public function mount()
    {
        $this->week = [
            ['day' => 'Monday', 'test' => 'Physics Chapter Test', 'batch' => 'NEET 2028', 'status' => 'Completed', 'detail' => '85% attendance · Avg: 64%'],
            ['day' => 'Tuesday', 'test' => 'Chemistry Weekly Test', 'batch' => 'NEET 2028', 'status' => 'Completed', 'detail' => '78% attendance · Avg: 71%'],
            ['day' => 'Wednesday', 'test' => 'Maths Chapter Test', 'batch' => 'JEE 2028', 'status' => 'Scheduled', 'detail' => '10:00 AM'],
            ['day' => 'Friday', 'test' => 'NEET Grand Mock Test 5', 'batch' => 'NEET 2028', 'status' => 'Scheduled', 'detail' => '9:00 AM – 12:20 PM'],
            ['day' => 'Saturday', 'test' => 'KCET Practice Test 3', 'batch' => 'KCET 2028', 'status' => 'Draft', 'detail' => 'Not scheduled'],
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3 mb-6">
        <div class="min-w-0">
            @php $__di = auth()->user()->institution; @endphp
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight truncate">{{ $__di ? 'your-institute_' . $__di->id : 'Institution' }}</h1>
            <p class="mt-1 text-sm text-muted-foreground">Good morning — here’s what is happening across your institute this week.</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap justify-end">
            <x-ui.button>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-1.5"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                Create Test
            </x-ui.button>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Active Batches -->
        <x-ui.card class="p-4 sm:p-5">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 shrink-0 flex items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div>
                    <div class="text-sm font-medium text-muted-foreground">Active Batches</div>
                    <div class="font-display text-2xl font-bold tracking-tight mt-1">6</div>
                </div>
            </div>
        </x-ui.card>
        
        <!-- Total Students -->
        <x-ui.card class="p-4 sm:p-5">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 shrink-0 flex items-center justify-center rounded-lg bg-info/10 text-info">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.42 10.922a2 2 0 0 1-.019 3.138l-8.5 8.54a2 2 0 0 1-2.83 0l-8.5-8.54a2 2 0 0 1-.019-3.138l8.5-8.32a2 2 0 0 1 2.854 0l8.5 8.32Z"/><path d="M12 12v6"/><path d="M8 12v4"/><path d="M16 12v4"/></svg>
                </div>
                <div>
                    <div class="text-sm font-medium text-muted-foreground">Total Students</div>
                    <div class="font-display text-2xl font-bold tracking-tight mt-1">384</div>
                </div>
            </div>
        </x-ui.card>

        <!-- Tests This Month -->
        <x-ui.card class="p-4 sm:p-5">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 shrink-0 flex items-center justify-center rounded-lg bg-success/10 text-success">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>
                </div>
                <div>
                    <div class="text-sm font-medium text-muted-foreground">Tests This Month</div>
                    <div class="font-display text-2xl font-bold tracking-tight mt-1">12</div>
                </div>
            </div>
        </x-ui.card>

        <!-- Question Bank -->
        <x-ui.card class="p-4 sm:p-5">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 shrink-0 flex items-center justify-center rounded-lg bg-warning/10 text-warning">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                </div>
                <div>
                    <div class="text-sm font-medium text-muted-foreground">Question Bank</div>
                    <div class="font-display text-2xl font-bold tracking-tight mt-1">4,850</div>
                </div>
            </div>
        </x-ui.card>
    </div>

    <div class="grid lg:grid-cols-[minmax(0,3fr)_minmax(280px,2fr)] gap-4">
        <x-ui.card class="p-5">
            <h2 class="font-display text-xl font-semibold">This Week</h2>
            <p class="text-sm text-muted-foreground mb-4">Scheduled and recently completed tests</p>
            <div class="divide-y">
                @foreach($week as $item)
                <a href="#" class="grid sm:grid-cols-[90px_1fr_auto] gap-3 py-4 items-center hover:bg-muted/40 -mx-2 px-2 rounded-lg transition-colors">
                    <span class="text-sm font-semibold">{{ $item['day'] }}</span>
                    <div>
                        <p class="text-sm font-medium">{{ $item['test'] }} <span class="text-muted-foreground">({{ $item['batch'] }})</span></p>
                        <p class="text-xs text-muted-foreground mt-0.5">{{ $item['detail'] }}</p>
                    </div>
                    <div class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold @if($item['status'] === 'Completed') text-success border-success/30 @elseif($item['status'] === 'Scheduled') text-info border-info/30 @else text-warning border-warning/30 @endif">
                        {{ $item['status'] }}
                    </div>
                </a>
                @endforeach
            </div>
        </x-ui.card>

        <div class="space-y-4">
            <x-ui.card class="p-5">
                <h3 class="font-semibold flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-warning"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                    Alerts
                </h3>
                <div class="mt-3 space-y-3 text-sm">
                    <p class="rounded-lg bg-warning/10 p-3">3 students haven’t taken any test in 2 weeks.</p>
                    <p class="rounded-lg bg-destructive/10 p-3">NEET 2028 Droppers average dropped 8%.</p>
                </div>
            </x-ui.card>

            <x-ui.card class="p-5">
                <h3 class="font-semibold">Recent Activity</h3>
                <ul class="mt-3 space-y-3 text-sm text-muted-foreground">
                    <li>Ramesh added 45 Physics questions · 38 min ago</li>
                    <li>Chemistry Test completed — 92 students attended</li>
                    <li>Anita created batch “KCET 2028 Weekend”</li>
                </ul>
            </x-ui.card>

            <x-ui.card class="p-5">
                <h3 class="font-semibold mb-3">Quick Actions</h3>
                <div class="grid gap-2">
                    <x-ui.button class="w-full justify-start">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                        Create Test
                    </x-ui.button>
                    <x-ui.button variant="outline" class="w-full justify-start">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>
                        Add Students
                    </x-ui.button>
                    <x-ui.button variant="outline" class="w-full justify-start">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
                        Import Questions
                    </x-ui.button>
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
