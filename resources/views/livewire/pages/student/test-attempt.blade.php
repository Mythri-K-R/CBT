<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\TestAttempt;
use App\Models\TestResponse;
use App\Models\TestTimerState;

new #[Layout('layouts.exam')] class extends Component {
    public string $slug = '';
    public int $attemptId = 0;

    // Exam meta
    public string $studentName = '';
    public string $rollNumber = '';
    public string $testTitle = '';
    public string $institutionName = '';
    public int $remainingSeconds = 0;

    // Navigation
    public int $sectionIdx = 0;
    public int $questionIdx = 0;

    // All sections + questions (statuses, selected answers, text)
    public array $sections = [];

    // UI
    public bool $showSubmitModal = false;

    public function mount(string $slug): void
    {
        $this->slug = $slug;

        $attemptId = session("active_attempt_{$slug}");
        if (!$attemptId) {
            $this->redirect(route('student.test.landing', ['slug' => $slug]), navigate: false);
            return;
        }

        $attempt = TestAttempt::with(['student', 'test.institution', 'test.sections.testQuestions.question'])->find($attemptId);

        if (!$attempt) {
            session()->forget("active_attempt_{$slug}");
            $this->redirect(route('student.test.landing', ['slug' => $slug]), navigate: false);
            return;
        }

        if (in_array($attempt->status, ['submitted', 'completed'])) {
            $this->redirect(route('student.test.result', ['slug' => $slug]), navigate: false);
            return;
        }

        if ($attempt->isExpired()) {
            $this->doSubmitAttempt($attempt);
            return;
        }

        $this->attemptId        = $attempt->id;
        $this->studentName      = $attempt->student->name;
        $this->rollNumber       = $attempt->student->roll_number;
        $this->testTitle        = $attempt->test->title;
        $this->institutionName  = $attempt->test->institution?->name ?? 'ExamSphere';
        $this->remainingSeconds = $attempt->getRemainingSeconds();

        // Load existing responses
        $responses = TestResponse::where('attempt_id', $attempt->id)
            ->get()->keyBy('test_question_id');

        // Build section/question tree
        $sectionList = [];
        foreach ($attempt->test->sections as $section) {
            $questions = [];
            foreach ($section->testQuestions as $tq) {
                $resp = $responses->get($tq->id);
                $questions[] = [
                    'tq_id'  => $tq->id,
                    'num'    => $tq->question_number,
                    'status' => $resp?->status ?? 'not_visited',
                    'sel'    => $resp?->selected_answer,
                    'text'   => $tq->question->question_text ?? '',
                    'opts'   => is_array($tq->question->options) ? $tq->question->options : (json_decode($tq->question->options ?? '{}', true) ?? []),
                    'pos'    => (float)$tq->positive_marks,
                    'neg'    => (float)$tq->negative_marks,
                    'latex'  => (bool)($tq->question->has_latex ?? false),
                ];
            }
            $sectionList[] = [
                'id'   => $section->id,
                'name' => $section->name,
                'qs'   => $questions,
            ];
        }
        $this->sections = $sectionList;

        // Mark first question as not_answered
        if (!empty($this->sections[0]['qs'])) {
            $first = &$this->sections[0]['qs'][0];
            if ($first['status'] === 'not_visited') {
                $first['status'] = 'not_answered';
                TestResponse::where('attempt_id', $attempt->id)
                    ->where('test_question_id', $first['tq_id'])
                    ->update(['status' => 'not_answered']);
            }
        }
    }

    // ── Navigation ───────────────────────────────────────────────────────────

    public function goToQuestion(int $si, int $qi): void
    {
        $this->sectionIdx  = $si;
        $this->questionIdx = $qi;

        // Mark as not_answered when first visited
        $q = &$this->sections[$si]['qs'][$qi];
        if ($q['status'] === 'not_visited') {
            $q['status'] = 'not_answered';
            TestResponse::where('attempt_id', $this->attemptId)
                ->where('test_question_id', $q['tq_id'])
                ->update(['status' => 'not_answered']);
        }
    }

    public function switchSection(int $si): void
    {
        $this->goToQuestion($si, 0);
    }

    public function goToNext(): void
    {
        $section = $this->sections[$this->sectionIdx];
        $nextQi  = $this->questionIdx + 1;

        if ($nextQi < count($section['qs'])) {
            $this->goToQuestion($this->sectionIdx, $nextQi);
        } elseif ($this->sectionIdx + 1 < count($this->sections)) {
            $this->goToQuestion($this->sectionIdx + 1, 0);
        }
    }

    public function goToPrev(): void
    {
        if ($this->questionIdx > 0) {
            $this->goToQuestion($this->sectionIdx, $this->questionIdx - 1);
        } elseif ($this->sectionIdx > 0) {
            $prevSection = $this->sections[$this->sectionIdx - 1];
            $this->goToQuestion($this->sectionIdx - 1, count($prevSection['qs']) - 1);
        }
    }

    // ── Answer Actions ───────────────────────────────────────────────────────

    public function selectOption(string $key): void
    {
        $q = &$this->sections[$this->sectionIdx]['qs'][$this->questionIdx];
        $q['sel'] = ($q['sel'] === $key) ? null : $key; // toggle off if same
    }

    public function saveAndNext(): void
    {
        $q = &$this->sections[$this->sectionIdx]['qs'][$this->questionIdx];
        $newStatus = $q['sel'] !== null ? 'answered' : 'not_answered';
        $this->persistResponse($q, $newStatus);
        $this->goToNext();
    }

    public function clearResponse(): void
    {
        $q = &$this->sections[$this->sectionIdx]['qs'][$this->questionIdx];
        $q['sel']    = null;
        $q['status'] = 'not_answered';
        $this->persistResponse($q, 'not_answered');
    }

    public function saveAndMarkForReview(): void
    {
        $q = &$this->sections[$this->sectionIdx]['qs'][$this->questionIdx];
        $newStatus = $q['sel'] !== null ? 'answered_marked_review' : 'marked_review';
        $this->persistResponse($q, $newStatus);
        $this->goToNext();
    }

    public function markForReviewAndNext(): void
    {
        $q = &$this->sections[$this->sectionIdx]['qs'][$this->questionIdx];
        $newStatus = $q['sel'] !== null ? 'answered_marked_review' : 'marked_review';
        $this->persistResponse($q, $newStatus);
        $this->goToNext();
    }

    private function persistResponse(array &$q, string $status): void
    {
        $q['status'] = $status;
        TestResponse::where('attempt_id', $this->attemptId)
            ->where('test_question_id', $q['tq_id'])
            ->update([
                'status'           => $status,
                'selected_answer'  => $q['sel'],
                'last_modified_at' => now(),
            ]);
    }

    // ── Timer Sync ───────────────────────────────────────────────────────────

    public function syncTimer(int $remaining): void
    {
        TestTimerState::where('attempt_id', $this->attemptId)
            ->update(['remaining_seconds' => $remaining, 'last_sync_at' => now()]);
    }

    public function autoSubmit(): void
    {
        $attempt = TestAttempt::find($this->attemptId);
        if ($attempt && $attempt->isInProgress()) {
            $this->doSubmitAttempt($attempt);
        }
    }

    // ── Submit ───────────────────────────────────────────────────────────────

    public function confirmSubmit(): void
    {
        $this->showSubmitModal = true;
    }

    public function submitTest(): void
    {
        $attempt = TestAttempt::find($this->attemptId);
        if (!$attempt || !$attempt->isInProgress()) {
            $this->redirect(route('student.test.result', ['slug' => $this->slug]), navigate: false);
            return;
        }
        $this->doSubmitAttempt($attempt);
    }

    private function doSubmitAttempt(TestAttempt $attempt): void
    {
        $attempt->status       = 'submitted';
        $attempt->submitted_at = now();

        $totalScore  = 0.0;
        $correct     = 0;
        $wrong       = 0;
        $unattempted = 0;

        $dbResponses = TestResponse::with('testQuestion.question')
            ->where('attempt_id', $attempt->id)
            ->get();

        foreach ($dbResponses as $resp) {
            $tq       = $resp->testQuestion;
            $question = $tq?->question;
            if (!$question) { $unattempted++; continue; }

            $answered = $resp->selected_answer !== null
                && in_array($resp->status, ['answered', 'answered_marked_review']);

            if ($answered) {
                $isCorrect = $question->isCorrect($resp->selected_answer);
                $marks     = $isCorrect
                    ? (float)$tq->positive_marks
                    : -(float)$tq->negative_marks;

                if ($isCorrect) $correct++; else $wrong++;
                $totalScore += $marks;

                $resp->is_correct    = $isCorrect;
                $resp->marks_awarded = $marks;
            } else {
                $unattempted++;
                $resp->marks_awarded = 0;
            }
            $resp->save();
        }

        $totalQ = $dbResponses->count();
        $attempt->total_score      = $totalScore;
        $attempt->total_correct    = $correct;
        $attempt->total_wrong      = $wrong;
        $attempt->total_unattempted= $unattempted;
        $attempt->max_possible_score = $dbResponses->sum(fn($r) => $r->testQuestion?->positive_marks ?? 0);
        $attempt->save();

        // Update link analytics
        if ($attempt->link_id) {
            \App\Models\TestLink::where('id', $attempt->link_id)->increment('total_completions');
        }

        session(["last_attempt_{$this->slug}" => $attempt->id]);
        session()->forget("active_attempt_{$this->slug}");

        $this->redirect(route('student.test.result', ['slug' => $this->slug]), navigate: false);
    }

    // ── Computed helpers for template ────────────────────────────────────────

    public function getCounts(): array
    {
        $c = ['answered' => 0, 'not_answered' => 0, 'not_visited' => 0, 'marked_review' => 0, 'answered_marked_review' => 0];
        foreach ($this->sections as $sec) {
            foreach ($sec['qs'] as $q) {
                if (isset($c[$q['status']])) $c[$q['status']]++;
            }
        }
        return $c;
    }

    public function getSectionCounts(): array
    {
        $result = [];
        foreach ($this->sections as $i => $sec) {
            $answered = 0; $notAnswered = 0; $marked = 0; $notVisited = 0;
            foreach ($sec['qs'] as $q) {
                match($q['status']) {
                    'answered'               => $answered++,
                    'answered_marked_review' => $answered++,
                    'marked_review'          => $marked++,
                    'not_answered'           => $notAnswered++,
                    default                  => $notVisited++,
                };
            }
            $result[] = compact('answered', 'notAnswered', 'marked', 'notVisited');
        }
        return $result;
    }
}; ?>

