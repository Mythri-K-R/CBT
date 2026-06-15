<?php

use App\Models\Lead;
use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';
    public string $phone = '';
    public string $institute = '';
    public string $city = '';
    public string $students = '';
    public array $exams = [];

    public bool $isSubmitted = false;

    public function submit(): void
    {
        $this->validate([
            'name' => 'required|string|max:150',
            'phone' => 'required|string|max:20',
            'institute' => 'required|string|max:150',
            'city' => 'nullable|string|max:100',
        ]);

        Lead::create([
            'name' => $this->name,
            'phone' => $this->phone,
            'institution_name' => $this->institute,
            'city' => $this->city,
            'student_count' => $this->students ?: null,
            'exam_types' => !empty($this->exams) ? implode(', ', $this->exams) : null,
            'status' => 'new',
            'notes' => 'Submitted via landing page demo form.',
        ]);

        $this->isSubmitted = true;
    }
}; ?>

<div>
    @if($isSubmitted)
        <div class="rounded-xl border border-success/20 bg-success/10 p-8 text-center flex flex-col items-center justify-center h-full min-h-[300px]">
            <div class="h-12 w-12 rounded-full bg-success/20 text-success flex items-center justify-center mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </div>
            <h3 class="font-display text-xl font-bold mb-2">Request Received!</h3>
            <p class="text-muted-foreground text-sm max-w-xs mx-auto">Thank you for your interest. Our team will contact you at {{ $phone }} within 2 business hours.</p>
        </div>
    @else
        <form wire:submit="submit" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5">Your Name <span class="text-destructive">*</span></label>
                    <input wire:model="name" type="text" required class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-ring placeholder:text-muted-foreground @error('name') border-destructive @enderror" placeholder="Dr. Rahul Sharma">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Phone <span class="text-destructive">*</span></label>
                    <input wire:model="phone" type="tel" required class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-ring placeholder:text-muted-foreground @error('phone') border-destructive @enderror" placeholder="+91 98765 43210">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Institute Name <span class="text-destructive">*</span></label>
                <input wire:model="institute" type="text" required class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-ring placeholder:text-muted-foreground @error('institute') border-destructive @enderror" placeholder="Pinnacle Academy">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5">City</label>
                    <input wire:model="city" type="text" class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-ring placeholder:text-muted-foreground" placeholder="Bangalore">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5">Students</label>
                    <select wire:model="students" class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-ring">
                        <option value="">Select range</option>
                        <option value="under_100">Under 100</option>
                        <option value="100_500">100–500</option>
                        <option value="500_1000">500–1000</option>
                        <option value="1000_plus">1000+</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1.5">Exam Types</label>
                <div class="flex gap-4">
                    @foreach(['NEET', 'JEE', 'KCET'] as $exam)
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input wire:model="exams" type="checkbox" value="{{ $exam }}" class="rounded border-input text-primary h-4 w-4">
                        {{ $exam }}
                    </label>
                    @endforeach
                </div>
            </div>
            <button type="submit" class="w-full rounded-lg bg-primary py-3 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors mt-2">
                Send Demo Request
            </button>
            <p class="text-xs text-center text-muted-foreground">We respond within 2 business hours.</p>
        </form>
    @endif
</div>
