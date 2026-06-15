<?php

use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.institution')] class extends Component {
    public string $search = '';
    public bool $showModal = false;
    public bool $showPermissionsModal = false;
    public ?int $editingId = null;
    public ?User $selectedFaculty = null;

    public string $name = '';
    public string $email = '';
    public string $password = '';

    public bool $canCreateQuestions = false;
    public bool $canCreateTests = false;
    public bool $canViewResults = false;
    public bool $canViewAnalytics = false;
    public bool $canManageStudents = false;

    public function openCreate(): void
    {
        $this->reset(['name','email','password','editingId']);
        $this->showModal = true;
    }

    public function openEdit(User $user): void
    {
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->password = '';
        $this->showModal = true;
    }

    public function openPermissions(User $user): void
    {
        $this->selectedFaculty = $user;
        $perms = $user->permissions ?? [];
        $this->canCreateQuestions = in_array('can_create_questions', $perms);
        $this->canCreateTests = in_array('can_create_tests', $perms);
        $this->canViewResults = in_array('can_view_results', $perms);
        $this->canViewAnalytics = in_array('can_view_analytics', $perms);
        $this->canManageStudents = in_array('can_manage_students', $perms);
        $this->showPermissionsModal = true;
    }

    public function save(): void
    {
        if ($this->editingId) {
            $this->validate([
                'name' => 'required|string|max:150',
                'email' => 'required|email|unique:users,email,'.$this->editingId,
                'password' => 'nullable|min:8',
            ]);
            $data = ['name' => $this->name, 'email' => $this->email];
            if ($this->password) $data['password'] = bcrypt($this->password);
            User::find($this->editingId)?->update($data);
            session()->flash('success', 'Faculty updated.');
        } else {
            $this->validate([
                'name' => 'required|string|max:150',
                'email' => 'required|email|unique:users,email',
                'password' => 'required|min:8',
            ]);
            $institution = auth()->user()->institution;
            $institution->users()->create([
                'name' => $this->name,
                'email' => $this->email,
                'password' => bcrypt($this->password),
                'role' => 'faculty',
            ]);
            session()->flash('success', 'Faculty account created. Share credentials with them.');
        }
        $this->showModal = false;
    }

    public function savePermissions(): void
    {
        $perms = [];
        if ($this->canCreateQuestions) $perms[] = 'can_create_questions';
        if ($this->canCreateTests) $perms[] = 'can_create_tests';
        if ($this->canViewResults) $perms[] = 'can_view_results';
        if ($this->canViewAnalytics) $perms[] = 'can_view_analytics';
        if ($this->canManageStudents) $perms[] = 'can_manage_students';

        $this->selectedFaculty?->update(['permissions' => $perms]);
        session()->flash('success', 'Permissions updated for '.$this->selectedFaculty?->name);
        $this->showPermissionsModal = false;
    }

    public function removeFaculty(User $user): void
    {
        $user->delete();
        session()->flash('success', 'Faculty member removed.');
    }

    public function with(): array
    {
        $faculty = User::query()
            ->where('institution_id', auth()->user()->institution_id)
            ->where('role', 'faculty')
            ->when($this->search, fn($q) => $q->where(function($q2) {
                $q2->where('name','like',"%{$this->search}%")
                   ->orWhere('email','like',"%{$this->search}%");
            }))
            ->orderBy('name')
            ->get();

        return ['faculty' => $faculty];
    }
}; ?>

