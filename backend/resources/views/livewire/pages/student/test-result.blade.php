<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\TestLink;
use App\Models\TestAttempt;

new #[Layout('layouts.exam')] class extends Component {
    public string $slug;
    public ?TestAttempt $attempt = null;
    public ?TestLink $testLink = null;

    public function mount(string $slug): void
    {
        $this->slug = $slug;
        $this->testLink = TestLink::with('test.institution')->where('slug', $slug)->first();

        if (!$this->testLink) {
            abort(404);
        }

        $attemptId = session("last_attempt_{$slug}");
        if ($attemptId) {
            $this->attempt = TestAttempt::with(['student', 'test'])->find($attemptId);
        }
    }
}; ?>

@push('title')<title>Test Submitted — {{ $testLink->test->title }}</title>@endpush
@push('styles')
<style>
    body { font-family: Arial, Helvetica, sans-serif; margin: 0; background: #f0f2f5; }
    .nta-header { background: #1a3c5e; height: 52px; display: flex; align-items: center; padding: 0 24px; justify-content: space-between; }
</style>
@endpush

<div>
<div class="nta-header">
    <div class="flex items-center gap-3">
        @if($testLink->test->institution?->logo_path)
        <img src="{{ asset('storage/'.$testLink->test->institution->logo_path) }}" class="h-8 w-auto" alt="">
        @endif
        <span class="text-white font-bold text-base">{{ $testLink->test->institution?->name ?? 'Examsphere' }}</span>
    </div>
    <span class="text-white/60 text-sm font-semibold">CBT Examination Portal</span>
</div>

<div class="min-h-[calc(100vh-52px)] flex items-center justify-center p-4">
    <div class="w-full max-w-md text-center">

        <!-- Success Icon -->
        <div class="mx-auto mb-6 h-20 w-20 rounded-full flex items-center justify-center" style="background:#e8f5e9;">
            <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#2E8B57" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
        </div>

        <h1 class="text-2xl font-bold text-gray-800 mb-2">Test Submitted Successfully</h1>
        <p class="text-gray-500 text-sm mb-6">Your responses have been recorded and submitted.</p>

        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 text-left space-y-3 mb-6">
            @if($attempt?->student)
            <div class="flex justify-between text-sm">
                <span class="text-gray-500">Student</span>
                <span class="font-semibold text-gray-800">{{ $attempt->student->name }}</span>
            </div>
            <div class="flex justify-between text-sm border-t border-gray-100 pt-3">
                <span class="text-gray-500">Roll Number</span>
                <span class="font-mono text-gray-800">{{ $attempt->student->roll_number }}</span>
            </div>
            @endif
            <div class="flex justify-between text-sm border-t border-gray-100 pt-3">
                <span class="text-gray-500">Test</span>
                <span class="font-semibold text-gray-800">{{ $testLink->test->title }}</span>
            </div>
            @if($attempt?->submitted_at)
            <div class="flex justify-between text-sm border-t border-gray-100 pt-3">
                <span class="text-gray-500">Submitted At</span>
                <span class="text-gray-800">{{ $attempt->submitted_at->format('d M Y, h:i A') }}</span>
            </div>
            @endif
        </div>

        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-800 mb-6">
            Your results will be reviewed and shared by your institution. You may close this window.
        </div>

        <p class="text-xs text-gray-400">Powered by <strong>Examsphere</strong> · Mitra Softwares</p>
    </div>
</div>
</div>
