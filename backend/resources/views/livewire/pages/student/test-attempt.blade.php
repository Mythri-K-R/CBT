<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\TestAttempt;
use App\Models\TestResponse;
use App\Models\TestQuestion;

new #[Layout('layouts.student')] #[Title('Live Exam')] class extends Component {
    public $uuid;
    public TestAttempt $attempt;
    public $responses = [];
    public $currentQuestionIndex = 0;
    public $testQuestions = [];
    public $remainingSeconds = 0;
    public $studentName = '';
    public $testTitle = '';

    public function mount($uuid)
    {
        $this->uuid = $uuid;
        $this->attempt = TestAttempt::with(['test', 'student'])->findOrFail($uuid);
        
        if ($this->attempt->status === 'submitted' || $this->attempt->status === 'completed') {
            abort(403, 'This test has already been submitted.');
        }

        if ($this->attempt->isExpired()) {
            $this->submitTest();
            abort(403, 'Time is up. Test has been auto-submitted.');
        }

        $this->studentName = $this->attempt->student->name;
        $this->testTitle = $this->attempt->test->title;
        $this->remainingSeconds = $this->attempt->getRemainingSeconds();

        $this->loadQuestionsAndResponses();

        // Pass variables to layout via view sharing or emit
        view()->share('testTitle', $this->testTitle);
        view()->share('studentName', $this->studentName);
    }

    public function loadQuestionsAndResponses()
    {
        // Get all responses for this attempt
        $dbResponses = TestResponse::where('attempt_id', $this->attempt->id)
            ->with(['testQuestion.question'])
            ->get()
            ->keyBy('test_question_id');

        $this->testQuestions = $this->attempt->test->testQuestions()
            ->orderBy('question_number')
            ->get();

        $this->responses = [];
        foreach ($this->testQuestions as $tq) {
            $resp = $dbResponses->get($tq->id);
            $this->responses[] = [
                'test_question_id' => $tq->id,
                'question_id' => $tq->question_id,
                'status' => $resp ? $resp->status : 'not_visited',
                'selected_answer' => $resp ? $resp->selected_answer : null,
                'question' => $tq->question->toArray(),
                'positive_marks' => $tq->positive_marks,
                'negative_marks' => $tq->negative_marks,
            ];
        }

        // Set the first question as not_answered if it's not_visited
        if (count($this->responses) > 0 && $this->responses[0]['status'] === 'not_visited') {
            $this->updateResponseStatus(0, 'not_answered');
        }
    }

    public function selectOption($optionKey)
    {
        $this->responses[$this->currentQuestionIndex]['selected_answer'] = $optionKey;
    }

    public function updateResponseStatus($index, $status)
    {
        $this->responses[$index]['status'] = $status;
        TestResponse::where('attempt_id', $this->attempt->id)
            ->where('test_question_id', $this->responses[$index]['test_question_id'])
            ->update([
                'status' => $status,
                'selected_answer' => $this->responses[$index]['selected_answer'],
                'last_modified_at' => now(),
            ]);
    }

    public function saveAndNext()
    {
        if ($this->responses[$this->currentQuestionIndex]['selected_answer'] !== null) {
            $this->updateResponseStatus($this->currentQuestionIndex, 'answered');
        } else {
            $this->updateResponseStatus($this->currentQuestionIndex, 'not_answered');
        }
        $this->goToNext();
    }

    public function markForReviewAndNext()
    {
        if ($this->responses[$this->currentQuestionIndex]['selected_answer'] !== null) {
            $this->updateResponseStatus($this->currentQuestionIndex, 'answered_marked_review');
        } else {
            $this->updateResponseStatus($this->currentQuestionIndex, 'marked_review');
        }
        $this->goToNext();
    }

    public function clearResponse()
    {
        $this->responses[$this->currentQuestionIndex]['selected_answer'] = null;
        $this->updateResponseStatus($this->currentQuestionIndex, 'not_answered');
    }

    public function goToNext()
    {
        if ($this->currentQuestionIndex < count($this->responses) - 1) {
            $this->goToQuestion($this->currentQuestionIndex + 1);
        }
    }

    public function goToQuestion($index)
    {
        $this->currentQuestionIndex = $index;
        if ($this->responses[$index]['status'] === 'not_visited') {
            $this->updateResponseStatus($index, 'not_answered');
        }
    }

    public function getPaletteCounts()
    {
        $counts = [
            'answered' => 0,
            'not_answered' => 0,
            'not_visited' => 0,
            'marked_review' => 0,
            'answered_marked_review' => 0,
        ];
        foreach ($this->responses as $r) {
            $counts[$r['status']]++;
        }
        return $counts;
    }

    public function submitTest()
    {
        $this->attempt->status = 'submitted';
        $this->attempt->submitted_at = now();
        
        // Basic calculation
        $total_score = 0;
        $total_correct = 0;
        $total_wrong = 0;
        $total_unattempted = 0;
        
        foreach ($this->responses as $r) {
            $q = $r['question'];
            $isCorrect = false;
            
            if ($r['selected_answer'] !== null) {
                // Check answer logic (assuming single correct option for now)
                if (isset($q['correct_answer']) && $q['correct_answer'] === $r['selected_answer']) {
                    $isCorrect = true;
                    $total_correct++;
                    $total_score += $r['positive_marks'];
                } else {
                    $total_wrong++;
                    $total_score -= $r['negative_marks'];
                }
            } else {
                $total_unattempted++;
            }
            
            TestResponse::where('attempt_id', $this->attempt->id)
                ->where('test_question_id', $r['test_question_id'])
                ->update([
                    'is_correct' => $isCorrect,
                    'marks_awarded' => $isCorrect ? $r['positive_marks'] : ($r['selected_answer'] !== null ? -$r['negative_marks'] : 0),
                    'selected_answer' => $r['selected_answer'],
                    'status' => $r['status'],
                ]);
        }
        
        $this->attempt->total_score = $total_score;
        $this->attempt->total_correct = $total_correct;
        $this->attempt->total_wrong = $total_wrong;
        $this->attempt->total_unattempted = $total_unattempted;
        $this->attempt->save();
        
        return redirect()->route('home')->with('message', 'Test submitted successfully.');
    }
}; ?>

