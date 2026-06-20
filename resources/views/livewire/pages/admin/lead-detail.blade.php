<?php

use App\Models\Lead;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] class extends Component {
    public Lead $lead;
    public string $generatedEmail = '';
    public string $generatedPassword = '';
    public bool $showSuccess = false;

    public function mount($lead)
    {
        $this->lead = Lead::findOrFail($lead);
    }

    public function approveAndGenerate(): void
    {
        // Approve the lead
        $this->lead->update(['status' => 'approved']);

        // Generate credentials
        $password = Str::password(8, true, true, false, false);
        $email = $this->lead->email ?: Str::slug($this->lead->name) . '@example.com';

        $institution = Institution::create([
            'name' => $this->lead->institution_name ?: $this->lead->name . ' Academy',
            'code' => strtoupper(Str::random(6)),
            'slug' => Str::slug($this->lead->institution_name ?: $this->lead->name . ' Academy') . '-' . rand(100, 999),
            'contact_person' => $this->lead->name,
            'email' => $email,
            'phone' => $this->lead->phone,
            'city' => $this->lead->city,
            'plan' => 'Pro',
            'is_active' => true,
        ]);

        User::create([
            'institution_id' => $institution->id,
            'name' => $this->lead->name,
            'email' => $email,
            'password' => \Illuminate\Support\Facades\Hash::make($password),
            'role' => 'institution_admin',
        ]);

        $this->lead->update(['status' => 'credentials_sent']);
        $this->generatedEmail = $email;
        $this->generatedPassword = $password;
        $this->showSuccess = true;
    }
}; ?>

<div>
    <h2 class="font-display text-2xl font-bold mb-4">Lead Details</h2>
    <div class="grid sm:grid-cols-2 gap-4 mb-4">
        <div>
            <label class="block text-sm font-medium mb-1.5">Contact Name</label>
            <input type="text" readonly class="h-9 w-full rounded-md border border-input bg-muted/25 px-3 text-sm" value="{{ $lead->name }}">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1.5">Institution Name</label>
            <input type="text" readonly class="h-9 w-full rounded-md border border-input bg-muted/25 px-3 text-sm" value="{{ $lead->institution_name }}">
        </div>
    </div>
    <div class="grid sm:grid-cols-2 gap-4 mb-4">
        <div>
            <label class="block text-sm font-medium mb-1.5">Phone</label>
            <input type="text" readonly class="h-9 w-full rounded-md border border-input bg-muted/25 px-3 text-sm" value="{{ $lead->phone }}">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1.5">Email</label>
            <input type="text" readonly class="h-9 w-full rounded-md border border-input bg-muted/25 px-3 text-sm" value="{{ $lead->email }}">
        </div>
    </div>
    <div class="grid sm:grid-cols-2 gap-4 mb-4">
        <div>
            <label class="block text-sm font-medium mb-1.5">City</label>
            <input type="text" readonly class="h-9 w-full rounded-md border border-input bg-muted/25 px-3 text-sm" value="{{ $lead->city }}">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1.5">Student Count</label>
            <input type="text" readonly class="h-9 w-full rounded-md border border-input bg-muted/25 px-3 text-sm" value="{{ $lead->student_count }}">
        </div>
    </div>
    <div class="mb-4">
        <label class="block text-sm font-medium mb-1.5">Exam Types</label>
        <input type="text" readonly class="h-9 w-full rounded-md border border-input bg-muted/25 px-3 text-sm" value="{{ $lead->exam_types }}">
    </div>
    <div class="mb-4">
        <label class="block text-sm font-medium mb-1.5">Notes</label>
        <textarea readonly class="w-full rounded-md border border-input bg-muted/25 px-3 py-2 text-sm" rows="3">{{ $lead->notes }}</textarea>
    </div>
    @if($showSuccess)
        <div class="p-4 rounded-lg bg-success/10 border border-success/30 mb-4">
            <p class="font-semibold text-success mb-2">Credentials Generated!</p>
            <p class="text-sm font-mono"><strong>Login URL:</strong> {{ route('login') }}</p>
            <p class="text-sm font-mono"><strong>Email:</strong> {{ $generatedEmail }}</p>
            <p class="text-sm font-mono"><strong>Password:</strong> {{ $generatedPassword }}</p>
            <p class="text-xs text-muted-foreground mt-2">Copy and send these details securely to the institution.</p>
        </div>
    @endif
    <div class="flex gap-3">
        @if($lead->status !== 'credentials_sent')
            <button wire:click="approveAndGenerate" class="px-4 py-2 bg-success text-white rounded-lg hover:bg-success/90 transition-colors">
                Approve & Generate Credentials
            </button>
        @endif
        <a href="{{ route('admin.dashboard') }}" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary/90 transition-colors">Back to Dashboard</a>
    </div>
</div>
