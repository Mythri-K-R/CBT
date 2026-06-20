<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\TestLink;
use App\Models\Student;

new #[Layout('layouts.exam')] class extends Component {
    public string $slug = '';
    public string $rollNumber = '';
    public ?string $errorMessage = null;

    public ?TestLink $testLink = null;

    public function mount(string $slug): void
    {
        $this->slug = $slug;
        $this->testLink = TestLink::with('test.institution')->where('slug', $slug)->first();

        if (!$this->testLink || !$this->testLink->test) {
            abort(404, 'Test not found.');
        }

        // If already in an active exam, redirect straight there
        $attemptId = session("active_attempt_{$slug}");
        if ($attemptId) {
            $attempt = \App\Models\TestAttempt::find($attemptId);
            if ($attempt && $attempt->isInProgress() && !$attempt->isExpired()) {
                $this->redirect(route('student.test.attempt', ['slug' => $slug]), navigate: false);
                return;
            }
            session()->forget("active_attempt_{$slug}");
        }
    }

    public function verify(): void
    {
        $this->validate(['rollNumber' => 'required|string|max:50']);
        $this->errorMessage = null;

        $test = $this->testLink->test;

        if (!$this->testLink->isValid()) {
            $this->errorMessage = 'This test link is no longer active.';
            return;
        }

        // Block only fully-ended / inactive tests; allow scheduled (not yet started) tests
        if ($test->status === 'inactive') {
            $this->errorMessage = 'This test is no longer available.';
            return;
        }

        if ($test->scheduled_end && now()->gt($test->scheduled_end)) {
            $this->errorMessage = 'This test has ended.';
            return;
        }

        $student = Student::where('roll_number', trim($this->rollNumber))
            ->where('institution_id', $test->institution_id)
            ->first();

        if (!$student) {
            $this->errorMessage = 'Invalid Roll Number. Please check and try again.';
            return;
        }

        // Batch check: if test has no batches, it is open to all institution students
        $hasBatches = $test->batches()->exists();
        if ($hasBatches) {
            $isAssigned = $test->batches()
                ->whereHas('students', fn($q) => $q->where('student_id', $student->id))
                ->exists();

            if (!$isAssigned) {
                $this->errorMessage = 'You are not assigned to this test.';
                return;
            }
        }

        // Check for existing submitted attempt
        $existingAttempt = \App\Models\TestAttempt::where('test_id', $test->id)
            ->where('student_id', $student->id)
            ->first();

        if ($existingAttempt) {
            if (in_array($existingAttempt->status, ['submitted', 'completed'])) {
                $this->errorMessage = 'You have already submitted this test.';
                return;
            }
            // Resume in-progress attempt
            if ($existingAttempt->isExpired()) {
                $this->errorMessage = 'Your test session has expired.';
                return;
            }
            session(["active_attempt_{$this->slug}" => $existingAttempt->id]);
            $this->redirect(route('student.test.attempt', ['slug' => $this->slug]), navigate: false);
            return;
        }

        // Store verified student in session and go to instructions
        session(["verified_student_{$this->slug}" => $student->id]);
        $this->redirect(route('student.test.instructions', ['slug' => $this->slug]), navigate: false);
    }
}; ?>

