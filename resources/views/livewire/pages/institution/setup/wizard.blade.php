<?php

use App\Models\Institution;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component {
    public int $step = 1;
    public int $totalSteps = 3;

    // Step 1 — Change password
    public string $newPassword = '';
    public string $newPasswordConfirmation = '';

    // Step 2 — Institution profile
    public string $instType = '';
    public string $instCity = '';
    public string $instState = '';
    public string $instAddress = '';
    public string $instWebsite = '';

    public function mount(): void
    {
        $user = auth()->user();

        // Faculty only need to change password (2 steps instead of 3)
        if ($user->role === 'faculty') {
            $this->totalSteps = 2;
        }

        // Pre-fill from existing institution data
        $inst = $user->institution;
        if ($inst) {
            $this->instType    = $inst->type ?? '';
            $this->instCity    = $inst->city ?? '';
            $this->instState   = $inst->state ?? '';
            $this->instAddress = $inst->address ?? '';
            $this->instWebsite = $inst->website ?? '';
        }
    }

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validate([
                'newPassword'             => ['required', Password::min(8)],
                'newPasswordConfirmation' => 'required|same:newPassword',
            ], [
                'newPasswordConfirmation.same' => 'Passwords do not match.',
            ]);

            auth()->user()->update(['password' => Hash::make($this->newPassword)]);
        }

        if ($this->step === 2 && auth()->user()->role === 'institution_admin') {
            $this->validate([
                'instType' => 'required|in:pu_college,degree_college,neet_academy,jee_academy,kcet_institute,coaching_center,school,other',
            ]);

            $inst = auth()->user()->institution;
            if ($inst) {
                $inst->update([
                    'type'    => $this->instType,
                    'city'    => $this->instCity ?: $inst->city,
                    'state'   => $this->instState ?: null,
                    'address' => $this->instAddress ?: null,
                    'website' => $this->instWebsite ?: null,
                ]);
            }
        }

        $this->step++;

        if ($this->step > $this->totalSteps) {
            auth()->user()->update(['force_password_change' => false]);
            $this->redirectRoute('institution.dashboard', navigate: true);
        }
    }

    public function skip(): void
    {
        $this->step++;
        if ($this->step > $this->totalSteps) {
            auth()->user()->update(['force_password_change' => false]);
            $this->redirectRoute('institution.dashboard', navigate: true);
        }
    }
}; ?>