<div>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="font-display text-2xl font-bold">Faculty</h1>
            <p class="text-muted-foreground text-sm mt-1">Manage faculty accounts and their permissions</p>
        </div>
        <button wire:click="openCreate" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
            Add Faculty
        </button>
    </div>

    @if(session('success'))
    <div class="rounded-lg bg-success/10 border border-success/20 px-4 py-3 text-sm text-success font-medium">{{ session('success') }}</div>
    @endif

    <div class="relative max-w-sm">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search faculty..." class="h-9 w-full rounded-md border border-input bg-background pl-9 pr-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
    </div>

    @php
        $permLabels = [
            'can_create_questions' => 'Create Questions',
            'can_create_tests' => 'Create Tests',
            'can_view_results' => 'View Results',
            'can_view_analytics' => 'View Analytics',
            'can_manage_students' => 'Manage Students',
        ];
    @endphp

    @if($faculty->isEmpty())
    <div class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-border py-20 text-center">
        <div class="rounded-full bg-muted p-4 mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="text-muted-foreground"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
        </div>
        <p class="font-semibold text-lg mb-1">No faculty members yet</p>
        <p class="text-muted-foreground text-sm mb-6">Add faculty to let them create tests and manage students</p>
        <button wire:click="openCreate" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground">Add First Faculty</button>
    </div>
    @else
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach($faculty as $member)
        <div class="rounded-2xl border border-border bg-card p-5 hover:border-primary/30 transition-colors">
            <div class="flex items-start justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="h-10 w-10 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold">{{ substr($member->name, 0, 1) }}</div>
                    <div>
                        <p class="font-semibold text-sm">{{ $member->name }}</p>
                        <p class="text-xs text-muted-foreground">{{ $member->email }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-1">
                    <button wire:click="openEdit({{ $member->id }})" class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                    </button>
                    <button wire:click="removeFaculty({{ $member->id }})" wire:confirm="Remove {{ $member->name }}?" class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="m19 6-.867 12.142A2 2 0 0 1 16.138 20H7.862a2 2 0 0 1-1.995-1.858L5 6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                    </button>
                </div>
            </div>

            <div class="mb-4">
                <p class="text-xs font-medium text-muted-foreground mb-2">Permissions</p>
                <div class="flex flex-wrap gap-1.5">
                    @forelse(($member->permissions ?? []) as $perm)
                    <span class="inline-flex items-center rounded-full bg-primary/10 text-primary text-[10px] px-2 py-0.5 font-medium">{{ $permLabels[$perm] ?? $perm }}</span>
                    @empty
                    <span class="text-xs text-muted-foreground italic">No permissions assigned</span>
                    @endforelse
                </div>
            </div>

            <button wire:click="openPermissions({{ $member->id }})" class="w-full rounded-lg border border-border py-1.5 text-xs font-medium hover:bg-muted transition-colors">
                Edit Permissions
            </button>
        </div>
        @endforeach
    </div>
    @endif
</div>

<!-- Add/Edit Faculty Modal -->
@if($showModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
    <div class="w-full max-w-md rounded-2xl bg-card border border-border shadow-2xl p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="font-display text-xl font-bold">{{ $editingId ? 'Edit' : 'Add' }} Faculty</h2>
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
                <label class="block text-sm font-medium mb-1.5">Email <span class="text-destructive">*</span></label>
                <input wire:model="email" type="email" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring @error('email') border-destructive @enderror">
                @error('email')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Password {{ $editingId ? '(leave blank to keep current)' : '' }} <span class="text-destructive">{{ $editingId ? '' : '*' }}</span></label>
                <input wire:model="password" type="password" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring @error('password') border-destructive @enderror">
                @error('password')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="flex-1 rounded-lg bg-primary py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90">{{ $editingId ? 'Save Changes' : 'Create Account' }}</button>
                <button type="button" wire:click="$set('showModal',false)" class="flex-1 rounded-lg border border-border py-2 text-sm font-medium hover:bg-muted">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endif

<!-- Permissions Modal -->
@if($showPermissionsModal && $selectedFaculty)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
    <div class="w-full max-w-sm rounded-2xl bg-card border border-border shadow-2xl p-6">
        <div class="flex items-center justify-between mb-2">
            <h2 class="font-display text-xl font-bold">Permissions</h2>
            <button wire:click="$set('showPermissionsModal',false)" class="h-8 w-8 rounded flex items-center justify-center text-muted-foreground hover:bg-muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <p class="text-sm text-muted-foreground mb-5">{{ $selectedFaculty->name }}</p>

        <div class="space-y-3">
            @foreach(['canCreateQuestions'=>'Create Questions','canCreateTests'=>'Create Tests','canViewResults'=>'View Results','canViewAnalytics'=>'View Analytics','canManageStudents'=>'Manage Students'] as $prop => $label)
            <label class="flex items-center gap-3 cursor-pointer group">
                <div class="relative">
                    <input type="checkbox" wire:model="{{ $prop }}" class="sr-only peer">
                    <div class="h-5 w-5 rounded border-2 border-input peer-checked:bg-primary peer-checked:border-primary flex items-center justify-center transition-colors">
                        <svg class="hidden peer-checked:block w-3 h-3 text-primary-foreground" viewBox="0 0 12 12" fill="none"><path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                </div>
                <span class="text-sm font-medium group-hover:text-primary transition-colors">{{ $label }}</span>
            </label>
            @endforeach
        </div>

        <div class="flex items-center gap-3 mt-6">
            <button wire:click="savePermissions" class="flex-1 rounded-lg bg-primary py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90">Save</button>
            <button wire:click="$set('showPermissionsModal',false)" class="flex-1 rounded-lg border border-border py-2 text-sm font-medium hover:bg-muted">Cancel</button>
        </div>
    </div>
</div>
@endif
</div>