@push('title')<title>{{ $testTitle }} — ExamSphere CBT</title>@endpush
@push('styles')
<style>
    * { box-sizing: border-box; }
    body { margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; font-size: 13px; background: #f0f2f5; overscroll-behavior: none; }
    html, body { height: 100%; }

    /* Palette button shapes (NTA standard) */
    .palette-btn { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: bold; cursor: pointer; border: none; position: relative; touch-action: manipulation; transition: transform .12s, box-shadow .12s; }
    .p-not-visited { background: #999; color: white; border-radius: 4px; }
    .p-not-answered { background: #E8602C; color: white; clip-path: polygon(0 0, 100% 0, 100% 75%, 50% 100%, 0 75%); border-radius: 4px 4px 0 0; }
    .p-answered { background: #2E8B57; color: white; border-radius: 50%; }
    .p-marked { background: #7B2D8E; color: white; clip-path: polygon(50% 0%, 100% 50%, 50% 100%, 0% 50%); }
    .p-answered-marked { background: #7B2D8E; color: white; border-radius: 50%; border: 3px solid #2E8B57; }
    .p-current { outline: 3px solid #FFD700; outline-offset: 2px; box-shadow: 0 0 0 1px #FFD700, 0 2px 8px rgba(255,215,0,.5); transform: scale(1.18); z-index: 1; }

    /* Option rows */
    .opt-row { display: flex; align-items: flex-start; gap: 12px; padding: 10px 14px; border: 1.5px solid #e5e7eb; border-radius: 6px; cursor: pointer; transition: all .15s; margin-bottom: 8px; background: white; touch-action: manipulation; }
    .opt-row:hover { border-color: #93c5fd; background: #eff6ff; }
    .opt-row.selected { border-color: #2563EB; background: #eff6ff; }
    .opt-num { min-width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 13px; background: #e5e7eb; color: #374151; flex-shrink: 0; }
    .opt-row.selected .opt-num { background: #2563EB; color: white; }

    /* Action buttons */
    .nta-btn { border: none; cursor: pointer; font-weight: bold; font-size: 11px; padding: 7px 10px; white-space: nowrap; transition: opacity .15s; touch-action: manipulation; border-radius: 3px; }
    .nta-btn:hover { opacity: .85; }
    .nta-btn:active { opacity: .7; }
    .nta-btn:disabled { opacity: .5; cursor: not-allowed; }

    /* Scrollable areas */
    .q-scroll { overflow-y: auto; flex: 1; -webkit-overflow-scrolling: touch; }
    .palette-scroll { overflow-y: auto; flex: 1; min-height: 0; -webkit-overflow-scrolling: touch; }

    /* ── MOBILE PALETTE DRAWER (top-down) ── */
    .drawer-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 200; }
    .drawer-panel { position: fixed; top: 0; left: 0; right: 0; background: white; border-radius: 0 0 16px 16px; z-index: 201; max-height: 80vh; display: flex; flex-direction: column; box-shadow: 0 4px 24px rgba(0,0,0,.22); }
    .drawer-handle { width: 40px; height: 4px; background: #ddd; border-radius: 2px; margin: 6px auto 10px; flex-shrink: 0; }

    /* ── DESKTOP: full layout ── */
    .exam-shell { display: flex; flex-direction: column; height: 100dvh; overflow: hidden; }
    .main-area { display: flex; flex: 1; overflow: hidden; }
    .question-panel { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #fff; min-width: 0; }
    .desktop-palette { width: 268px; background: #fff; border-left: 1px solid #ddd; display: flex; flex-direction: column; flex-shrink: 0; }
    .mobile-bottom-bar { display: none; }

    /* ── MOBILE: stack layout ── */
    @media (max-width: 767px) {
        .desktop-palette { display: none !important; }
        .mobile-bottom-bar { display: flex; align-items: center; gap: 6px; background: #f0f0f0; border-top: 1px solid #ccc; padding: 6px 8px; flex-shrink: 0; }
        .nta-btn { font-size: 10px; padding: 7px 8px; }
        .q-scroll { padding: 12px 14px !important; }
        .question-content { font-size: 13px !important; }
    }
</style>
@endpush

@php
    $currentSection  = $sections[$sectionIdx] ?? ['id'=>0,'name'=>'','qs'=>[]];
    $currentQuestion = $currentSection['qs'][$questionIdx] ?? null;
    $counts          = $this->getCounts();
    $sectionCounts   = $this->getSectionCounts();
    $optionLabels    = ['A'=>'1','B'=>'2','C'=>'3','D'=>'4'];
@endphp

<div class="exam-shell"
     x-data="{
         remaining: @js($remainingSeconds),
         syncEvery: 30,
         syncCounter: 0,
         tick: null,
         showPalette: false,
         init() {
             this.tick = setInterval(() => {
                 if (this.remaining > 0) {
                     this.remaining--;
                     this.syncCounter++;
                     if (this.syncCounter >= this.syncEvery) {
                         this.syncCounter = 0;
                         $wire.syncTimer(this.remaining);
                     }
                 } else {
                     clearInterval(this.tick);
                     $wire.autoSubmit();
                 }
             }, 1000);
         },
         fmt(s) {
             let h = Math.floor(s / 3600),
                 m = Math.floor((s % 3600) / 60),
                 sec = s % 60;
             return (h > 0 ? String(h).padStart(2,'0')+':' : '') +
                    String(m).padStart(2,'0')+':'+String(sec).padStart(2,'0');
         }
     }">

    {{-- ── TOP HEADER ── --}}
    <div style="background:#1a3c5e;flex-shrink:0;display:flex;align-items:center;padding:0 12px;justify-content:space-between;min-height:42px;">
        <div style="color:white;font-weight:700;font-size:13px;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $institutionName }}</div>
        {{-- Timer --}}
        <div style="flex-shrink:0;margin-left:8px;color:#ffffff;font-family:monospace;font-weight:900;font-size:22px;letter-spacing:2px;"
             :style="remaining < 300 ? 'color:#ff6b6b;' : 'color:#ffffff;'"
             x-text="fmt(remaining)">
            {{ gmdate('H:i:s', $remainingSeconds) }}
        </div>
    </div>

    {{-- ── STUDENT BAR (peach) ── --}}
    <div style="background:#FEF5E4;border-bottom:1px solid #e5c97e;flex-shrink:0;display:flex;align-items:center;padding:5px 12px;gap:10px;">
        <div style="width:34px;height:34px;background:#1a3c5e;border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-weight:bold;font-size:14px;flex-shrink:0;">
            {{ strtoupper(substr($studentName, 0, 1)) }}
        </div>
        <div style="flex:1;min-width:0;">
            <div style="font-weight:700;font-size:12px;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $studentName }}</div>
            <div style="font-size:10px;color:#666;">{{ $rollNumber }}</div>
        </div>
        {{-- Exam title shown on md+ only --}}
        <div class="hidden md:block" style="font-size:11px;font-weight:600;color:#1a3c5e;text-align:right;flex-shrink:0;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            {{ $testTitle }}
        </div>
    </div>

    {{-- ── SECTION TABS ── --}}
    <div style="background:#e8e8e8;border-bottom:2px solid #ccc;flex-shrink:0;display:flex;overflow-x:auto;scrollbar-width:none;">
        @foreach($sections as $si => $sec)
        <button wire:click="switchSection({{ $si }})"
                style="padding:7px 16px;font-size:12px;font-weight:600;border:none;border-right:1px solid #ccc;cursor:pointer;white-space:nowrap;transition:background .15s;touch-action:manipulation;
                {{ $sectionIdx === $si ? 'background:white;color:#1a3c5e;border-bottom:3px solid #1a3c5e;' : 'background:#e8e8e8;color:#555;' }}">
            {{ $sec['name'] }}
        </button>
        @endforeach
    </div>

    {{-- ── MAIN AREA ── --}}
    <div class="main-area">

        {{-- LEFT: Question panel --}}
        <div class="question-panel">

            @if($currentQuestion)
            {{-- Question meta bar --}}
            <div style="background:#f5f5f5;border-bottom:1px solid #ddd;padding:7px 12px;flex-shrink:0;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-weight:700;color:#333;font-size:12px;">Q {{ $currentQuestion['num'] }}</span>
                <span style="background:#2E8B57;color:white;border-radius:3px;padding:1px 7px;font-size:10px;font-weight:700;">+{{ $currentQuestion['pos'] }}</span>
                <span style="background:#E8602C;color:white;border-radius:3px;padding:1px 7px;font-size:10px;font-weight:700;">–{{ $currentQuestion['neg'] }}</span>
                <span style="margin-left:auto;font-size:10px;color:#888;">{{ $currentSection['name'] }}</span>
            </div>

            {{-- Scrollable question content --}}
            <div class="q-scroll" style="padding:16px 20px;background:#fff;">
                <div class="question-content" style="font-size:14px;line-height:1.75;margin-bottom:16px;">
                    {!! $currentQuestion['text'] !!}
                </div>
                <div>
                    @foreach($optionLabels as $key => $num)
                    @if(isset($currentQuestion['opts'][$key]))
                    <div wire:click="selectOption('{{ $key }}')"
                         class="opt-row {{ $currentQuestion['sel'] === $key ? 'selected' : '' }}">
                        <div class="opt-num">{{ $num }}</div>
                        <div style="flex:1;font-size:13px;line-height:1.6;padding-top:2px;">{!! $currentQuestion['opts'][$key] !!}</div>
                    </div>
                    @endif
                    @endforeach
                </div>
            </div>

            {{-- ── ACTION BUTTONS (shared desktop + mobile, slightly different wrapping) ── --}}
            <div style="background:#f0f0f0;border-top:1px solid #ddd;padding:7px 10px;display:flex;gap:6px;flex-wrap:wrap;flex-shrink:0;align-items:center;">
                <button wire:click="saveAndNext" class="nta-btn" style="background:#2E8B57;color:white;">SAVE &amp; NEXT</button>
                <button wire:click="clearResponse" class="nta-btn" style="background:#f8f8f8;color:#333;border:1px solid #aaa;">CLEAR</button>
                <button wire:click="saveAndMarkForReview" class="nta-btn" style="background:#7B2D8E;color:white;">SAVE &amp; MARK</button>
                <button wire:click="markForReviewAndNext" class="nta-btn" style="background:#2563EB;color:white;">MARK &amp; NEXT</button>
            </div>
            <div style="background:#f0f0f0;border-top:1px solid #ccc;padding:7px 10px;display:flex;gap:6px;align-items:center;flex-shrink:0;">
                <button wire:click="goToPrev" class="nta-btn" style="background:white;color:#333;border:1px solid #aaa;">&#171; BACK</button>
                <button wire:click="goToNext" class="nta-btn" style="background:white;color:#333;border:1px solid #aaa;">NEXT &#187;</button>
                {{-- Submit button visible on desktop in this bar --}}
                <div class="hidden md:block" style="margin-left:auto;">
                    <button wire:click="confirmSubmit" class="nta-btn" style="background:#1a3c5e;color:white;padding:7px 20px;">SUBMIT TEST</button>
                </div>
            </div>

            {{-- ── MOBILE BOTTOM BAR (palette toggle + submit) ── --}}
            <div class="mobile-bottom-bar">
                @if(isset($sectionCounts[$sectionIdx]))
                @php $sc = $sectionCounts[$sectionIdx]; @endphp
                <div style="display:flex;gap:8px;font-size:10px;flex:1;">
                    <span style="color:#2E8B57;font-weight:700;">✓{{ $sc['answered'] }}</span>
                    <span style="color:#E8602C;font-weight:700;">✗{{ $sc['notAnswered'] }}</span>
                    <span style="color:#7B2D8E;font-weight:700;">★{{ $sc['marked'] }}</span>
                    <span style="color:#888;">○{{ $sc['notVisited'] }}</span>
                </div>
                @endif
                <button @click="showPalette = true"
                        style="background:#1a3c5e;color:white;border:none;padding:7px 12px;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;flex-shrink:0;">
                    Questions ▼
                </button>
                <button wire:click="confirmSubmit"
                        style="background:#E8602C;color:white;border:none;padding:7px 12px;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;flex-shrink:0;">
                    Submit
                </button>
            </div>

            @else
            <div style="flex:1;display:flex;align-items:center;justify-content:center;color:#888;">No questions found.</div>
            @endif

        </div>{{-- /question-panel --}}

        {{-- RIGHT: Desktop palette (hidden on mobile) --}}
        <div class="desktop-palette">

            {{-- Legend --}}
            <div style="padding:10px 12px;border-bottom:1px solid #eee;background:#fafafa;flex-shrink:0;">
                <div style="font-size:11px;font-weight:700;color:#1a3c5e;margin-bottom:8px;">QUESTION PALETTE</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px;">
                    @foreach([
                        ['p-answered',       $counts['answered'],               'Answered'],
                        ['p-not-answered',   $counts['not_answered'],           'Not Answered'],
                        ['p-not-visited',    $counts['not_visited'],            'Not Visited'],
                        ['p-marked',         $counts['marked_review'],          'Marked'],
                        ['p-answered-marked',$counts['answered_marked_review'], 'Ans+Marked'],
                    ] as [$cls, $cnt, $lbl])
                    <div style="display:flex;align-items:center;gap:5px;font-size:11px;">
                        <div class="palette-btn {{ $cls }}" style="width:22px;height:22px;font-size:10px;">{{ $cnt }}</div>
                        <span style="color:#444;">{{ $lbl }}</span>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Section mini-tabs --}}
            @if(count($sections) > 1)
            <div style="border-bottom:1px solid #eee;display:flex;background:#f5f5f5;flex-shrink:0;overflow-x:auto;">
                @foreach($sections as $si => $sec)
                <button wire:click="switchSection({{ $si }})"
                        style="padding:5px 10px;font-size:11px;font-weight:600;border:none;border-right:1px solid #ddd;cursor:pointer;white-space:nowrap;
                        {{ $sectionIdx === $si ? 'background:white;color:#1a3c5e;' : 'background:#f5f5f5;color:#777;' }}">
                    {{ Str::limit($sec['name'], 9) }}
                </button>
                @endforeach
            </div>
            @endif

            {{-- Section counts --}}
            @if(isset($sectionCounts[$sectionIdx]))
            @php $sc = $sectionCounts[$sectionIdx]; @endphp
            <div style="padding:6px 12px;background:#f9f9f9;border-bottom:1px solid #eee;font-size:11px;display:flex;gap:10px;flex-shrink:0;">
                <span style="color:#2E8B57;font-weight:700;">✓ {{ $sc['answered'] }}</span>
                <span style="color:#E8602C;font-weight:700;">✗ {{ $sc['notAnswered'] }}</span>
                <span style="color:#7B2D8E;font-weight:700;">★ {{ $sc['marked'] }}</span>
                <span style="color:#888;">○ {{ $sc['notVisited'] }}</span>
            </div>
            @endif

            {{-- Grid --}}
            <div class="palette-scroll" style="padding:10px 12px;">
                <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:5px;">
                    @foreach($currentSection['qs'] as $qi => $q)
                    @php
                        $pcls = match($q['status']) {
                            'answered'               => 'p-answered',
                            'not_answered'           => 'p-not-answered',
                            'marked_review'          => 'p-marked',
                            'answered_marked_review' => 'p-answered-marked',
                            default                  => 'p-not-visited',
                        };
                    @endphp
                    <button wire:click="goToQuestion({{ $sectionIdx }}, {{ $qi }})"
                            class="palette-btn {{ $pcls }} {{ $qi === $questionIdx ? 'p-current' : '' }}">
                        {{ $q['num'] }}
                    </button>
                    @endforeach
                </div>
            </div>

            {{-- Submit --}}
            <div style="padding:12px;border-top:1px solid #ddd;background:#fafafa;flex-shrink:0;">
                <div style="font-size:11px;color:#666;margin-bottom:2px;">Candidate</div>
                <div style="font-size:12px;font-weight:700;color:#1a3c5e;margin-bottom:10px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $studentName }}</div>
                <button wire:click="confirmSubmit"
                        style="width:100%;padding:10px;background:#2E8B57;color:white;border:none;font-weight:700;font-size:13px;cursor:pointer;border-radius:4px;">
                    SUBMIT TEST
                </button>
            </div>

        </div>{{-- /desktop-palette --}}

    </div>{{-- /main-area --}}

    {{-- ── FOOTER (desktop only) ── --}}
    <div class="hidden md:flex" style="background:#1a3c5e;color:rgba(255,255,255,.45);padding:2px 16px;font-size:10px;justify-content:space-between;flex-shrink:0;">
        <span>© ExamSphere CBT</span><span>{{ $institutionName }}</span>
    </div>

    {{-- ── MOBILE PALETTE DRAWER (top-down, inside Alpine scope) ── --}}
    <div x-show="showPalette" x-cloak
         style="position:fixed;inset:0;z-index:200;background:rgba(0,0,0,.45);"
         @click.self="showPalette = false">
    </div>
    <div x-show="showPalette" x-cloak class="drawer-panel">
        {{-- Header row --}}
        <div style="padding:10px 14px 6px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;border-bottom:1px solid #eee;">
            <div style="font-size:13px;font-weight:700;color:#1a3c5e;">Question Palette</div>
            <button @click="showPalette = false"
                    style="background:none;border:none;font-size:20px;cursor:pointer;color:#888;line-height:1;padding:2px 8px;">✕</button>
        </div>

        {{-- Legend --}}
        <div style="padding:8px 14px;border-bottom:1px solid #f0f0f0;display:grid;grid-template-columns:1fr 1fr;gap:6px;flex-shrink:0;background:#fafafa;">
            @foreach([
                ['p-answered',       $counts['answered'],               'Answered'],
                ['p-not-answered',   $counts['not_answered'],           'Not Answered'],
                ['p-not-visited',    $counts['not_visited'],            'Not Visited'],
                ['p-marked',         $counts['marked_review'],          'Marked'],
                ['p-answered-marked',$counts['answered_marked_review'], 'Ans+Marked'],
            ] as [$cls, $cnt, $lbl])
            <div style="display:flex;align-items:center;gap:6px;font-size:11px;">
                <div class="palette-btn {{ $cls }}" style="width:22px;height:22px;font-size:10px;">{{ $cnt }}</div>
                <span style="color:#444;">{{ $lbl }}</span>
            </div>
            @endforeach
        </div>

        {{-- Section tabs in drawer --}}
        @if(count($sections) > 1)
        <div style="display:flex;overflow-x:auto;border-bottom:1px solid #eee;background:#f5f5f5;flex-shrink:0;">
            @foreach($sections as $si => $sec)
            <button wire:click="switchSection({{ $si }})"
                    @click="showPalette = false"
                    style="padding:7px 14px;font-size:12px;font-weight:600;border:none;border-right:1px solid #ddd;cursor:pointer;white-space:nowrap;touch-action:manipulation;
                    {{ $sectionIdx === $si ? 'background:white;color:#1a3c5e;' : 'background:#f5f5f5;color:#777;' }}">
                {{ $sec['name'] }}
            </button>
            @endforeach
        </div>
        @endif

        {{-- Question grid (scrollable) --}}
        <div class="palette-scroll" style="padding:12px 14px;">
            <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:8px;">
                @foreach($currentSection['qs'] as $qi => $q)
                @php
                    $pcls = match($q['status']) {
                        'answered'               => 'p-answered',
                        'not_answered'           => 'p-not-answered',
                        'marked_review'          => 'p-marked',
                        'answered_marked_review' => 'p-answered-marked',
                        default                  => 'p-not-visited',
                    };
                @endphp
                <button wire:click="goToQuestion({{ $sectionIdx }}, {{ $qi }})"
                        @click="showPalette = false"
                        class="palette-btn {{ $pcls }} {{ $qi === $questionIdx ? 'p-current' : '' }}"
                        style="width:36px;height:36px;font-size:12px;">
                    {{ $q['num'] }}
                </button>
                @endforeach
            </div>
        </div>

        {{-- Submit + close handle --}}
        <div style="padding:10px 14px 14px;border-top:1px solid #eee;flex-shrink:0;display:flex;gap:10px;">
            <button @click="showPalette = false"
                    style="flex:1;padding:11px;background:#f5f5f5;color:#444;border:1px solid #ddd;font-weight:700;font-size:13px;cursor:pointer;border-radius:6px;">
                Close ↑
            </button>
            <button wire:click="confirmSubmit"
                    style="flex:1;padding:11px;background:#E8602C;color:white;border:none;font-weight:700;font-size:13px;cursor:pointer;border-radius:6px;">
                SUBMIT TEST
            </button>
        </div>
        <div class="drawer-handle"></div>
    </div>

    {{-- ── SUBMIT MODAL (inside exam-shell = single Livewire root) ── --}}
    @if($showSubmitModal)
    @php $c = $counts; $tot = array_sum($c); @endphp
    <div style="position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:500;display:flex;align-items:center;justify-content:center;padding:16px;">
        <div style="background:white;border-radius:10px;width:100%;max-width:440px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.3);">
            <div style="background:#1a3c5e;color:white;padding:14px 18px;font-weight:700;font-size:14px;">Submit Examination?</div>
            <div style="padding:18px;">
                <p style="font-size:13px;color:#555;margin:0 0 14px;">Review your answers before submitting.</p>
                <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:14px;">
                    <tbody>
                    @foreach([
                        ['Answered',          $c['answered'],               '#2E8B57'],
                        ['Not Answered',      $c['not_answered'],           '#E8602C'],
                        ['Marked for Review', $c['marked_review'],          '#7B2D8E'],
                        ['Answered & Marked', $c['answered_marked_review'], '#7B2D8E'],
                        ['Not Visited',       $c['not_visited'],            '#999'],
                    ] as [$label, $cnt, $color])
                    <tr style="border-bottom:1px solid #f0f0f0;">
                        <td style="padding:7px 4px;color:#444;">{{ $label }}</td>
                        <td style="padding:7px 4px;text-align:right;font-weight:700;color:{{ $color }};">{{ $cnt }}</td>
                    </tr>
                    @endforeach
                    <tr style="border-top:2px solid #e5e5e5;">
                        <td style="padding:7px 4px;font-weight:700;">Total</td>
                        <td style="padding:7px 4px;text-align:right;font-weight:700;">{{ $tot }}</td>
                    </tr>
                    </tbody>
                </table>
                <div style="background:#fff8e1;border:1px solid #ffd54f;border-radius:4px;padding:9px 12px;font-size:12px;color:#856404;margin-bottom:16px;">
                    ⚠ Once submitted, you <strong>cannot</strong> change your answers.
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button wire:click="$set('showSubmitModal', false)"
                            style="padding:9px 18px;background:#f5f5f5;border:1px solid #ddd;font-weight:600;font-size:13px;cursor:pointer;border-radius:4px;">
                        Cancel
                    </button>
                    <button wire:click="submitTest"
                            style="padding:9px 22px;background:#E8602C;color:white;border:none;font-weight:700;font-size:13px;cursor:pointer;border-radius:4px;">
                        Yes, Submit
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>{{-- /exam-shell --}}

<script>
function renderMath() {
    if (typeof renderMathInElement === 'undefined') return;
    renderMathInElement(document.body, {
        delimiters: [
            { left: '$$', right: '$$', display: true },
            { left: '$',  right: '$',  display: false },
            { left: '\\(', right: '\\)', display: false },
            { left: '\\[', right: '\\]', display: true }
        ],
        throwOnError: false
    });
}
document.addEventListener('livewire:init', () => {
    Livewire.hook('morph.updated', () => setTimeout(renderMath, 50));
});
</script>