<div class="min-h-screen bg-background flex flex-col items-center justify-center p-4">
    <div class="w-full max-w-lg">

        <!-- Logo -->
        <div class="flex items-center justify-center gap-2 mb-8 font-display text-xl font-bold text-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20"/><path d="m17 5-5-3-5 3"/><path d="m17 19-5 3-5-3"/><path d="M2 12h20"/><path d="m5 17-3-5 3-5"/><path d="m19 17 3-5-3-5"/></svg>
            ExamSphere
        </div>

        <!-- Progress -->
        <div class="mb-8">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-foreground">Setup</span>
                <span class="text-xs text-muted-foreground">Step {{ $step }} of {{ $totalSteps }}</span>
            </div>
            <div class="h-1.5 w-full rounded-full bg-muted overflow-hidden">
                <div class="h-full rounded-full bg-primary transition-all duration-500"
                     style="width: {{ round(($step / $totalSteps) * 100) }}%"></div>
            </div>
            <div class="flex mt-3 gap-2">
                @php $steps = auth()->user()->role === 'faculty'
                    ? ['Set Password', 'All Set!']
                    : ['Set Password', 'Institute Profile', 'All Set!']; @endphp
                @foreach($steps as $i => $label)
                <div class="flex items-center gap-1.5 text-xs {{ ($i + 1) <= $step ? 'text-primary font-medium' : 'text-muted-foreground' }}">
                    <div class="h-4 w-4 rounded-full flex items-center justify-center {{ ($i + 1) < $step ? 'bg-primary text-primary-foreground' : (($i + 1) === $step ? 'ring-2 ring-primary bg-background' : 'bg-muted') }}">
                        @if(($i + 1) < $step)
                        <svg width="8" height="8" viewBox="0 0 12 12" fill="none"><path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        @else
                        <span class="text-[9px] font-bold">{{ $i + 1 }}</span>
                        @endif
                    </div>
                    {{ $label }}
                </div>
                @if(!$loop->last)<div class="h-px flex-1 bg-border self-center"></div>@endif
                @endforeach
            </div>
        </div>

        <div class="rounded-2xl border border-border bg-card shadow-xl p-8">

            {{-- Step 1: Change Password --}}
            @if($step === 1)
            <div>
                <div class="mb-6">
                    <h2 class="font-display text-2xl font-bold mb-1">Welcome to ExamSphere!</h2>
                    <p class="text-sm text-muted-foreground">Your account has been set up. First, create a secure password for yourself.</p>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1.5">New Password <span class="text-destructive">*</span></label>
                        <input wire:model="newPassword" type="password" autocomplete="new-password"
                               class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30 @error('newPassword') border-destructive @enderror"
                               placeholder="Minimum 8 characters">
                        @error('newPassword')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Confirm Password <span class="text-destructive">*</span></label>
                        <input wire:model="newPasswordConfirmation" type="password" autocomplete="new-password"
                               class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30 @error('newPasswordConfirmation') border-destructive @enderror"
                               placeholder="Re-enter password">
                        @error('newPasswordConfirmation')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
                    </div>
                </div>

                <button wire:click="nextStep" class="mt-6 w-full rounded-lg bg-primary py-2.5 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">
                    Set Password & Continue
                </button>
            </div>

            {{-- Step 2: Institute Profile (institution_admin only) --}}
            @elseif($step === 2 && auth()->user()->role === 'institution_admin')
            <div>
                <div class="mb-6">
                    <h2 class="font-display text-2xl font-bold mb-1">Complete your profile</h2>
                    <p class="text-sm text-muted-foreground">Help us set up your institute correctly. You can update these anytime from Settings.</p>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1.5">Institute Type <span class="text-destructive">*</span></label>
                        <select wire:model="instType" class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30 @error('instType') border-destructive @enderror">
                            <option value="">Select type…</option>
                            <option value="neet_academy">NEET Academy</option>
                            <option value="jee_academy">JEE Academy</option>
                            <option value="kcet_institute">KCET Institute</option>
                            <option value="coaching_center">Coaching Center</option>
                            <option value="pu_college">PU College</option>
                            <option value="degree_college">Degree College</option>
                            <option value="school">School</option>
                            <option value="other">Other</option>
                        </select>
                        @error('instType')<p class="text-xs text-destructive mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium mb-1.5">City</label>
                            <input wire:model="instCity" type="text" placeholder="Bangalore"
                                   class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1.5">State</label>
                            <input wire:model="instState" type="text" placeholder="Karnataka"
                                   class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1.5">Address</label>
                        <textarea wire:model="instAddress" rows="2" placeholder="Street, area, landmark…"
                                  class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30 resize-none"></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1.5">Website <span class="text-xs text-muted-foreground">(optional)</span></label>
                        <input wire:model="instWebsite" type="url" placeholder="https://yourinstitute.com"
                               class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary/30">
                    </div>
                </div>

                <div class="flex gap-3 mt-6">
                    <button wire:click="nextStep" class="flex-1 rounded-lg bg-primary py-2.5 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">
                        Save & Continue
                    </button>
                    <button wire:click="skip" class="rounded-lg border border-border px-4 py-2.5 text-sm font-medium hover:bg-muted transition-colors">
                        Skip
                    </button>
                </div>
            </div>

            {{-- Final Step: All Set --}}
            @else
            <div class="text-center py-6">
                <div class="h-20 w-20 rounded-full bg-success/15 flex items-center justify-center mx-auto mb-6">
                    <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-success"><path d="M20 6 9 17l-5-5"/></svg>
                </div>
                <h2 class="font-display text-2xl font-bold mb-2">You're all set!</h2>
                <p class="text-muted-foreground text-sm mb-8 max-w-xs mx-auto">
                    Your portal is ready. Start by adding your first batch and enrolling students.
                </p>
                <button wire:click="nextStep" class="inline-flex items-center gap-2 rounded-lg bg-primary px-8 py-3 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">
                    Go to Dashboard
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </button>
            </div>
            @endif

        </div>

        <p class="text-center text-xs text-muted-foreground mt-6">
            Having trouble?
            <a href="mailto:hello@examsphere.in" class="text-primary hover:underline">Contact support</a>
        </p>
    </div>
</div>
