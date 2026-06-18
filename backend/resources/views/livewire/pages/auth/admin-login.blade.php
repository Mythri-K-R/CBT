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

        if (auth()->user()->role !== 'super_admin') {
            auth()->logout();
            Session::invalidate();
            Session::regenerateToken();
            $this->addError('form.email', 'Access denied. This portal is for system administrators only.');
            return;
        }

        Session::regenerate();

        $this->redirectIntended(default: route('admin.dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="min-h-screen grid place-items-center bg-background p-4">
    <div class="absolute top-4 right-4">
        <!-- Theme Toggle placeholder -->
    </div>
    <div class="w-full max-w-md">
        <div class="mb-8 text-center">
            <div class="flex justify-center mb-6">
                <!-- Brand placeholder -->
                <div class="flex items-center gap-2 text-xl font-bold font-display text-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-primary"><path d="M12 2v20"/><path d="m17 5-5-3-5 3"/><path d="m17 19-5 3-5-3"/><path d="M2 12h20"/><path d="m5 17-3-5 3-5"/><path d="m19 17 3-5-3-5"/></svg>
                    Examsphere
                </div>
            </div>
            <h2 class="font-display text-2xl font-bold tracking-tight text-primary flex items-center justify-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg> 
                System Administrator
            </h2>
            <p class="mt-2 text-sm text-muted-foreground">Authorized personnel only.</p>
        </div>

        <form wire:submit="login" class="space-y-4">
            <div>
                <x-ui.label for="email">Admin Email / Username</x-ui.label>
                <div class="relative mt-1.5">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <x-ui.input wire:model="form.email" id="email" class="pl-9" type="text" name="email" required autofocus autocomplete="username" />
                </div>
                <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
            </div>

            <div>
                <x-ui.label for="password">Password</x-ui.label>
                <div class="relative mt-1.5">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <x-ui.input wire:model="form.password" id="password" type="password" class="pl-9 pr-9" name="password" required autocomplete="current-password" />
                </div>
                <x-input-error :messages="$errors->get('form.password')" class="mt-2" />
            </div>

            <x-ui.button type="submit" class="w-full" size="lg">Secure Login</x-ui.button>
        </form>
    </div>
</div>
