<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\TestLink;
use App\Models\Student;
use App\Models\TestAttempt;
use App\Models\TestResponse;
use App\Models\TestTimerState;

new #[Layout('layouts.exam')] class extends Component {
    public string $slug;
    public ?TestLink $testLink = null;
    public ?Student $student = null;
    public ?string $errorMessage = null;

    public function mount(string $slug): void
    {
        $this->slug = $slug;
        $this->testLink = TestLink::with('test.sections')->where('slug', $slug)->first();

        if (!$this->testLink || !$this->testLink->test) {
            abort(404, 'Test not found.');
        }

        if (!$this->testLink->isValid()) {
            abort(404, 'This test link is no longer valid.');
        }

        $studentId = session("verified_student_{$slug}");
        if (!$studentId) {
            $this->redirect(route('student.test.landing', ['slug' => $slug]), navigate: false);
            return;
        }

        $this->student = Student::find($studentId);
        if (!$this->student) {
            session()->forget("verified_student_{$slug}");
            $this->redirect(route('student.test.landing', ['slug' => $slug]), navigate: false);
            return;
        }
    }

    public function beginTest(): void
    {
        $test = $this->testLink->test;

        if (!$test->isAccessible()) {
            $this->errorMessage = 'The test has not started yet or has already ended.';
            return;
        }

        $existing = TestAttempt::where('test_id', $test->id)
            ->where('student_id', $this->student->id)
            ->first();

        if ($existing) {
            if (in_array($existing->status, ['submitted', 'completed'])) {
                $this->errorMessage = 'You have already submitted this test.';
                return;
            }
            session(["active_attempt_{$this->slug}" => $existing->id]);
            session()->forget("verified_student_{$this->slug}");
            $this->redirect(route('student.test.attempt', ['slug' => $this->slug]), navigate: false);
            return;
        }

        $attempt = TestAttempt::create([
            'test_id'         => $test->id,
            'student_id'      => $this->student->id,
            'link_id'         => $this->testLink->id,
            'started_at'      => now(),
            'server_end_time' => now()->addMinutes($test->duration_minutes),
            'status'          => 'in_progress',
        ]);

        TestTimerState::create([
            'attempt_id'        => $attempt->id,
            'remaining_seconds' => $test->duration_minutes * 60,
            'last_sync_at'      => now(),
        ]);

        $rows = [];
        foreach ($test->testQuestions()->with('section')->get() as $tq) {
            $rows[] = [
                'attempt_id'       => $attempt->id,
                'test_question_id' => $tq->id,
                'question_id'      => $tq->question_id,
                'section_id'       => $tq->section_id,
                'status'           => 'not_visited',
            ];
        }
        if ($rows) TestResponse::insert($rows);

        $this->testLink->increment('total_starts');

        session(["active_attempt_{$this->slug}" => $attempt->id]);
        session()->forget("verified_student_{$this->slug}");
        $this->redirect(route('student.test.attempt', ['slug' => $this->slug]), navigate: false);
    }
}; ?>

