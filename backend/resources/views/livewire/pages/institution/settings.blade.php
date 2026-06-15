<?php

use App\Models\Institution;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.institution')] class extends Component {
    public string $tab = 'institution';

    // Institution settings
    public string $instName = '';
    public string $instEmail = '';
    public string $instPhone = '';
    public string $instAddress = '';
    public string $instWebsite = '';

    // Preferences
    public string $defaultExamType = 'neet';
    public bool $autoPublishResults = false;
    public bool $allowResultsLookup = true;
    public string $resultVisibility = 'after_end';

    public function mount(): void
    {
        $institution = auth()->user()->institution;
        if (!$institution) return;

        $this->instName = $institution->name;
        $this->instEmail = $institution->email ?? '';
        $this->instPhone = $institution->phone ?? '';
        $this->instAddress = $institution->address ?? '';
        $this->instWebsite = $institution->website ?? '';

        $prefs = $institution->preferences ?? [];
        $this->defaultExamType = $prefs['default_exam_type'] ?? 'neet';
        $this->autoPublishResults = $prefs['auto_publish_results'] ?? false;
        $this->allowResultsLookup = $prefs['allow_results_lookup'] ?? true;
        $this->resultVisibility = $prefs['result_visibility'] ?? 'after_end';
    }

    public function saveInstitution(): void
    {
        $this->validate([
            'instName' => 'required|string|max:200',
            'instEmail' => 'nullable|email|max:150',
            'instPhone' => 'nullable|string|max:20',
            'instWebsite' => 'nullable|url|max:200',
        ]);

        auth()->user()->institution->update([
            'name'    => $this->instName,
            'email'   => $this->instEmail ?: null,
            'phone'   => $this->instPhone ?: null,
            'address' => $this->instAddress ?: null,
            'website' => $this->instWebsite ?: null,
        ]);

        session()->flash('success_institution', 'Institution details saved.');
    }

    public function savePreferences(): void
    {
        auth()->user()->institution->update([
            'preferences' => [
                'default_exam_type'    => $this->defaultExamType,
                'auto_publish_results' => $this->autoPublishResults,
                'allow_results_lookup' => $this->allowResultsLookup,
                'result_visibility'    => $this->resultVisibility,
            ],
        ]);
        session()->flash('success_prefs', 'Preferences saved.');
    }
}; ?>

<div>
<div class="space-y-6 max-w-3xl">
    <div>
        <h1 class="font-display text-2xl font-bold">Settings</h1>
        <p class="text-muted-foreground text-sm mt-1">Manage your institution profile and preferences</p>
    </div>

    <!-- Tabs -->
    <div class="border-b border-border flex gap-0">
        @foreach(['institution'=>'Institution','preferences'=>'Preferences','subscription'=>'Subscription'] as $key => $label)
        <button wire:click="$set('tab','{{ $key }}')" class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors {{ $tab === $key ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if($tab === 'institution')
    <div class="bg-card border border-border rounded-2xl p-6">
        <h2 class="font-semibold mb-5">Institution Profile</h2>
        @if(session('success_institution'))
        <div class="rounded-lg bg-success/10 border border-success/20 px-4 py-3 text-sm text-success mb-4">{{ session('success_institution') }}</div>
        @endif

        <form wire:submit="saveInstitution" class="space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1.5">Institution Name <span class="text-destructive">*</span></label>
                <input wire:model="instName" type="text" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring @error('instName') border-destructive @enderror">
                @error('instName')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5">Email</label>
                    <input wire:model="instEmail" type="email" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Phone</label>
                    <input wire:model="instPhone" type="tel" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Address</label>
                <textarea wire:model="instAddress" rows="2" class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring resize-none"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Website</label>
                <input wire:model="instWebsite" type="url" placeholder="https://" class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
            </div>
            <div class="pt-2">
                <button type="submit" class="rounded-lg bg-primary px-5 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">Save Changes</button>
            </div>
        </form>
    </div>
    @endif

    @if($tab === 'preferences')
    <div class="bg-card border border-border rounded-2xl p-6">
        <h2 class="font-semibold mb-5">Test & Result Preferences</h2>
        @if(session('success_prefs'))
        <div class="rounded-lg bg-success/10 border border-success/20 px-4 py-3 text-sm text-success mb-4">{{ session('success_prefs') }}</div>
        @endif

        <form wire:submit="savePreferences" class="space-y-5">
            <div>
                <label class="block text-sm font-medium mb-1.5">Default Exam Type</label>
                <select wire:model="defaultExamType" class="h-9 rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                    <option value="neet">NEET</option>
                    <option value="jee">JEE</option>
                    <option value="kcet">KCET</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Result Visibility</label>
                <select wire:model="resultVisibility" class="h-9 rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring">
                    <option value="after_end">After test ends</option>
                    <option value="immediate">Immediately after submission</option>
                    <option value="manual">Manually published</option>
                </select>
            </div>
            <div class="space-y-3">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input wire:model="autoPublishResults" type="checkbox" class="h-4 w-4 rounded border-input text-primary focus:ring-primary">
                    <div>
                        <p class="text-sm font-medium">Auto-publish results</p>
                        <p class="text-xs text-muted-foreground">Automatically make results visible after test ends</p>
                    </div>
                </label>
                <label class="flex items-center gap-3 cursor-pointer">
                    <input wire:model="allowResultsLookup" type="checkbox" class="h-4 w-4 rounded border-input text-primary focus:ring-primary">
                    <div>
                        <p class="text-sm font-medium">Allow public results lookup</p>
                        <p class="text-xs text-muted-foreground">Students can look up results using roll number at /results</p>
                    </div>
                </label>
            </div>
            <div class="pt-2">
                <button type="submit" class="rounded-lg bg-primary px-5 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">Save Preferences</button>
            </div>
        </form>
    </div>
    @endif

    @if($tab === 'subscription')
    @php $institution = auth()->user()->institution; @endphp
    <div class="bg-card border border-border rounded-2xl p-6">
        <h2 class="font-semibold mb-5">Subscription</h2>
        <div class="space-y-4">
            <div class="rounded-xl bg-primary/5 border border-primary/20 px-5 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="font-semibold">{{ ucfirst($institution->subscription_plan ?? 'Free') }} Plan</p>
                        @if($institution->subscription_ends_at)
                        <p class="text-sm text-muted-foreground mt-0.5">Expires {{ \Carbon\Carbon::parse($institution->subscription_ends_at)->format('d M Y') }}</p>
                        @else
                        <p class="text-sm text-muted-foreground mt-0.5">No expiry</p>
                        @endif
                    </div>
                    <span class="inline-flex items-center rounded-full bg-success/10 text-success text-xs font-semibold px-2.5 py-0.5">Active</span>
                </div>
            </div>
            <p class="text-sm text-muted-foreground">To upgrade or manage your subscription, contact <a href="mailto:hello@examsphere.in" class="text-primary hover:underline">hello@examsphere.in</a>.</p>
        </div>
    </div>
    @endif
</div>
</div>
