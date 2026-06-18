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

    public function resetPassword(int $userId): void
    {
        $user = \App\Models\User::find($userId);
        if (!$user || $user->institution_id !== $this->institution->id) return;

        $password = \Illuminate\Support\Str::password(8, true, true, false, false);
        $user->update(['password' => \Illuminate\Support\Facades\Hash::make($password)]);

        $this->generatedEmail    = $user->email;
        $this->generatedPassword = $password;
        $this->showSuccess       = true;
        $this->tab               = 'overview';
        session()->flash('resetDone', true);
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
            <div class="flex items-center justify-between mb-3">
                <p class="font-semibold text-success">{{ session('resetDone') ? 'Password Reset!' : 'Credentials Generated!' }}</p>
                <button onclick="downloadCredentialsPDF('{{ $institution->name }}','{{ route('login') }}','{{ $generatedEmail }}','{{ $generatedPassword }}')"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-primary text-primary-foreground px-3 py-1.5 text-xs font-semibold hover:bg-primary/90 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                    Download PDF
                </button>
            </div>
            <p class="text-sm font-mono"><strong>Login URL:</strong> {{ route('login') }}</p>
            <p class="text-sm font-mono"><strong>Email:</strong> {{ $generatedEmail }}</p>
            <p class="text-sm font-mono"><strong>Password:</strong> {{ $generatedPassword }}</p>
            <p class="text-xs text-muted-foreground mt-2">Download and send the PDF securely to the institution.</p>
        </div>
        <script>
        function downloadCredentialsPDF(institution, url, email, password) {
            const html = `<!DOCTYPE html>
<html><head><meta charset="utf-8">
<title>Login Credentials — ${institution}</title>
<style>
  body { font-family: Arial, sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
  .card { background: white; border-radius: 12px; padding: 40px 48px; max-width: 480px; width: 100%; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
  .logo { font-size: 22px; font-weight: 900; color: #1a3c5e; margin-bottom: 4px; }
  .sub { font-size: 11px; color: #888; margin-bottom: 32px; }
  h2 { font-size: 18px; color: #111; margin: 0 0 6px; }
  .subtitle { font-size: 13px; color: #666; margin-bottom: 28px; }
  .field { margin-bottom: 18px; }
  .label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 4px; }
  .value { font-size: 15px; font-family: monospace; color: #1a3c5e; background: #f0f4f8; padding: 10px 14px; border-radius: 6px; border-left: 3px solid #1a3c5e; }
  .footer { margin-top: 32px; padding-top: 20px; border-top: 1px solid #eee; font-size: 11px; color: #aaa; }
  @media print { body { background: white; } .card { box-shadow: none; } }
</style></head>
<body><div class="card">
  <div class="logo">Examsphere</div>
  <div class="sub">by Mitra Softwares</div>
  <h2>Login Credentials</h2>
  <p class="subtitle">Institution: <strong>${institution}</strong></p>
  <div class="field"><div class="label">Login URL</div><div class="value">${url}</div></div>
  <div class="field"><div class="label">Email / Username</div><div class="value">${email}</div></div>
  <div class="field"><div class="label">Password</div><div class="value">${password}</div></div>
  <div class="footer">Keep these credentials confidential. To reset the password, contact Examsphere support.<br>Generated on ${new Date().toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'})}</div>
</div>
<script>window.onload = () => { window.print(); }<\/script>
</body></html>`;
            const w = window.open('', '_blank');
            w.document.write(html);
            w.document.close();
        }
        </script>
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
                    <th class="text-right px-4 py-3 font-semibold text-muted-foreground">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse($institution->users as $user)
                <tr class="hover:bg-muted/20">
                    <td class="px-4 py-3 font-medium">{{ $user->name }}</td>
                    <td class="px-4 py-3 text-muted-foreground">{{ ucfirst(str_replace('_',' ',$user->role)) }}</td>
                    <td class="px-4 py-3 text-muted-foreground">{{ $user->email }}</td>
                    <td class="px-4 py-3 text-right">
                        <button wire:click="resetPassword({{ $user->id }})"
                                wire:confirm="Reset password for {{ $user->name }}? A new password will be generated."
                                class="text-xs font-medium text-warning hover:underline">
                            Reset Password
                        </button>
                    </td>
                </tr>
                @empty
                <tr><td colspan="4" class="px-4 py-8 text-center text-muted-foreground">No users.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @endif
</div>
</div>
