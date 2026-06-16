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

    public function mount(Lead $lead): void
    {
        $this->lead = $lead->load('institution');
    }

    public function updateStatus(string $status): void
    {
        $this->lead->update(['status' => $status]);
        $this->lead->refresh();
    }

    public function approveAndGenerate(): void
    {
        if ($this->lead->institution_id) {
            session()->flash('error', 'Credentials already generated for this lead.');
            return;
        }

        $password = Str::password(8, true, true, false, false);
        $email    = $this->lead->email ?: Str::slug($this->lead->name) . '@example.com';
        $instName = $this->lead->institution_name ?: $this->lead->name . ' Academy';
        $username = Str::slug($this->lead->name) . rand(100, 999);

        $institution = Institution::create([
            'name'           => $instName,
            'code'           => strtoupper(Str::random(6)),
            'slug'           => Str::slug($instName) . '-' . rand(100, 999),
            'type'           => 'coaching_center',
            'contact_person' => $this->lead->name,
            'email'          => $email,
            'phone'          => $this->lead->phone ?: '0000000000',
            'city'           => $this->lead->city,
            'plan'           => 'starter',
            'is_active'      => true,
        ]);

        User::create([
            'institution_id' => $institution->id,
            'name'           => $this->lead->name,
            'email'          => $email,
            'username'       => $username,
            'password'       => \Illuminate\Support\Facades\Hash::make($password),
            'role'           => 'institution_admin',
        ]);

        $this->lead->update([
            'status'         => 'credentials_sent',
            'institution_id' => $institution->id,
        ]);
        $this->lead->refresh()->load('institution');

        $this->generatedEmail    = $email;
        $this->generatedPassword = $password;
        $this->showSuccess       = true;
    }
}; ?>

<div>
<div class="space-y-6 max-w-3xl">
    <!-- Header -->
    <div class="flex items-center gap-4">
        <a href="{{ route('admin.leads') }}" wire:navigate class="h-9 w-9 rounded-lg border border-border flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div>
            <h1 class="font-display text-2xl font-bold">{{ $lead->name }}</h1>
            <p class="text-sm text-muted-foreground mt-0.5">{{ $lead->institution_name }}{{ $lead->city ? ', ' . $lead->city : '' }}</p>
        </div>
        @php
            $statusColors = [
                'new'              => 'bg-muted text-muted-foreground',
                'under_review'     => 'bg-info/10 text-info',
                'approved'         => 'bg-success/10 text-success',
                'credentials_sent' => 'bg-primary/10 text-primary',
                'rejected'         => 'bg-destructive/10 text-destructive',
            ];
            $statusLabels = [
                'new'              => 'New',
                'under_review'     => 'Under Review',
                'approved'         => 'Approved',
                'credentials_sent' => 'Credentials Sent',
                'rejected'         => 'Rejected',
            ];
            $sc = $statusColors[$lead->status] ?? 'bg-muted text-muted-foreground';
        @endphp
        <span class="ml-auto inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $sc }}">
            {{ $statusLabels[$lead->status] ?? ucfirst($lead->status) }}
        </span>
    </div>

    @if(session('error'))
    <div class="rounded-lg bg-destructive/10 border border-destructive/20 px-4 py-3 text-sm text-destructive">{{ session('error') }}</div>
    @endif

    <!-- Contact Info -->
    <div class="rounded-xl border border-border bg-card p-5">
        <h2 class="font-semibold mb-4">Contact Details</h2>
        <dl class="grid sm:grid-cols-2 gap-x-8 gap-y-0 text-sm">
            @foreach([
                ['Contact Name', $lead->name],
                ['Institution',  $lead->institution_name ?? '—'],
                ['Email',        $lead->email ?? '—'],
                ['Phone',        $lead->phone ?? '—'],
                ['City',         $lead->city ?? '—'],
                ['Student Count', $lead->student_count ? '~' . $lead->student_count . ' students' : '—'],
                ['Exam Types',   $lead->exam_types ?? '—'],
                ['Added',        $lead->created_at->format('d M Y')],
            ] as [$label, $val])
            <div class="flex justify-between py-2 border-b border-border/50 last:border-0">
                <dt class="text-muted-foreground">{{ $label }}</dt>
                <dd class="font-medium text-right">{{ $val }}</dd>
            </div>
            @endforeach
        </dl>
        @if($lead->notes)
        <div class="mt-4 pt-4 border-t border-border/50">
            <p class="text-sm font-medium text-muted-foreground mb-1.5">Notes</p>
            <p class="text-sm">{{ $lead->notes }}</p>
        </div>
        @endif
    </div>

    <!-- Status Pipeline -->
    <div class="rounded-xl border border-border bg-card p-5">
        <h2 class="font-semibold mb-4">Pipeline Status</h2>
        <div class="flex flex-wrap gap-2">
            @foreach($statusLabels as $s => $l)
            <button
                wire:click="updateStatus('{{ $s }}')"
                class="rounded-lg px-3 py-1.5 text-xs font-semibold border transition-colors
                    {{ $lead->status === $s
                        ? ($statusColors[$s] ?? 'bg-primary/10 text-primary') . ' border-current'
                        : 'border-border text-muted-foreground hover:bg-muted' }}">
                {{ $l }}
            </button>
            @endforeach
        </div>
    </div>

    <!-- Institution Link -->
    @if($lead->institution)
    <div class="rounded-xl border border-border bg-card p-5">
        <h2 class="font-semibold mb-3">Linked Institution</h2>
        <div class="flex items-center justify-between">
            <div>
                <p class="font-medium">{{ $lead->institution->name }}</p>
                <p class="text-sm text-muted-foreground">{{ ucfirst($lead->institution->plan) }} plan · {{ $lead->institution->city ?? 'No city' }}</p>
            </div>
            <a href="{{ route('admin.institutions.show', $lead->institution) }}" wire:navigate
               class="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-xs font-medium hover:bg-muted transition-colors">
                View Institution
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
            </a>
        </div>
    </div>
    @endif

    <!-- Generate Credentials -->
    @if(!$lead->institution_id && $lead->status !== 'rejected')
    <div class="rounded-xl border border-border bg-card p-5">
        <h2 class="font-semibold mb-1">Generate Credentials</h2>
        <p class="text-sm text-muted-foreground mb-4">Creates an institution account and sends login credentials for this lead.</p>
        <button wire:click="approveAndGenerate" wire:loading.attr="disabled"
            class="inline-flex items-center gap-2 rounded-lg bg-success px-4 py-2 text-sm font-semibold text-white hover:bg-success/90 transition-colors disabled:opacity-50">
            <span wire:loading.remove wire:target="approveAndGenerate">Approve & Generate Credentials</span>
            <span wire:loading wire:target="approveAndGenerate">Generating...</span>
        </button>
    </div>
    @endif

    @if($showSuccess)
    <div class="rounded-xl border border-success/30 bg-success/5 p-5">
        <p class="font-semibold text-success mb-3">Credentials Generated Successfully!</p>
        <dl class="space-y-2 text-sm font-mono">
            <div class="flex gap-3"><dt class="text-muted-foreground w-24">Login URL</dt><dd>{{ route('login') }}</dd></div>
            <div class="flex gap-3"><dt class="text-muted-foreground w-24">Email</dt><dd>{{ $generatedEmail }}</dd></div>
            <div class="flex gap-3"><dt class="text-muted-foreground w-24">Password</dt><dd>{{ $generatedPassword }}</dd></div>
        </dl>
        <p class="text-xs text-muted-foreground mt-3">Copy and share these details securely with the institution.</p>
    </div>
    @endif
</div>
</div>
