<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout('layouts.admin')] class extends Component {
    public array $institutions = [];

    public $stats = [];

    public function mount()
    {
        $totalInstitutions = \App\Models\Institution::count();
        $activeInstitutions = \App\Models\Institution::where('is_active', true)->count();
        
        $this->stats = [
            'total_institutions' => $totalInstitutions,
            'active_institutions' => $activeInstitutions,
            'revenue' => '₹4,85,000' // Still mocked as revenue requires billing system
        ];

        $this->institutions = \App\Models\Institution::latest()->take(5)->get()->map(function($i) {
            return [
                'id' => $i->id,
                'name' => $i->name,
                'code' => $i->code ?? 'N/A',
                'contact' => $i->contact_person ?? 'No Contact',
                'phone' => $i->phone ?? 'N/A',
                'plan' => $i->plan ?? 'Pro',
                'status' => $i->is_active ? 'Active' : 'Inactive'
            ];
        })->toArray();
    }
}; ?>

<div class="space-y-6">
    <div class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3 mb-6">
        <div class="min-w-0">
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight truncate">Platform Overview</h1>
            <p class="mt-1 text-sm text-muted-foreground">Real-time snapshot of Examsphere across all institutions.</p>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Total Institutions -->
        <x-ui.card class="p-4 sm:p-5">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 shrink-0 flex items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/></svg>
                </div>
                <div>
                    <div class="text-sm font-medium text-muted-foreground">Total Institutions</div>
                    <div class="font-display text-2xl font-bold tracking-tight mt-1 flex items-baseline gap-2">
                        {{ $stats['total_institutions'] }}
                        <span class="text-xs font-semibold text-success flex items-center bg-success/15 px-1.5 py-0.5 rounded-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-0.5"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                            0.0%
                        </span>
                    </div>
                </div>
            </div>
        </x-ui.card>
        
        <!-- Active -->
        <x-ui.card class="p-4 sm:p-5">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 shrink-0 flex items-center justify-center rounded-lg bg-success/10 text-success">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/></svg>
                </div>
                <div>
                    <div class="text-sm font-medium text-muted-foreground">Active</div>
                    <div class="font-display text-2xl font-bold tracking-tight mt-1 flex items-baseline gap-2">
                        {{ $stats['active_institutions'] }}
                        <span class="text-xs font-semibold text-success flex items-center bg-success/15 px-1.5 py-0.5 rounded-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-0.5"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                            0.0%
                        </span>
                    </div>
                </div>
            </div>
        </x-ui.card>

        <!-- Revenue -->
        <x-ui.card class="p-4 sm:p-5 col-span-2">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 shrink-0 flex items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 15V9"/><path d="M18 15V9"/><path d="M12 18V6"/><path d="M12 6h3a3 3 0 0 1 0 6h-3"/><path d="M12 12h-3a3 3 0 0 0 0 6h3"/></svg>
                </div>
                <div>
                    <div class="text-sm font-medium text-muted-foreground">Monthly Revenue</div>
                    <div class="font-display text-2xl font-bold tracking-tight mt-1 flex items-baseline gap-2">
                        ₹4,85,000
                        <span class="text-xs font-semibold text-success flex items-center bg-success/15 px-1.5 py-0.5 rounded-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mr-0.5"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                            6.8%
                        </span>
                    </div>
                </div>
            </div>
        </x-ui.card>
    </div>

    <!-- Charts Placeholder -->
    <div class="grid lg:grid-cols-3 gap-4">
        <x-ui.card class="lg:col-span-2 p-5 flex flex-col justify-center items-center h-[340px] border-dashed bg-muted/40 text-muted-foreground">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-dasharray="2 2" class="mb-4 opacity-50"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
            <p>Area Chart Placeholder (Revenue Growth)</p>
        </x-ui.card>
        <x-ui.card class="p-5 flex flex-col justify-center items-center h-[340px] border-dashed bg-muted/40 text-muted-foreground">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-dasharray="2 2" class="mb-4 opacity-50"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
            <p>Pie Chart Placeholder</p>
        </x-ui.card>
    </div>

    <!-- Table -->
    <x-ui.card class="p-5">
        <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
            <h3 class="font-display text-lg font-semibold">Recent Institution Registrations</h3>
            <x-ui.button variant="outline" size="sm">View all</x-ui.button>
        </div>
        <div class="overflow-x-auto w-full">
            <table class="w-full caption-bottom text-sm">
                <thead class="[&_tr]:border-b">
                    <tr class="border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted">
                        <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Institution</th>
                        <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Contact Person</th>
                        <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Phone</th>
                        <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Plan</th>
                        <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Status</th>
                    </tr>
                </thead>
                <tbody class="[&_tr:last-child]:border-0">
                    @foreach($institutions as $i)
                    <tr class="border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted">
                        <td class="p-4 align-middle">
                            <div class="font-medium">{{ $i['name'] }}</div>
                            <div class="text-xs text-muted-foreground">{{ $i['code'] }}</div>
                        </td>
                        <td class="p-4 align-middle">{{ $i['contact'] }}</td>
                        <td class="p-4 align-middle font-mono text-xs">{{ $i['phone'] }}</td>
                        <td class="p-4 align-middle">
                            <div class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2">{{ $i['plan'] }}</div>
                        </td>
                        <td class="p-4 align-middle">
                            <div class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 @if($i['status'] === 'Active') bg-success/15 text-success border-success/30 @else bg-info/15 text-info border-info/30 @endif">
                                {{ $i['status'] }}
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
