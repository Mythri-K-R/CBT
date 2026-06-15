<?php

use App\Models\Lead;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] class extends Component {
    public string $search = '';
    public bool $showModal = false;
    public ?int $editingId = null;
    public string $draggingFrom = '';

    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $institutionName = '';
    public string $city = '';
    public string $examTypes = '';
    public string $studentCount = '';
    public string $notes = '';
    public string $status = 'new';

    public function openCreate(): void
    {
        $this->reset(['name','email','phone','institutionName','city','examTypes','studentCount','notes','editingId','generatedEmail','generatedPassword']);
        $this->status = 'new';
        $this->showModal = true;
    }

    public function openEdit(Lead $lead): void
    {
        $this->reset(['generatedEmail', 'generatedPassword']);
        $this->editingId = $lead->id;
        $this->name = $lead->name;
        $this->email = $lead->email ?? '';
        $this->phone = $lead->phone ?? '';
        $this->institutionName = $lead->institution_name ?? '';
        $this->city = $lead->city ?? '';
        $this->examTypes = $lead->exam_types ?? '';
        $this->studentCount = $lead->student_count ?? '';
        $this->notes = $lead->notes ?? '';
        $this->status = $lead->status;
        $this->showModal = true;
    }

    public function save(): void
    {
        if ($this->editingId) return; // Prevent editing

        $this->validate([
            'name' => 'required|string|max:150',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:150',
            'status' => 'required|in:new,under_review,approved,credentials_sent,rejected',
        ]);

        $data = [
            'name' => $this->name,
            'email' => $this->email ?: null,
            'phone' => $this->phone ?: null,
            'institution_name' => $this->institutionName ?: null,
            'city' => $this->city ?: null,
            'exam_types' => $this->examTypes ?: null,
            'student_count' => $this->studentCount ?: null,
            'notes' => $this->notes ?: null,
            'status' => $this->status,
        ];

        Lead::create($data);

        $this->showModal = false;
        session()->flash('success', 'Lead created.');
    }

    public string $generatedEmail = '';
    public string $generatedPassword = '';

    public function generateCredentials(): void
    {
        $lead = Lead::findOrFail($this->editingId);

        $password = \Illuminate\Support\Str::password(8, true, true, false, false);
        $email = $lead->email ?: \Illuminate\Support\Str::slug($lead->name) . '@example.com';
        
        $institution = \App\Models\Institution::create([
            'name' => $lead->institution_name ?: $lead->name . ' Academy',
            'code' => strtoupper(\Illuminate\Support\Str::random(6)),
            'slug' => \Illuminate\Support\Str::slug($lead->institution_name ?: $lead->name . ' Academy') . '-' . rand(100, 999),
            'contact_person' => $lead->name,
            'email' => $email,
            'phone' => $lead->phone,
            'city' => $lead->city,
            'plan' => 'Pro',
            'is_active' => true,
        ]);

        \App\Models\User::create([
            'institution_id' => $institution->id,
            'name' => $lead->name,
            'email' => $email,
            'password' => \Illuminate\Support\Facades\Hash::make($password),
            'role' => 'institution_admin',
        ]);

        $lead->update(['status' => 'credentials_sent', 'institution_id' => $institution->id]);
        $this->status = 'credentials_sent';
        
        $this->generatedEmail = $email;
        $this->generatedPassword = $password;

        session()->flash('success', 'Credentials generated successfully!');
    }

    public function moveStatus(int $id, string $status): void
    {
        Lead::find($id)?->update(['status' => $status]);
    }

    public function deleteLead(Lead $lead): void
    {
        $lead->delete();
        session()->flash('success', 'Lead removed.');
    }

    public function with(): array
    {
        $statuses = ['new', 'under_review', 'approved', 'credentials_sent', 'rejected'];
        $leads = Lead::query()
            ->when($this->search, fn($q) => $q->where(function($q2) {
                $q2->where('name','like',"%{$this->search}%")
                   ->orWhere('institution_name','like',"%{$this->search}%")
                   ->orWhere('phone','like',"%{$this->search}%");
            }))
            ->orderByRaw("FIELD(status, 'new','under_review','approved','credentials_sent','rejected')")
            ->orderByDesc('created_at')
            ->get();

        $byStatus = collect($statuses)->mapWithKeys(fn($s) => [$s => $leads->where('status', $s)->values()]);

        return compact('byStatus', 'statuses', 'leads');
    }
}; ?>

