<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\Student;
use App\Models\TestAttempt;
use App\Models\TestResponse;

new #[Layout('layouts.exam')] class extends Component {
    public Student $student;
    public TestAttempt $attempt;

    public function mount(Student $student, TestAttempt $attempt): void
    {
        abort_if(!auth()->check(), 403);
        abort_if($student->institution_id !== auth()->user()->institution_id, 403);
        abort_if($attempt->student_id !== $student->id, 403);
        abort_if(!in_array($attempt->status, ['submitted', 'completed']), 404);

        $this->student = $student;
        $this->attempt = $attempt->load('test.institution', 'test.sections', 'student');
    }

    public function with(): array
    {
        $responses = TestResponse::with([
            'testQuestion.question.chapter',
            'testQuestion.section',
        ])->where('attempt_id', $this->attempt->id)
          ->orderByRaw('(SELECT question_number FROM test_questions WHERE id = test_responses.test_question_id)')
          ->get();

        // Group by section for display
        $sections = [];
        foreach ($responses as $resp) {
            $sectionName = $resp->testQuestion?->section?->name ?? 'General';
            if (!isset($sections[$sectionName])) {
                $sections[$sectionName] = [];
            }
            $sections[$sectionName][] = $resp;
        }

        return compact('sections');
    }
}; ?>