@push('title')<title>Instructions — {{ $testLink->test->title }}</title>@endpush
@push('styles')
<style>
    body { font-family: Arial, Helvetica, sans-serif; margin: 0; background: #f0f2f5; }
    .nta-header { background: #1a3c5e; height: 52px; display: flex; align-items: center; padding: 0 24px; justify-content: space-between; }
    [x-cloak] { display: none !important; }
</style>
@endpush

@php
$t = $testLink->test;
$schedTimestamp = $t->scheduled_start?->timestamp ?? 0;
$isTestScheduled = $t->scheduled_start && now()->lt($t->scheduled_start);
@endphp

<div x-data="{
    target: {{ $schedTimestamp }},
    isScheduled: {{ $isTestScheduled ? 'true' : 'false' }},
    showOverlay: false,
    overlayRemaining: 0,
    agreed: false,
    agreementError: false,
    fmt(s) {
        let h = Math.floor(s / 3600),
            m = Math.floor((s % 3600) / 60),
            sec = s % 60;
        return String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(sec).padStart(2,'0');
    },
    clickBegin() {
        if (!this.agreed) { this.agreementError = true; return; }
        this.agreementError = false;
        if (!this.isScheduled) {
            $wire.beginTest();
            return;
        }
        this.showOverlay = true;
        this.overlayRemaining = Math.max(0, this.target - Math.floor(Date.now() / 1000));
        let iv = setInterval(() => {
            this.overlayRemaining = Math.max(0, this.target - Math.floor(Date.now() / 1000));
            if (this.overlayRemaining <= 0) {
                clearInterval(iv);
                setTimeout(() => $wire.beginTest(), 800);
            }
        }, 500);
    }
}">

<!-- Full-screen countdown overlay shown after student clicks I am ready to begin -->
<div x-show="showOverlay" x-cloak
     style="position:fixed;inset:0;background:#0f2340;z-index:9999;display:flex;align-items:center;justify-content:center;flex-direction:column;">
    <p style="color:rgba(255,255,255,.55);font-size:13px;letter-spacing:.15em;text-transform:uppercase;margin-bottom:20px;">Exam begins in</p>
    <div style="font-size:clamp(72px,12vw,140px);font-weight:900;line-height:1;font-variant-numeric:tabular-nums;color:#fff;"
         x-text="fmt(overlayRemaining)"></div>
    <p style="margin-top:28px;font-size:12px;color:rgba(255,255,255,.35);">Please wait — the exam interface will open automatically.</p>
</div>

<!-- Header -->
<div class="nta-header">
    <div class="text-white font-bold text-base">ExamSphere</div>
    <div class="text-white/70 text-sm">{{ $testLink->test->title }}</div>
</div>

@if($student)
<div class="bg-[#FEF5E4] border-b border-amber-200 px-6 py-2 flex items-center gap-3 text-sm">
    <div class="h-8 w-8 rounded-full bg-[#1a3c5e] text-white flex items-center justify-center font-bold text-sm shrink-0">
        {{ strtoupper(substr($student->name, 0, 1)) }}
    </div>
    <div>
        <span class="font-semibold text-gray-800">{{ $student->name }}</span>
        <span class="text-gray-500 ml-2">Roll: {{ $student->roll_number }}</span>
    </div>
</div>
@endif

<div class="max-w-4xl mx-auto px-4 py-8">
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">

        <div style="background:#1a3c5e;" class="text-white px-6 py-4">
            <h1 class="text-lg font-bold">General Instructions</h1>
            <p class="text-white/60 text-sm mt-0.5">Please read carefully before beginning</p>
        </div>

        <div class="p-6 space-y-6 text-sm leading-relaxed">

            <!-- Test Info -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 p-4 bg-blue-50 rounded-lg border border-blue-100">
                <div>
                    <p class="text-xs text-gray-500 uppercase font-semibold tracking-wide">Exam</p>
                    <p class="font-bold mt-0.5 text-gray-800">{{ strtoupper($t->exam_type) }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase font-semibold tracking-wide">Duration</p>
                    <p class="font-bold mt-0.5 text-gray-800">{{ $t->duration_minutes }} Min</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase font-semibold tracking-wide">Questions</p>
                    <p class="font-bold mt-0.5 text-gray-800">{{ $t->sections->sum('question_count') ?: '—' }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase font-semibold tracking-wide">Max Marks</p>
                    <p class="font-bold mt-0.5 text-gray-800">{{ $t->total_marks ?? '—' }}</p>
                </div>
            </div>

            @if($isTestScheduled)
            <!-- Countdown banner shown when test hasn't started yet -->
            <div class="p-4 bg-amber-50 border border-amber-200 rounded-lg text-center"
                 x-data="{
                     target: {{ $schedTimestamp }},
                     d:0, h:0, m:0, s:0,
                     init() {
                         let iv = setInterval(() => {
                             let diff = this.target - Math.floor(Date.now()/1000);
                             if (diff <= 0) { clearInterval(iv); this.d=0; this.h=0; this.m=0; this.s=0; return; }
                             this.d=Math.floor(diff/86400);
                             this.h=Math.floor((diff%86400)/3600);
                             this.m=Math.floor((diff%3600)/60);
                             this.s=diff%60;
                         }, 1000);
                     }
                 }">
                <p class="text-xs font-semibold text-amber-700 mb-2 uppercase tracking-wide">Exam starts in</p>
                <div class="flex items-center justify-center gap-2 text-amber-900 font-bold text-2xl">
                    <template x-if="d > 0"><span x-text="String(d).padStart(2,'0')+'d'"></span></template>
                    <span x-text="String(h).padStart(2,'0')"></span>
                    <span class="text-amber-400">:</span>
                    <span x-text="String(m).padStart(2,'0')"></span>
                    <span class="text-amber-400">:</span>
                    <span x-text="String(s).padStart(2,'0')"></span>
                </div>
                <p class="text-xs text-amber-600 mt-1">{{ $t->scheduled_start->format('d M Y, h:i A') }}</p>
            </div>
            @endif

            <div>
                <h2 class="font-bold text-base mb-3" style="color:#1a3c5e;">Instructions for the Examination:</h2>
                <ol class="list-decimal pl-5 space-y-2 text-gray-700">
                    <li>The clock will be set at the server. The countdown timer will display the remaining time available for you to complete the examination. When the timer reaches zero, the examination will end automatically.</li>
                    <li>The <strong>Question Palette</strong> displayed on the right side shows the status of each question using the following colour codes:</li>
                </ol>
            </div>

            <!-- Status Legend (NTA exact colours) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach([
                    ['#999999', 'Not Visited', 'You have not visited the question yet.'],
                    ['#E8602C', 'Not Answered', 'You have not answered the question.'],
                    ['#2E8B57', 'Answered', 'You have answered the question.'],
                    ['#7B2D8E', 'Marked for Review', 'You have marked the question for review without answering.'],
                    ['#7B2D8E', 'Answered & Marked', 'Answered and marked — WILL be evaluated.'],
                ] as [$bg, $label, $desc])
                <div class="flex items-start gap-3">
                    <div class="h-7 w-7 rounded shrink-0 flex items-center justify-center font-bold text-xs text-white" style="background:{{ $bg }}">1</div>
                    <div>
                        <p class="font-semibold text-gray-800">{{ $label }}</p>
                        <p class="text-gray-500 text-xs mt-0.5">{{ $desc }}</p>
                    </div>
                </div>
                @endforeach
            </div>

            <ol class="list-decimal pl-5 space-y-2 text-gray-700" start="3">
                <li>Click <strong>Save &amp; Next</strong> to save your answer for the current question and move to the next.</li>
                <li>Click <strong>Mark for Review &amp; Next</strong> to mark for review and move to the next question.</li>
                <li>Click <strong>Clear Response</strong> to clear your selected option.</li>
                <li>To change an already-answered question, navigate to it and select a new option, then click Save &amp; Next.</li>
                <li>Only questions with status <em>Answered</em> or <em>Answered &amp; Marked for Review</em> will be evaluated.</li>
                <li>You can navigate freely between sections and questions throughout the exam duration.</li>
                <li>The exam will auto-submit when the timer reaches zero.</li>
                <li>Do not refresh the page, switch browser tabs, or close the window during the exam.</li>
            </ol>

            <!-- Marking Scheme per section -->
            @if($t->sections->isNotEmpty())
            <div class="border border-gray-200 rounded-lg overflow-hidden">
                <div class="bg-gray-100 px-4 py-2.5 font-semibold text-sm" style="color:#1a3c5e;">Marking Scheme</div>
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-100">
                        <tr class="text-xs text-gray-500 uppercase">
                            <th class="text-left px-4 py-2">Section</th>
                            <th class="text-center px-4 py-2">Questions</th>
                            <th class="text-center px-4 py-2">Correct</th>
                            <th class="text-center px-4 py-2">Wrong</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($t->sections as $sec)
                        <tr>
                            <td class="px-4 py-2.5 font-medium">{{ $sec->name }}</td>
                            <td class="px-4 py-2.5 text-center">{{ $sec->question_count }}</td>
                            <td class="px-4 py-2.5 text-center font-semibold text-green-700">+{{ $sec->positive_marks }}</td>
                            <td class="px-4 py-2.5 text-center font-semibold text-red-600">–{{ $sec->negative_marks }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif

            @if($errorMessage)
            <div class="p-3 bg-red-50 border border-red-200 rounded text-red-700 text-sm">{{ $errorMessage }}</div>
            @endif

            <!-- Agreement & Start -->
            <div class="pt-4 border-t border-gray-200">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" x-model="agreed" @change="agreementError = false"
                           class="mt-0.5 h-4 w-4 rounded border-gray-300 accent-[#1a3c5e]">
                    <span class="text-sm text-gray-700">I have read and understood all instructions. I confirm that I am not in possession of any prohibited devices. I agree that violation of rules may result in disqualification.</span>
                </label>
                <p x-show="agreementError" x-cloak class="text-red-500 text-xs mt-2 ml-7">
                    Please check the box to confirm you have read the instructions.
                </p>

                <div class="flex justify-between items-center mt-6">
                    <a href="{{ route('student.test.landing', ['slug' => $slug]) }}" class="text-sm text-gray-500 hover:underline">← Go Back</a>
                    <button type="button" @click="clickBegin()"
                            style="background:#E8602C;color:white;border:none;padding:11px 32px;font-weight:bold;font-size:14px;cursor:pointer;border-radius:4px;"
                            wire:loading.attr="disabled">
                        <span wire:loading.remove>I am ready to begin</span>
                        <span wire:loading>Starting exam...</span>
                    </button>
                </div>
            </div>

        </div>
    </div>
</div>

</div>