<div>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="font-display text-2xl font-bold">Leads</h1>
            <p class="text-muted-foreground text-sm mt-1">Institution acquisition pipeline</p>
        </div>
        <button wire:click="openCreate" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
            Add Lead
        </button>
    </div>

    @if(session('success'))
    <div class="rounded-lg bg-success/10 border border-success/20 px-4 py-3 text-sm text-success">{{ session('success') }}</div>
    @endif

    <div class="relative max-w-xs">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search leads..." class="h-9 w-full rounded-md border border-input bg-background pl-9 pr-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
    </div>

    @php
        $columnDefs = [
            'new' => ['New', 'bg-muted'],
            'under_review' => ['Under Review', 'bg-info/10'],
            'approved' => ['Approved', 'bg-success/10'],
            'credentials_sent' => ['Credentials Sent', 'bg-primary/10'],
            'rejected' => ['Rejected', 'bg-destructive/10'],
        ];
    @endphp

    <!-- Kanban board -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 overflow-x-auto">
        @foreach($columnDefs as $status => [$label, $bg])
        <div class="min-w-[220px]">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ $label }}</span>
                <span class="rounded-full bg-muted px-2 py-0.5 text-xs font-semibold">{{ $byStatus[$status]->count() }}</span>
            </div>
            <div class="space-y-3 min-h-[200px]">
                @foreach($byStatus[$status] as $lead)
                <a href="{{ route('admin.lead.detail', $lead->id) }}" class="block">
                    <div class="group rounded-xl border border-border bg-card p-4 hover:border-primary/30 transition-colors">
                        <div class="flex items-start justify-between mb-2">
                            <p class="font-semibold text-sm">{{ $lead->name }}</p>
                            <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button wire:click.prevent="openEdit({{ $lead->id }})" class="h-6 w-6 rounded flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                                </button>
                                <button wire:click.prevent="deleteLead({{ $lead->id }})" wire:confirm="Delete this lead?" class="h-6 w-6 rounded flex items-center justify-center text-muted-foreground hover:text-destructive hover:bg-destructive/10">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="m19 6-.867 12.142A2 2 0 0 1 16.138 20H7.862a2 2 0 0 1-1.995-1.858L5 6"/></svg>
                                </button>
                            </div>
                        </div>
                        @if($lead->institution_id)
                        <a href="{{ route('admin.institutions.show', $lead->institution_id) }}" class="text-xs text-primary mb-1 hover:underline">{{ $lead->institution_name }}{{ $lead->city ? ', '.$lead->city : '' }}</a>
                        @else
                        <p class="text-xs text-muted-foreground mb-1">{{ $lead->institution_name }}{{ $lead->city ? ', '.$lead->city : '' }}</p>
                        @endif
                        @if($lead->phone)
                        <p class="text-xs text-muted-foreground">{{ $lead->phone }}</p>
                        @endif
                        @if($lead->student_count)
                        <p class="text-xs text-muted-foreground mt-1">~{{ $lead->student_count }} students</p>
                        @endif

                        <!-- Status actions -->
                        <div class="mt-3 flex flex-wrap gap-1">
                            @foreach(array_keys($columnDefs) as $s)
                            @if($s !== $status)
                            <button wire:click.prevent="moveStatus({{ $lead->id }}, '{{ $s }}')" class="text-[10px] rounded px-2 py-0.5 border border-border hover:bg-muted transition-colors">→ {{ $columnDefs[$s][0] }}</button>
                            @endif
                            @endforeach
                        </div>
                    </div>
                </a>
                @endforeach
            </div>
        </div>
        @endforeach
    </div>
</div>