<div class="flex h-[calc(100vh-4rem)] bg-background"
     x-data="{ 
        remaining: {{ $remainingSeconds }},
        formatTime(sec) {
            let h = Math.floor(sec / 3600);
            let m = Math.floor((sec % 3600) / 60);
            let s = sec % 60;
            return (h > 0 ? h.toString().padStart(2, '0') + ':' : '') + 
                   m.toString().padStart(2, '0') + ':' + 
                   s.toString().padStart(2, '0');
        }
     }"
     x-init="
        setInterval(() => {
            if (remaining > 0) {
                remaining--;
            } else {
                $wire.submitTest();
            }
        }, 1000);
     ">
    
    <!-- Timer Bar Mobile (Visible only on small screens) -->
    <div class="lg:hidden absolute top-0 left-0 right-0 h-1 bg-primary/20">
        <div class="h-full bg-primary transition-all" :style="'width: ' + ((remaining / {{ $attempt->getRemainingSeconds() }}) * 100) + '%'"></div>
    </div>

    <!-- Main Content Area (Question) -->
    <div class="flex-1 flex flex-col overflow-hidden relative">
        @if(count($responses) > 0)
            @php $current = $responses[$currentQuestionIndex]; @endphp
            
            <div class="flex-1 overflow-y-auto p-4 sm:p-8">
                <div class="max-w-4xl mx-auto">
                    <!-- Question Header -->
                    <div class="flex items-center justify-between mb-6 pb-4 border-b border-border">
                        <div class="flex items-center gap-3">
                            <div class="bg-primary/10 text-primary font-bold px-3 py-1 rounded-md text-sm">
                                Q {{ $currentQuestionIndex + 1 }}
                            </div>
                            <span class="text-sm font-medium text-muted-foreground">
                                Single Correct Type
                            </span>
                        </div>
                        <div class="flex gap-4 text-sm font-medium">
                            <span class="text-green-600 dark:text-green-500">+{{ $current['positive_marks'] }} Marks</span>
                            <span class="text-red-600 dark:text-red-500">-{{ $current['negative_marks'] }} Marks</span>
                        </div>
                    </div>

                    <!-- Question Content -->
                    <div class="prose prose-sm sm:prose-base max-w-none dark:prose-invert mb-8">
                        {!! $current['question']['content'] !!}
                    </div>

                    <!-- Options -->
                    <div class="space-y-3">
                        @foreach($current['question']['options'] ?? [] as $key => $optionHtml)
                            <div 
                                wire:click="selectOption('{{ $key }}')"
                                class="relative flex items-start p-4 border rounded-lg cursor-pointer transition-colors
                                @if($current['selected_answer'] === (string)$key) 
                                    border-primary bg-primary/5 ring-1 ring-primary 
                                @else 
                                    border-border hover:bg-muted/50 
                                @endif"
                            >
                                <div class="flex items-center h-5">
                                    <input type="radio" 
                                           name="option" 
                                           value="{{ $key }}"
                                           class="w-4 h-4 text-primary bg-background border-border focus:ring-primary focus:ring-offset-background"
                                           @if($current['selected_answer'] === (string)$key) checked @endif
                                    >
                                </div>
                                <div class="ml-3 text-sm">
                                    <span class="font-medium mr-2">{{ strtoupper($key) }}.</span>
                                    <span class="prose prose-sm dark:prose-invert inline">{!! $optionHtml !!}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Bottom Action Bar -->
            <div class="bg-card border-t border-border p-4 flex flex-wrap gap-3 items-center justify-between z-10 shrink-0">
                <div class="flex gap-2 w-full sm:w-auto overflow-x-auto pb-2 sm:pb-0">
                    <button wire:click="markForReviewAndNext" class="whitespace-nowrap px-4 py-2 border border-border bg-background hover:bg-muted text-sm font-medium rounded-md transition-colors text-yellow-600 dark:text-yellow-500 border-yellow-200 dark:border-yellow-900/50">
                        Mark for Review & Next
                    </button>
                    <button wire:click="clearResponse" class="whitespace-nowrap px-4 py-2 border border-border bg-background hover:bg-muted text-sm font-medium rounded-md transition-colors">
                        Clear Response
                    </button>
                </div>
                
                <div class="flex gap-2 w-full sm:w-auto">
                    <button wire:click="goToQuestion({{ $currentQuestionIndex - 1 }})" @if($currentQuestionIndex === 0) disabled @endif class="flex-1 sm:flex-none px-4 py-2 border border-border bg-background hover:bg-muted text-sm font-medium rounded-md transition-colors disabled:opacity-50">
                        Previous
                    </button>
                    <button wire:click="saveAndNext" class="flex-1 sm:flex-none px-6 py-2 bg-primary text-primary-foreground hover:bg-primary/90 text-sm font-medium rounded-md transition-colors">
                        Save & Next
                    </button>
                </div>
            </div>
        @else
            <div class="flex-1 flex items-center justify-center p-8">
                <div class="text-center">
                    <x-lucide-alert-circle class="w-12 h-12 text-muted-foreground mx-auto mb-4" />
                    <h2 class="text-xl font-semibold mb-2">No Questions Found</h2>
                    <p class="text-muted-foreground">This test doesn't have any questions configured.</p>
                </div>
            </div>
        @endif
    </div>

    <!-- Right Sidebar (Palette & Timer) -->
    <div class="w-80 bg-card border-l border-border flex flex-col shrink-0 hidden lg:flex">
        <!-- Timer -->
        <div class="p-6 border-b border-border flex flex-col items-center justify-center bg-muted/20">
            <div class="text-sm font-medium text-muted-foreground mb-1 uppercase tracking-wider">Time Left</div>
            <div class="text-3xl font-outfit font-bold font-mono tracking-tight" x-text="formatTime(remaining)" :class="remaining < 300 ? 'text-destructive animate-pulse' : 'text-foreground'">
                --:--:--
            </div>
        </div>

        <!-- Student Profile snippet -->
        <div class="p-4 border-b border-border flex items-center gap-3">
            <div class="h-10 w-10 rounded-md bg-primary/10 flex items-center justify-center text-primary font-bold text-lg">
                {{ substr($studentName, 0, 1) }}
            </div>
            <div>
                <div class="font-semibold text-sm line-clamp-1">{{ $studentName }}</div>
                <div class="text-xs text-muted-foreground">Roll No: {{ $attempt->student->roll_number }}</div>
            </div>
        </div>

        @php $palette = $this->getPaletteCounts(); @endphp

        <!-- Palette Legend -->
        <div class="p-4 border-b border-border grid grid-cols-2 gap-3 text-xs">
            <div class="flex items-center gap-2">
                <div class="w-5 h-5 rounded bg-green-500 text-white flex items-center justify-center font-bold text-[10px]">{{ $palette['answered'] }}</div>
                <span>Answered</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-5 h-5 rounded bg-red-500 text-white flex items-center justify-center font-bold text-[10px]">{{ $palette['not_answered'] }}</div>
                <span>Not Answered</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-5 h-5 rounded bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center font-bold text-[10px]">{{ $palette['not_visited'] }}</div>
                <span>Not Visited</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-5 h-5 rounded bg-yellow-500 text-white flex items-center justify-center font-bold text-[10px]">{{ $palette['marked_review'] }}</div>
                <span>Marked</span>
            </div>
            <div class="flex items-center gap-2 col-span-2">
                <div class="w-5 h-5 rounded bg-purple-500 text-white flex items-center justify-center font-bold text-[10px]">{{ $palette['answered_marked_review'] }}</div>
                <span>Answered & Marked</span>
            </div>
        </div>

        <!-- Question Grid -->
        <div class="flex-1 overflow-y-auto p-4">
            <h3 class="font-medium text-sm mb-3">Question Palette</h3>
            <div class="grid grid-cols-5 gap-2">
                @foreach($responses as $idx => $r)
                    @php
                        $bgClass = 'bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300';
                        if ($r['status'] === 'answered') $bgClass = 'bg-green-500 text-white';
                        if ($r['status'] === 'not_answered') $bgClass = 'bg-red-500 text-white';
                        if ($r['status'] === 'marked_review') $bgClass = 'bg-yellow-500 text-white';
                        if ($r['status'] === 'answered_marked_review') $bgClass = 'bg-purple-500 text-white';
                        
                        $isCurrent = $currentQuestionIndex === $idx;
                    @endphp
                    <button 
                        wire:click="goToQuestion({{ $idx }})"
                        class="w-full aspect-square rounded flex items-center justify-center text-sm font-semibold transition-all hover:ring-2 hover:ring-primary/50 hover:ring-offset-1 hover:ring-offset-background {{ $bgClass }} {{ $isCurrent ? 'ring-2 ring-primary ring-offset-2 ring-offset-background scale-110 shadow-md' : '' }}"
                    >
                        {{ $idx + 1 }}
                    </button>
                @endforeach
            </div>
        </div>

        <!-- Submit Section -->
        <div class="p-4 border-t border-border bg-muted/10">
            <button wire:click="submitTest" wire:confirm="Are you sure you want to submit the test? You cannot make changes after submission." class="w-full py-3 bg-primary text-primary-foreground hover:bg-primary/90 font-semibold rounded-md shadow-sm transition-colors">
                Submit Test
            </button>
        </div>
    </div>
</div>