@push('title')<title>Answer Sheet — {{ $attempt->student->name }} — {{ $attempt->test->title }}</title>@endpush
@push('styles')
<style>
    body { font-family: Arial, Helvetica, sans-serif; margin: 0; background: #f5f5f5; }
    .sheet-header { background: #1a3c5e; color: white; padding: 14px 24px; display: flex; align-items: center; justify-content: space-between; }
    .q-card { background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 12px; page-break-inside: avoid; }
    .q-num { font-size: 11px; font-weight: 700; color: #888; text-transform: uppercase; margin-bottom: 6px; }
    .q-text { font-size: 13px; color: #222; line-height: 1.6; margin-bottom: 12px; }
    .q-options { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
    .opt { display: flex; align-items: flex-start; gap: 8px; padding: 7px 10px; border-radius: 6px; font-size: 12px; border: 1px solid #e5e7eb; }
    .opt-key { font-weight: 800; min-width: 18px; }
    .opt-correct { background: #f0fdf4; border-color: #86efac; }
    .opt-selected-wrong { background: #fff5f5; border-color: #fca5a5; }
    .opt-selected-correct { background: #f0fdf4; border-color: #4ade80; }
    .status-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 12px; }
    .badge-correct { background: #dcfce7; color: #16a34a; }
    .badge-wrong { background: #fee2e2; color: #dc2626; }
    .badge-skipped { background: #f3f4f6; color: #6b7280; }
    .section-head { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #1a3c5e; padding: 10px 0 6px; border-bottom: 2px solid #1a3c5e; margin-bottom: 10px; }

    @media print {
        body { background: white; }
        .no-print { display: none !important; }
        .print-only { display: block !important; }
        .sheet-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .opt-correct, .opt-selected-correct { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .opt-selected-wrong { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .status-badge { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .q-card { border: 1px solid #ddd !important; }
    }
    .print-only { display: none; }
</style>
@endpush

<div>
<!-- Header -->
<div class="sheet-header no-print">
    <div>
        <div style="font-weight:700;font-size:15px;">Answer Sheet</div>
        <div style="font-size:12px;opacity:.7;margin-top:2px;">{{ $attempt->test->title }} · {{ $student->name }} ({{ $student->roll_number }})</div>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
        <a href="{{ route('institution.students.attempt', ['student' => $student->id, 'attempt' => $attempt->uuid]) }}"
           style="color:rgba(255,255,255,.7);font-size:13px;text-decoration:none;">← Back to Results</a>
        <button onclick="window.print()"
                style="background:#E8602C;color:white;border:none;padding:8px 20px;border-radius:6px;font-weight:700;font-size:13px;cursor:pointer;">
            Download / Print PDF
        </button>
    </div>
</div>

<!-- Print-only header (shows institution name) -->
<div style="display:none;" class="print-only">
    <div style="background:#1a3c5e;color:white;padding:14px 24px;-webkit-print-color-adjust:exact;print-color-adjust:exact;">
        <div style="font-weight:700;font-size:16px;">{{ $attempt->test->institution?->name ?? 'Examsphere' }} — Answer Sheet</div>
        <div style="font-size:12px;opacity:.7;margin-top:2px;">
            {{ $attempt->test->title }} · {{ $student->name }} ({{ $student->roll_number }}) · Submitted: {{ $attempt->submitted_at?->format('d M Y, h:i A') }}
        </div>
    </div>
</div>

<!-- Summary bar -->
<div style="background:white;border-bottom:1px solid #e5e7eb;padding:10px 24px;display:flex;gap:24px;align-items:center;font-size:13px;" class="no-print">
    <span><strong>Score:</strong> {{ number_format($attempt->total_score, 0) }} / {{ number_format($attempt->max_possible_score ?? 0, 0) }}</span>
    <span style="color:#16a34a;font-weight:600;">✓ {{ $attempt->total_correct }} correct</span>
    <span style="color:#dc2626;font-weight:600;">✗ {{ $attempt->total_wrong }} wrong</span>
    <span style="color:#6b7280;">{{ $attempt->total_unattempted }} skipped</span>
</div>

<!-- Answer Sheet Body -->
<div style="max-width:900px;margin:0 auto;padding:20px 16px 48px;">

    @php $qGlobal = 1; @endphp

    @foreach($sections as $sectionName => $responses)
    <div class="section-head">{{ $sectionName }}</div>

    @foreach($responses as $resp)
    @php
        $tq       = $resp->testQuestion;
        $question = $tq?->question;
        $opts     = $question?->options ?? [];
        if (is_string($opts)) $opts = json_decode($opts, true) ?? [];
        $correct  = $question?->correct_answer ?? null;
        $selected = $resp->selected_answer;
        $answered = $selected !== null && in_array($resp->status, ['answered', 'answered_marked_review']);
        $isCorrect = $resp->is_correct;
        $chapterName = $question?->chapter?->name ?? '';
    @endphp

    <div class="q-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
            <div>
                <span class="q-num">Q{{ $tq?->question_number ?? $qGlobal }}</span>
                @if($chapterName)
                <span style="font-size:10px;color:#aaa;margin-left:8px;">{{ $chapterName }}</span>
                @endif
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                @if(!$answered)
                <span class="status-badge badge-skipped">— Skipped</span>
                @elseif($isCorrect)
                <span class="status-badge badge-correct">✓ Correct +{{ number_format($resp->marks_awarded, 0) }}</span>
                @else
                <span class="status-badge badge-wrong">✗ Wrong {{ number_format($resp->marks_awarded, 0) }}</span>
                @endif
            </div>
        </div>

        @if($question?->question_text)
        <div class="q-text">{!! nl2br(e($question->question_text)) !!}</div>
        @endif

        <div class="q-options">
            @foreach($opts as $key => $text)
            @php
                $isCorrectOpt  = ($key === $correct);
                $isSelectedOpt = ($key === $selected);
                $optClass = '';
                if ($isCorrectOpt && $isSelectedOpt) $optClass = 'opt-selected-correct';
                elseif ($isCorrectOpt)                $optClass = 'opt-correct';
                elseif ($isSelectedOpt)               $optClass = 'opt-selected-wrong';
            @endphp
            <div class="opt {{ $optClass }}">
                <span class="opt-key">{{ $key }}.</span>
                <span>
                    {{ $text }}
                    @if($isSelectedOpt && $isCorrectOpt)
                    <span style="color:#16a34a;font-weight:700;margin-left:4px;">← Your answer ✓</span>
                    @elseif($isSelectedOpt)
                    <span style="color:#dc2626;font-weight:700;margin-left:4px;">← Your answer ✗</span>
                    @elseif($isCorrectOpt)
                    <span style="color:#16a34a;font-weight:700;margin-left:4px;">← Correct</span>
                    @endif
                </span>
            </div>
            @endforeach
        </div>
    </div>

    @php $qGlobal++; @endphp
    @endforeach

    @endforeach

    <!-- Print footer -->
    <div style="text-align:center;padding-top:16px;font-size:11px;color:#aaa;">
        Generated by Examsphere · Mitra Softwares CBT Platform · {{ now()->format('d M Y, h:i A') }}
    </div>
</div>
</div>