<!-- Modal -->
@if($showModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
    <div class="w-full max-w-lg rounded-2xl bg-card border border-border shadow-2xl p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-6">
            <h2 class="font-display text-xl font-bold">{{ $editingId ? 'Edit' : 'Add' }} Lead</h2>
            <button wire:click="$set('showModal',false)" class="h-8 w-8 rounded flex items-center justify-center text-muted-foreground hover:bg-muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <form wire:submit="save" class="space-y-4">
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5">Contact Name <span class="text-destructive">*</span></label>
                    <input wire:model="name" type="text" {{ $editingId ? 'disabled' : '' }} class="h-9 w-full rounded-md border border-input {{ $editingId ? 'bg-muted/50' : 'bg-background' }} px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring @error('name') border-destructive @enderror">
                    @error('name')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Institution Name</label>
                    <input wire:model="institutionName" type="text" {{ $editingId ? 'disabled' : '' }} class="h-9 w-full rounded-md border border-input {{ $editingId ? 'bg-muted/50' : 'bg-background' }} px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                </div>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5">Phone</label>
                    <input wire:model="phone" type="tel" {{ $editingId ? 'disabled' : '' }} class="h-9 w-full rounded-md border border-input {{ $editingId ? 'bg-muted/50' : 'bg-background' }} px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Email</label>
                    <input wire:model="email" type="email" {{ $editingId ? 'disabled' : '' }} class="h-9 w-full rounded-md border border-input {{ $editingId ? 'bg-muted/50' : 'bg-background' }} px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                </div>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5">City</label>
                    <input wire:model="city" type="text" {{ $editingId ? 'disabled' : '' }} class="h-9 w-full rounded-md border border-input {{ $editingId ? 'bg-muted/50' : 'bg-background' }} px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Student Count</label>
                    <input wire:model="studentCount" type="text" {{ $editingId ? 'disabled' : '' }} placeholder="e.g. 200" class="h-9 w-full rounded-md border border-input {{ $editingId ? 'bg-muted/50' : 'bg-background' }} px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Exam Types</label>
                <input wire:model="examTypes" type="text" {{ $editingId ? 'disabled' : '' }} placeholder="NEET, JEE, KCET" class="h-9 w-full rounded-md border border-input {{ $editingId ? 'bg-muted/50' : 'bg-background' }} px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Status</label>
                <select wire:model="status" {{ $editingId ? 'disabled' : '' }} class="h-9 w-full rounded-md border border-input {{ $editingId ? 'bg-muted/50' : 'bg-background' }} px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                    @foreach($columnDefs as $s => [$l, $_])
                    <option value="{{ $s }}">{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Notes</label>
                <textarea wire:model="notes" rows="3" {{ $editingId ? 'disabled' : '' }} class="w-full rounded-md border border-input {{ $editingId ? 'bg-muted/50' : 'bg-background' }} px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring resize-none"></textarea>
            </div>
            @if($generatedEmail)
                <div class="mt-4 p-4 rounded-lg bg-success/10 border border-success/30">
                    <p class="font-semibold text-success mb-2">Credentials Generated!</p>
                    <p class="text-sm font-mono mb-1"><strong>Login URL:</strong> {{ route('login') }}</p>
                    <p class="text-sm font-mono mb-1"><strong>Email:</strong> {{ $generatedEmail }}</p>
                    <p class="text-sm font-mono"><strong>Password:</strong> {{ $generatedPassword }}</p>
                    <p class="text-xs text-muted-foreground mt-3">Copy and send these details to the client securely.</p>
                </div>
            @endif

            <div class="flex items-center gap-3 pt-2">
                @if(!$editingId)
                    <button type="submit" class="flex-1 rounded-lg bg-primary py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90">Add Lead</button>
                @elseif($status !== 'credentials_sent')
                    <button type="button" wire:click="generateCredentials" class="flex-1 rounded-lg bg-success py-2 text-sm font-semibold text-white hover:bg-success/90 transition-colors">
                        Generate Credentials & Send
                    </button>
                @endif
                <button type="button" wire:click="$set('showModal',false)" class="flex-1 rounded-lg border border-border py-2 text-sm font-medium hover:bg-muted">Close</button>
            </div>
        </form>
    </div>
</div>
@endif
</div>
