<?php

use App\Models\Institution;
use Livewire\Attributes\Layout;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] class extends Component {
    public Institution $institution;
    public string $tab = 'overview';
    // Credential generation state
    public string $generatedEmail = '';
    public string $generatedPassword = '';
    public bool $showSuccess = false;


    public function mount(Institution $institution): void
    {
        $this->institution = $institution->load(['users','batches','tests']);
    }

    // Approve institution and generate admin credentials
    public function approveAndGenerate(): void
    {
        // Create a random password and email if not set
        $password = \Illuminate\Support\Str::password(8, true, true, false, false);
        $email = $this->institution->email ?: \Illuminate\Support\Str::slug($this->institution->name) . '@example.com';

        // Ensure a user admin exists
        \App\Models\User::create([
            'institution_id' => $this->institution->id,
            'name' => $this->institution->contact_person ?? $this->institution->name,
            'email' => $email,
            'password' => \Illuminate\Support\Facades\Hash::make($password),
            'role' => 'institution_admin',
        ]);

        // Mark institution as active
        $this->institution->update(['is_active' => true]);

        $this->generatedEmail = $email;
        $this->generatedPassword = $password;
        $this->showSuccess = true;
    }

    public function with(): array
    {
        return [
            'students' => $this->institution->students()->count(),
            'tests'    => $this->institution->tests()->count(),
            'attempts' => \App\Models\TestAttempt::whereHas('test', fn($q) => $q->where('institution_id', $this->institution->id))->count(),
        ];
    }
}; ?>

<div>
<div class="space-y-6">
    <div class="flex items-center gap-4">
        <a href="{{ route('admin.institutions') }}" wire:navigate class="h-9 w-9 rounded-lg border border-border flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div>
            <h1 class="font-display text-2xl font-bold">{{ $institution->name }}</h1>
            <div class="flex items-center gap-2 mt-0.5 text-sm text-muted-foreground">
                @if($institution->city)<span>{{ $institution->city }}</span>@endif
                @php $pc = ['trial'=>'bg-muted text-muted-foreground','starter'=>'bg-info/10 text-info','growth'=>'bg-success/10 text-success','enterprise'=>'bg-primary/10 text-primary'][$institution->plan ?? 'trial'] ?? 'bg-muted text-muted-foreground'; @endphp
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $pc }}">{{ ucfirst($institution->plan ?? 'trial') }}</span>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach([
            ['Faculty/Admin', $institution->users->count(), 'text-primary'],
            ['Students', $students, 'text-info'],
            ['Tests', $tests, 'text-success'],
            ['Attempts', $attempts, 'text-warning'],
        ] as [$l, $v, $c])
        <div class="rounded-xl border border-border bg-card p-4">
            <p class="text-sm text-muted-foreground">{{ $l }}</p>
            <p class="text-3xl font-bold {{ $c }} mt-1">{{ $v }}</p>
        </div>
        @endforeach
    </div>

    <div class="border-b border-border flex gap-0">
        @foreach(['overview'=>'Overview','batches'=>'Batches','tests'=>'Tests','users'=>'Users'] as $key => $label)
        <button wire:click="$set('tab','{{ $key }}')" class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors {{ $tab === $key ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if($tab === 'overview')
    <div class="rounded-xl border border-border bg-card p-5">
        <h3 class="font-semibold mb-4">Institution Details</h3>
        <dl class="space-y-3 text-sm grid sm:grid-cols-2 gap-x-8">
            @foreach([
                ['Name', $institution->name],
                ['Email', $institution->email ?? '—'],
                ['Phone', $institution->phone ?? '—'],
                ['City', $institution->city ?? '—'],
                ['Contact', $institution->contact_person ?? '—'],
                ['Plan', ucfirst($institution->plan ?? '—')],
                ['Subscription Ends', $institution->subscription_end?->format('d M Y') ?? '—'],
                ['Created', $institution->created_at->format('d M Y')],
            ] as [$label, $val])
            <div class="flex justify-between py-1.5 border-b border-border/50 last:border-0">
                <dt class="text-muted-foreground">{{ $label }}</dt>
                <dd class="font-medium">{{ $val }}</dd>
            </div>
            @endforeach
        </dl>
        @if(!$institution->users->count())
        <div class="mt-4">
            <button wire:click="approveAndGenerate" class="px-4 py-2 bg-success text-white rounded-lg hover:bg-success/90 transition-colors">Approve & Generate Credentials</button>
        </div>
        @endif
        @if($showSuccess)
        <div class="mt-4 p-4 rounded-lg bg-success/10 border border-success/30">
            <p class="font-semibold text-success mb-2">Credentials Generated!</p>
            <p class="text-sm font-mono"><strong>Login URL:</strong> {{ route('login') }}</p>
            <p class="text-sm font-mono"><strong>Email:</strong> {{ $generatedEmail }}</p>
            <p class="text-sm font-mono"><strong>Password:</strong> {{ $generatedPassword }}</p>
            <p class="text-xs text-muted-foreground mt-2">Copy and send these details securely to the institution.</p>
        </div>
        @endif
    </div>
    @endif

    @if($tab === 'batches')
    <div class="rounded-xl border border-border bg-card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="border-b border-border bg-muted/30">
                <tr>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Batch</th>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Type</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse($institution->batches as $batch)
                <tr class="hover:bg-muted/20"><td class="px-4 py-3 font-medium">{{ $batch->name }}</td><td class="px-4 py-3 text-muted-foreground">{{ strtoupper($batch->exam_type) }}</td></tr>
                @empty
                <tr><td colspan="2" class="px-4 py-8 text-center text-muted-foreground">No batches.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @endif

    @if($tab === 'tests')
    <div class="rounded-xl border border-border bg-card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="border-b border-border bg-muted/30">
                <tr>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Test</th>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Status</th>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Created</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse($institution->tests as $test)
                <tr class="hover:bg-muted/20">
                    <td class="px-4 py-3 font-medium">{{ $test->title }}</td>
                    <td class="px-4 py-3 text-muted-foreground">{{ ucfirst($test->status) }}</td>
                    <td class="px-4 py-3 text-muted-foreground text-xs">{{ $test->created_at->format('d M Y') }}</td>
                </tr>
                @empty
                <tr><td colspan="3" class="px-4 py-8 text-center text-muted-foreground">No tests.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @endif

    @if($tab === 'users')
    <div class="rounded-xl border border-border bg-card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="border-b border-border bg-muted/30">
                <tr>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">User</th>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Role</th>
                    <th class="text-left px-4 py-3 font-semibold text-muted-foreground">Email</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse($institution->users as $user)
                <tr class="hover:bg-muted/20">
                    <td class="px-4 py-3 font-medium">{{ $user->name }}</td>
                    <td class="px-4 py-3 text-muted-foreground">{{ ucfirst(str_replace('_',' ',$user->role)) }}</td>
                    <td class="px-4 py-3 text-muted-foreground">{{ $user->email }}</td>
                </tr>
                @empty
                <tr><td colspan="3" class="px-4 py-8 text-center text-muted-foreground">No users.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @endif
</div>
</div>
