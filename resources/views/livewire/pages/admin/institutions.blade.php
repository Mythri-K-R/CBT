<?php

use App\Models\Institution;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $planFilter = '';
    public bool $showModal = false;
    public ?int $editingId = null;

    public string $name = '';
    public string $contactPerson = '';
    public string $email = '';
    public string $phone = '';
    public string $city = '';
    public string $type = 'coaching_center';
    public string $plan = 'trial';
    public string $subscriptionEnd = '';

    public function openCreate(): void
    {
        $this->reset(['name','contactPerson','email','phone','city','type','plan','subscriptionEnd','editingId']);
        $this->type = 'coaching_center';
        $this->plan = 'trial';
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $institution = Institution::find($id);
        if (!$institution) return;
        $this->editingId = $institution->id;
        $this->name = $institution->name;
        $this->contactPerson = $institution->contact_person ?? '';
        $this->email = $institution->email ?? '';
        $this->phone = $institution->phone ?? '';
        $this->city = $institution->city ?? '';
        $this->type = $institution->type ?? 'coaching_center';
        $this->plan = $institution->plan ?? 'trial';
        $this->subscriptionEnd = $institution->subscription_end?->format('Y-m-d') ?? '';
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'name'          => 'required|string|max:200',
            'contactPerson' => 'required|string|max:150',
            'email'         => 'required|email|max:150',
            'phone'         => 'required|string|max:20',
            'type'          => 'required|in:pu_college,degree_college,neet_academy,jee_academy,kcet_institute,coaching_center,school,other',
            'plan'          => 'required|in:trial,starter,growth,enterprise',
            'subscriptionEnd' => 'nullable|date',
        ]);

        $data = [
            'name'             => $this->name,
            'contact_person'   => $this->contactPerson,
            'email'            => $this->email,
            'phone'            => $this->phone,
            'city'             => $this->city ?: null,
            'type'             => $this->type,
            'plan'             => $this->plan,
            'subscription_end' => $this->subscriptionEnd ?: null,
        ];

        if ($this->editingId) {
            Institution::find($this->editingId)?->update($data);
        } else {
            $baseName = \Illuminate\Support\Str::slug($this->name);
            $data['code'] = strtoupper(\Illuminate\Support\Str::random(6));
            $data['slug'] = $baseName . '-' . rand(100, 999);
            Institution::create($data);
        }

        $this->showModal = false;
        session()->flash('success', 'Institution saved.');
    }

    public function with(): array
    {
        $institutions = Institution::query()
            ->withCount(['users', 'students', 'tests'])
            ->when($this->search, fn($q) => $q->where('name','like',"%{$this->search}%")->orWhere('city','like',"%{$this->search}%"))
            ->when($this->planFilter, fn($q) => $q->where('plan', $this->planFilter))
            ->latest()
            ->paginate(15);

        return ['institutions' => $institutions];
    }
}; ?>

<div>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="font-display text-2xl font-bold">Institutions</h1>
            <p class="text-muted-foreground text-sm mt-1">All registered coaching institutes</p>
        </div>
        <button wire:click="openCreate" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
            Add Institution
        </button>
    </div>

    @if(session('success'))
    <div class="rounded-lg bg-success/10 border border-success/20 px-4 py-3 text-sm text-success">{{ session('success') }}</div>
    @endif

    <div class="flex flex-wrap gap-3">
        <div class="relative flex-1 min-w-[200px] max-w-xs">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search institutions..." class="h-9 w-full rounded-md border border-input bg-background pl-9 pr-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
        </div>
        <select wire:model.live="planFilter" class="h-9 rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
            <option value="">All Plans</option>
            <option value="trial">Trial</option>
            <option value="starter">Starter</option>
            <option value="growth">Growth</option>
            <option value="enterprise">Enterprise</option>
        </select>
    </div>

    <div class="rounded-xl border border-border bg-card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="border-b border-border bg-muted/30">
                <tr>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Institution</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground hidden sm:table-cell">Users</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground hidden sm:table-cell">Students</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground hidden md:table-cell">Tests</th>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Plan</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse($institutions as $inst)
                @php
                    $planColors = ['trial'=>'bg-muted text-muted-foreground','starter'=>'bg-info/10 text-info','growth'=>'bg-success/10 text-success','enterprise'=>'bg-primary/10 text-primary'];
                    $pc = $planColors[$inst->plan ?? 'trial'] ?? 'bg-muted text-muted-foreground';
                @endphp
                <tr class="hover:bg-muted/20 transition-colors">
                    <td class="px-4 py-3">
                        <div class="font-medium">{{ $inst->name }}</div>
                        @if($inst->city)<div class="text-xs text-muted-foreground">{{ $inst->city }}</div>@endif
                    </td>
                    <td class="px-4 py-3 text-right hidden sm:table-cell text-muted-foreground">{{ $inst->users_count }}</td>
                    <td class="px-4 py-3 text-right hidden sm:table-cell text-muted-foreground">{{ $inst->students_count }}</td>
                    <td class="px-4 py-3 text-right hidden md:table-cell text-muted-foreground">{{ $inst->tests_count }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $pc }}">{{ ucfirst($inst->plan ?? 'trial') }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-1">
                            <a href="{{ route('admin.institutions.show', $inst) }}" wire:navigate class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                            </a>
                            <button wire:click="openEdit({{ $inst->id }})" class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                            </button>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-16 text-center text-muted-foreground">No institutions found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $institutions->links() }}</div>
</div>

@if($showModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
    <div class="w-full max-w-md rounded-2xl bg-card border border-border shadow-2xl p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="font-display text-xl font-bold">{{ $editingId ? 'Edit' : 'Add' }} Institution</h2>
            <button wire:click="$set('showModal',false)" class="h-8 w-8 rounded flex items-center justify-center text-muted-foreground hover:bg-muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1.5">Institution Name <span class="text-destructive">*</span></label>
                <input wire:model="name" type="text" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring @error('name') border-destructive @enderror">
                @error('name')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Contact Person <span class="text-destructive">*</span></label>
                <input wire:model="contactPerson" type="text" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring @error('contactPerson') border-destructive @enderror">
                @error('contactPerson')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium mb-1.5">Email <span class="text-destructive">*</span></label>
                    <input wire:model="email" type="email" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring @error('email') border-destructive @enderror">
                    @error('email')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Phone <span class="text-destructive">*</span></label>
                    <input wire:model="phone" type="tel" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring @error('phone') border-destructive @enderror">
                    @error('phone')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium mb-1.5">Type <span class="text-destructive">*</span></label>
                    <select wire:model="type" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                        <option value="coaching_center">Coaching Center</option>
                        <option value="neet_academy">NEET Academy</option>
                        <option value="jee_academy">JEE Academy</option>
                        <option value="kcet_institute">KCET Institute</option>
                        <option value="pu_college">PU College</option>
                        <option value="degree_college">Degree College</option>
                        <option value="school">School</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">City</label>
                    <input wire:model="city" type="text" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium mb-1.5">Plan</label>
                    <select wire:model="plan" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                        <option value="trial">Trial</option>
                        <option value="starter">Starter</option>
                        <option value="growth">Growth</option>
                        <option value="enterprise">Enterprise</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Subscription End</label>
                    <input wire:model="subscriptionEnd" type="date" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                </div>
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="flex-1 rounded-lg bg-primary py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90">{{ $editingId ? 'Save' : 'Create' }}</button>
                <button type="button" wire:click="$set('showModal',false)" class="flex-1 rounded-lg border border-border py-2 text-sm font-medium hover:bg-muted">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endif
</div>
