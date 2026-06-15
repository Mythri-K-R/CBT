<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        $user = auth()->user();
        if (in_array($user->role, ['institution_admin', 'faculty', 'institution'])) {
            $this->redirectIntended(default: route('institution.dashboard', absolute: false), navigate: true);
        } else {
            $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
        }
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
                <h1 class="font-display text-4xl font-bold leading-tight">India's Most Realistic CBT Examination Platform</h1>
                <p class="mt-3 text-primary-foreground/90 max-w-md">The CBT platform powering 120+ coaching institutes, PU colleges and competitive-exam centres across India.</p>
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
        <div class="relative text-xs opacity-80">© 2026 ExamSphere Technologies Pvt Ltd</div>
    </div>

    <!-- Right panel -->
    <div class="flex flex-col">
        <div class="flex items-center justify-between p-4 lg:p-6">
            <a href="/" class="lg:hidden items-center gap-2 text-xl font-bold font-display text-primary flex">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-primary"><path d="M12 2v20"/><path d="m17 5-5-3-5 3"/><path d="m17 19-5 3-5-3"/><path d="M2 12h20"/><path d="m5 17-3-5 3-5"/><path d="m19 17 3-5-3-5"/></svg>
                ExamSphere
            </a>
            <div class="ml-auto">
                <!-- Theme Toggle -->
            </div>
        </div>
        <div class="flex-1 grid place-items-center p-4 sm:p-6">
            <div class="w-full max-w-md">
                <div class="mb-6">
                    <h2 class="font-display text-2xl sm:text-3xl font-bold tracking-tight">Sign in to your portal</h2>
                    <p class="mt-1.5 text-sm text-muted-foreground">Enter your credentials to continue.</p>
                </div>

                <x-auth-session-status class="mb-4" :status="session('status')" />

                <form wire:submit="login" class="space-y-4">
                    <div>
                        <x-ui.label for="email">Email Address</x-ui.label>
                        <div class="relative mt-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <x-ui.input wire:model="form.email" id="email" class="pl-9" type="text" name="email" required autofocus autocomplete="username" />
                        </div>
                        <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <x-ui.label for="password">Password</x-ui.label>
                            @if (Route::has('password.request'))
                                <a class="text-xs text-primary hover:underline" href="{{ route('password.request') }}" wire:navigate>
                                    {{ __('Forgot Password?') }}
                                </a>
                            @endif
                        </div>
                        <div class="relative mt-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <x-ui.input wire:model="form.password" id="password" type="password" class="pl-9" name="password" required autocomplete="current-password" />
                        </div>
                        <x-input-error :messages="$errors->get('form.password')" class="mt-2" />
                    </div>

                    <div class="block">
                        <label for="remember" class="inline-flex items-center">
                            <input wire:model="form.remember" id="remember" type="checkbox" class="rounded border-input bg-background text-primary shadow-sm focus:ring-primary" name="remember">
                            <span class="ms-2 text-sm text-muted-foreground">{{ __('Remember me') }}</span>
                        </label>
                    </div>

                    <x-ui.button type="submit" class="w-full" size="lg">Sign in</x-ui.button>
                </form>

            </div>
        </div>
    </div>
</div>