@push('title')<title>{{ $testLink->test->title ?? 'ExamSphere' }} — Test Portal</title>@endpush
@push('styles')
<style>
    body { font-family: Arial, Helvetica, sans-serif; margin: 0; background: #f0f2f5; }
    .nta-header { background: #1a3c5e; height: 52px; display: flex; align-items: center; padding: 0 24px; justify-content: space-between; }
    .btn-primary { background: #1a3c5e; color: white; border: none; padding: 10px 28px; font-weight: bold; font-size: 14px; cursor: pointer; border-radius: 4px; }
    .btn-primary:hover { background: #15304e; }
</style>
@endpush

<div><!-- Mobile Warning -->
<div class="md:hidden fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-6">
    <div class="bg-white rounded-xl p-8 text-center max-w-sm">
        <div style="font-size:48px;">💻</div>
        <h2 class="text-xl font-bold mt-3 mb-2">Desktop Required</h2>
        <p class="text-gray-600 text-sm">Please open this exam on a laptop or desktop computer for the best experience. Mobile devices are not supported for CBT exams.</p>
    </div>
</div>

<!-- Header -->
<div class="nta-header">
    <div class="flex items-center gap-3">
        @if($testLink->test->institution?->logo_path)
        <img src="{{ asset('storage/'.$testLink->test->institution->logo_path) }}" class="h-8 w-auto" alt="">
        @endif
        <span class="text-white font-bold text-base">{{ $testLink->test->institution?->name ?? 'ExamSphere' }}</span>
    </div>
    <span class="text-white/60 text-sm font-semibold">CBT Examination Portal</span>
</div>

<div class="min-h-[calc(100vh-52px)] flex items-center justify-center p-4 py-10">
    <div class="w-full max-w-lg">

        @php
            $test = $testLink->test;
            $now = now();
            $isScheduled = $test->status === 'scheduled' && $test->scheduled_start && $now->lt($test->scheduled_start);
            $isLive = $testLink->isValid() && $test->isAccessible();
            $isExpired = !$testLink->isValid() || $test->status === 'inactive'
                         || ($test->scheduled_end && $now->gt($test->scheduled_end));
            $showForm = !$isExpired; // show form when scheduled OR live
            $totalQuestions = $test->sections->sum('question_count') ?: $test->testQuestions()->count();
        @endphp

        <!-- Test Info Card -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-5">
            <div style="background: linear-gradient(135deg, #1a3c5e, #2563EB);" class="px-6 py-5 text-white">
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-xs font-semibold bg-white/20 rounded px-2 py-0.5">{{ strtoupper($test->exam_type) }}</span>
                    @if($isLive)
                    <span class="text-xs font-semibold bg-green-400/30 text-green-200 rounded px-2 py-0.5 flex items-center gap-1">
                        <span class="h-1.5 w-1.5 rounded-full bg-green-400 animate-pulse inline-block"></span>LIVE
                    </span>
                    @elseif($isScheduled)
                    <span class="text-xs font-semibold bg-yellow-400/20 text-yellow-200 rounded px-2 py-0.5">SCHEDULED</span>
                    @elseif($isExpired)
                    <span class="text-xs font-semibold bg-red-400/20 text-red-200 rounded px-2 py-0.5">ENDED</span>
                    @endif
                </div>
                <h1 class="text-xl font-bold">{{ $test->title }}</h1>
            </div>

            <div class="grid grid-cols-3 divide-x divide-gray-100 text-center py-3 bg-gray-50 border-b border-gray-100">
                <div class="px-4 py-1">
                    <p class="text-xl font-bold text-gray-800">{{ $test->duration_minutes }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">Minutes</p>
                </div>
                <div class="px-4 py-1">
                    <p class="text-xl font-bold text-gray-800">{{ $totalQuestions ?: '—' }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">Questions</p>
                </div>
                <div class="px-4 py-1">
                    <p class="text-xl font-bold text-gray-800">{{ $test->total_marks ?? '—' }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">Total Marks</p>
                </div>
            </div>

            @if($isExpired)
            <div class="p-6 text-center">
                <p class="text-gray-500 text-sm">This test has ended and is no longer accepting entries.</p>
            </div>

            @else
            {{-- Countdown (shown when test is scheduled and not yet started) --}}
            @if($isScheduled)
            <div class="px-6 pt-5 pb-3 text-center border-b border-gray-100"
                 x-data="{
                     target: {{ $test->scheduled_start->timestamp }},
                     d: 0, h: 0, m: 0, s: 0,
                     init() {
                         let iv = setInterval(() => {
                             let diff = this.target - Math.floor(Date.now()/1000);
                             if (diff <= 0) { clearInterval(iv); location.reload(); return; }
                             this.d = Math.floor(diff / 86400);
                             this.h = Math.floor((diff % 86400) / 3600);
                             this.m = Math.floor((diff % 3600) / 60);
                             this.s = diff % 60;
                         }, 1000);
                     }
                 }">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-widest mb-2">Test starts in</p>
                <div class="flex items-center justify-center gap-3">
                    <template x-if="d > 0">
                        <div class="text-center"><span class="text-3xl font-bold text-gray-800" x-text="String(d).padStart(2,'0')"></span><p class="text-xs text-gray-400 mt-0.5">Days</p></div>
                    </template>
                    <div class="text-center"><span class="text-3xl font-bold" style="color:#1a3c5e" x-text="String(h).padStart(2,'0')"></span><p class="text-xs text-gray-400 mt-0.5">Hrs</p></div>
                    <span class="text-2xl font-bold text-gray-300 -mt-3">:</span>
                    <div class="text-center"><span class="text-3xl font-bold" style="color:#1a3c5e" x-text="String(m).padStart(2,'0')"></span><p class="text-xs text-gray-400 mt-0.5">Min</p></div>
                    <span class="text-2xl font-bold text-gray-300 -mt-3">:</span>
                    <div class="text-center"><span class="text-3xl font-bold" style="color:#1a3c5e" x-text="String(s).padStart(2,'0')"></span><p class="text-xs text-gray-400 mt-0.5">Sec</p></div>
                </div>
                <p class="text-xs text-gray-400 mt-2">{{ $test->scheduled_start->format('d M Y, h:i A') }}</p>
            </div>
            @endif

            {{-- Roll Number Form (shown for both scheduled and live states) --}}
            <div class="p-6">
                @if($isScheduled)
                <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-4">
                    Enter your roll number now so you are ready when the test begins.
                </p>
                @else
                <p class="text-sm text-gray-600 mb-4">Enter your roll number to begin the examination.</p>
                @endif

                <form wire:submit="verify" class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1.5">Roll Number</label>
                        <input
                            wire:model="rollNumber"
                            type="text"
                            placeholder="e.g. STU2026001"
                            autofocus
                            autocomplete="off"
                            style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:10px 14px;font-size:15px;outline:none;"
                            onfocus="this.style.borderColor='#1a3c5e'"
                            onblur="this.style.borderColor='#d1d5db'"
                        >
                        @if($errorMessage)
                        <p class="text-red-600 text-sm mt-2 flex items-center gap-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            {{ $errorMessage }}
                        </p>
                        @endif
                    </div>

                    <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled">
                        <span wire:loading.remove>Proceed to Instructions →</span>
                        <span wire:loading>Verifying...</span>
                    </button>
                </form>

                <div class="mt-5 p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-800">
                    <p class="font-semibold mb-1">Before you begin:</p>
                    <ul class="space-y-0.5 pl-3 list-disc">
                        <li>Ensure stable internet connection</li>
                        <li>Do not refresh or switch tabs</li>
                        <li>Test will auto-submit when timer expires</li>
                        <li>Use a laptop/desktop — not mobile</li>
                    </ul>
                </div>
            </div>
            @endif
        </div>

        <p class="text-center text-xs text-gray-400">
            Powered by <strong>ExamSphere</strong> CBT Platform
        </p>
    </div>
</div>
</div>
