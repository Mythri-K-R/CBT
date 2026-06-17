<?php

use App\Models\User;
use App\Models\Institution;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $institution_name = '';
    public string $type = 'coaching_center';
    public string $contact_person = '';
    public string $email = '';
    public string $phone = '';
    public string $city = '';
    public string $state = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Handle an incoming registration request.
     */
    public function register(): void
    {
        $validated = $this->validate([
            'institution_name' => ['required', 'string', 'max:255'],
            'type'             => ['required', 'in:pu_college,degree_college,neet_academy,jee_academy,kcet_institute,coaching_center,school,other'],
            'contact_person'   => ['required', 'string', 'max:255'],
            'email'            => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.Institution::class.',email'],
            'phone'            => ['required', 'string', 'max:20'],
            'city'             => ['nullable', 'string', 'max:100'],
            'state'            => ['nullable', 'string', 'max:100'],
            'password'         => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $trialConfig = config('examsphere.plans.trial');
        $trialDays   = $trialConfig['duration_days'] ?? 30;

        $code = strtoupper(Str::slug($validated['institution_name'], ''));
        $code = substr($code, 0, 6) . rand(100, 999);

        // Create Institution
        $institution = Institution::create([
            'code'               => $code,
            'name'               => $validated['institution_name'],
            'slug'               => Str::slug($validated['institution_name']) . '-' . Str::random(4),
            'type'               => $validated['type'],
            'contact_person'     => $validated['contact_person'],
            'email'              => $validated['email'],
            'phone'              => $validated['phone'],
            'city'               => $validated['city'] ?? null,
            'state'              => $validated['state'] ?? null,
            'plan'               => 'trial',
            'student_limit'      => $trialConfig['student_limit'] ?? 50,
            'faculty_limit'      => $trialConfig['faculty_limit'] ?? 3,
            'question_limit'     => $trialConfig['question_limit'] ?? 5000,
            'subscription_start' => today(),
            'subscription_end'   => today()->addDays($trialDays),
            'is_active'          => true,
        ]);

        // Create Admin User
        $user = User::create([
            'institution_id'        => $institution->id,
            'role'                  => 'institution_admin',
            'name'                  => $validated['contact_person'],
            'email'                 => $validated['email'],
            'username'              => strtolower(str_replace(' ', '.', $validated['contact_person'])) . rand(10, 99),
            'password'              => Hash::make($validated['password']),
            'force_password_change' => false,
            'is_active'             => true,
        ]);

        event(new Registered($user));

        Auth::login($user);

        $this->redirect(route('institution.dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="min-h-screen grid lg:grid-cols-2 bg-background">
    <!-- Left panel -->
    <div class="hidden lg:flex relative flex-col justify-between gradient-brand text-primary-foreground p-10 overflow-hidden">
        <div class="absolute inset-0 opacity-20" style="background-image: radial-gradient(circle at 1px 1px, white 1px, transparent 0); background-size: 32px 32px"></div>
        <div class="relative">
            <a href="/" class="inline-flex items-center gap-2 text-xl font-bold font-display text-primary-foreground">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-primary-foreground"><path d="M12 2v20"/><path d="m17 5-5-3-5 3"/><path d="m17 19-5 3-5-3"/><path d="M2 12h20"/><path d="m5 17-3-5 3-5"/><path d="m19 17 3-5-3-5"/></svg>
                ExamSphere
            </a>
        </div>
        <div class="relative space-y-8">
            <div>
                <h1 class="font-display text-4xl font-bold leading-tight">Empower your institution with advanced CBT capabilities</h1>
                <p class="mt-3 text-primary-foreground/90 max-w-md">Start your 30-day free trial today. Full access to features, analytics, and question banks.</p>
            </div>
            <div class="grid grid-cols-3 gap-3 max-w-md">
                <div class="rounded-xl bg-white/10 backdrop-blur p-3 border border-white/15">
                    <div class="font-display text-2xl font-bold">126+</div>
                    <div class="text-xs opacity-80">Institutions</div>
                </div>
                <div class="rounded-xl bg-white/10 backdrop-blur p-3 border border-white/15">
                    <div class="font-display text-2xl font-bold">48K+</div>
                    <div class="text-xs opacity-80">Students</div>
                </div>
                <div class="rounded-xl bg-white/10 backdrop-blur p-3 border border-white/15">
                    <div class="font-display text-2xl font-bold">99.9%</div>
                    <div class="text-xs opacity-80">Uptime</div>
                </div>
            </div>
        </div>
        <div class="relative text-xs opacity-80">© {{ date('Y') }} ExamSphere Technologies Pvt Ltd</div>
    </div>

    <!-- Right panel -->
    <div class="flex flex-col h-screen overflow-y-auto">
        <div class="flex items-center justify-between p-4 lg:p-6 shrink-0">
            <a href="/" class="lg:hidden items-center gap-2 text-xl font-bold font-display text-primary flex">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-primary"><path d="M12 2v20"/><path d="m17 5-5-3-5 3"/><path d="m17 19-5 3-5-3"/><path d="M2 12h20"/><path d="m5 17-3-5 3-5"/><path d="m19 17 3-5-3-5"/></svg>
                ExamSphere
            </a>
            <div class="ml-auto">
                <!-- Theme Toggle Placeholder -->
            </div>
        </div>
        <div class="flex-1 grid place-items-center p-4 sm:p-6">
            <div class="w-full max-w-md">
                <div class="mb-6">
                    <h2 class="font-display text-2xl sm:text-3xl font-bold tracking-tight">Create an Account</h2>
                    <p class="mt-1.5 text-sm text-muted-foreground">Register your institution to get started with ExamSphere.</p>
                </div>

                <form wire:submit="register" class="space-y-4">
                    <div>
                        <x-ui.label for="institution_name">Institution Name</x-ui.label>
                        <div class="relative mt-1.5">
                            <x-ui.input wire:model="institution_name" id="institution_name" type="text" required autofocus />
                        </div>
                        <x-input-error :messages="$errors->get('institution_name')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-ui.label for="contact_person">Admin Contact Name</x-ui.label>
                            <div class="relative mt-1.5">
                                <x-ui.input wire:model="contact_person" id="contact_person" type="text" required />
                            </div>
                            <x-input-error :messages="$errors->get('contact_person')" class="mt-2" />
                        </div>
                        <div>
                            <x-ui.label for="phone">Phone Number</x-ui.label>
                            <div class="relative mt-1.5">
                                <x-ui.input wire:model="phone" id="phone" type="text" required />
                            </div>
                            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-ui.label for="email">Admin Email Address</x-ui.label>
                        <div class="relative mt-1.5">
                            <x-ui.input wire:model="email" id="email" type="email" required />
                        </div>
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>
                    
                    <div>
                        <x-ui.label for="type">Institution Type</x-ui.label>
                        <div class="relative mt-1.5">
                            <select wire:model="type" id="type" required class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">
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
                        <x-input-error :messages="$errors->get('type')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-ui.label for="city">City (Optional)</x-ui.label>
                            <div class="relative mt-1.5">
                                <x-ui.input wire:model="city" id="city" type="text" />
                            </div>
                            <x-input-error :messages="$errors->get('city')" class="mt-2" />
                        </div>
                        <div>
                            <x-ui.label for="state">State (Optional)</x-ui.label>
                            <div class="relative mt-1.5">
                                <x-ui.input wire:model="state" id="state" type="text" />
                            </div>
                            <x-input-error :messages="$errors->get('state')" class="mt-2" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-ui.label for="password">Password</x-ui.label>
                            <div class="relative mt-1.5">
                                <x-ui.input wire:model="password" id="password" type="password" required />
                            </div>
                            <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        </div>
                        <div>
                            <x-ui.label for="password_confirmation">Confirm Password</x-ui.label>
                            <div class="relative mt-1.5">
                                <x-ui.input wire:model="password_confirmation" id="password_confirmation" type="password" required />
                            </div>
                            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                        </div>
                    </div>

                    <div class="pt-2">
                        <x-ui.button type="submit" class="w-full" size="lg">Create Account</x-ui.button>
                    </div>

                    <div class="mt-4 text-center text-sm">
                        <span class="text-muted-foreground">Already registered?</span>
                        <a href="{{ route('login') }}" class="text-primary hover:underline font-medium" wire:navigate>
                            Sign in instead
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
