<?php

use App\Models\PlatformSetting;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] class extends Component {
    public array $settings = [];
    public bool $showModal = false;
    public string $editKey = '';
    public string $editValue = '';
    public string $editDescription = '';
    public string $newKey = '';
    public string $newValue = '';
    public string $newDescription = '';

    public function mount(): void
    {
        $this->loadSettings();
    }

    public function loadSettings(): void
    {
        $this->settings = PlatformSetting::orderBy('setting_key')->get(['setting_key','setting_value','description'])->toArray();
    }

    public function openEdit(string $key): void
    {
        $setting = PlatformSetting::where('setting_key', $key)->first();
        $this->editKey = $key;
        $this->editValue = $setting?->setting_value ?? '';
        $this->editDescription = $setting?->description ?? '';
        $this->showModal = true;
    }

    public function saveEdit(): void
    {
        $this->validate(['editKey' => 'required', 'editValue' => 'nullable|string|max:5000']);
        PlatformSetting::where('setting_key', $this->editKey)->update(['setting_value' => $this->editValue, 'description' => $this->editDescription]);
        $this->showModal = false;
        $this->loadSettings();
        session()->flash('success', 'Setting updated.');
    }

    public function addSetting(): void
    {
        $this->validate([
            'newKey' => 'required|string|max:100|unique:platform_settings,setting_key',
            'newValue' => 'nullable|string|max:5000',
        ]);
        PlatformSetting::create(['setting_key' => $this->newKey, 'setting_value' => $this->newValue, 'description' => $this->newDescription]);
        $this->reset(['newKey','newValue','newDescription']);
        $this->loadSettings();
        session()->flash('success', 'Setting added.');
    }

    public function deleteSetting(string $key): void
    {
        PlatformSetting::where('setting_key', $key)->delete();
        $this->loadSettings();
        session()->flash('success', 'Setting deleted.');
    }
}; ?>

<div>
<div class="space-y-6 max-w-3xl">
    <div>
        <h1 class="font-display text-2xl font-bold">Platform Settings</h1>
        <p class="text-muted-foreground text-sm mt-1">Global configuration for the Examsphere platform</p>
    </div>

    @if(session('success'))
    <div class="rounded-lg bg-success/10 border border-success/20 px-4 py-3 text-sm text-success">{{ session('success') }}</div>
    @endif

    <!-- Settings Table -->
    <div class="rounded-xl border border-border bg-card overflow-hidden">
        <div class="px-4 py-3 border-b border-border bg-muted/30 flex items-center justify-between">
            <h2 class="font-semibold text-sm">Settings</h2>
        </div>
        <table class="w-full text-sm">
            <thead class="border-b border-border">
                <tr>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Key</th>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Value</th>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground hidden md:table-cell">Description</th>
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse($settings as $setting)
                <tr class="hover:bg-muted/20 transition-colors">
                    <td class="px-4 py-3 font-mono text-xs font-medium">{{ $setting['setting_key'] }}</td>
                    <td class="px-4 py-3 max-w-[200px]">
                        <span class="truncate block text-sm">{{ $setting['setting_value'] ?? '—' }}</span>
                    </td>
                    <td class="px-4 py-3 text-muted-foreground text-xs hidden md:table-cell">{{ $setting['description'] ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-1">
                            <button wire:click="openEdit('{{ $setting['setting_key'] }}')" class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                            </button>
                            <button wire:click="deleteSetting('{{ $setting['setting_key'] }}')" wire:confirm="Delete setting '{{ $setting['setting_key'] }}'?" class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="m19 6-.867 12.142A2 2 0 0 1 16.138 20H7.862a2 2 0 0 1-1.995-1.858L5 6"/></svg>
                            </button>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="4" class="px-4 py-12 text-center text-muted-foreground">No settings configured yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Add new setting form -->
    <div class="rounded-xl border border-border bg-card p-5">
        <h3 class="font-semibold mb-4">Add New Setting</h3>
        <form wire:submit="addSetting" class="space-y-3">
            <div class="grid sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium mb-1.5">Key <span class="text-destructive">*</span></label>
                    <input wire:model="newKey" type="text" placeholder="e.g. max_students_per_batch" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm font-mono focus:outline-none focus:ring-1 focus:ring-ring @error('newKey') border-destructive @enderror">
                    @error('newKey')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Value</label>
                    <input wire:model="newValue" type="text" placeholder="e.g. 500" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Description</label>
                <input wire:model="newDescription" type="text" placeholder="What this setting controls" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
            </div>
            <div>
                <button type="submit" class="rounded-lg bg-primary px-5 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">Add Setting</button>
            </div>
        </form>
    </div>
</div>

@if($showModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
    <div class="w-full max-w-md rounded-2xl bg-card border border-border shadow-2xl p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="font-display text-xl font-bold">Edit Setting</h2>
            <button wire:click="$set('showModal',false)" class="h-8 w-8 rounded flex items-center justify-center text-muted-foreground hover:bg-muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <form wire:submit="saveEdit" class="space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1.5">Key</label>
                <input type="text" value="{{ $editKey }}" disabled class="h-9 w-full rounded-md border border-input bg-muted/30 px-3 text-sm font-mono text-muted-foreground">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Value</label>
                <textarea wire:model="editValue" rows="3" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring resize-none"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Description</label>
                <input wire:model="editDescription" type="text" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
            </div>
            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="flex-1 rounded-lg bg-primary py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90">Save</button>
                <button type="button" wire:click="$set('showModal',false)" class="flex-1 rounded-lg border border-border py-2 text-sm font-medium hover:bg-muted">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endif
</div>
