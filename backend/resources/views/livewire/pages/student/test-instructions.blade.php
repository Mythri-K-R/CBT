<?php

use App\Models\TestLink;
use Livewire\Volt\Component;

new class extends Component {
    public string $slug;
    public TestLink $testLink;
    public bool $agreed = false;

    public function mount(string $slug): void
    {
        $this->slug = $slug;
        $this->testLink = TestLink::with('test')->where('slug', $slug)->firstOrFail();

        if (!$this->testLink->isValid()) {
            abort(404, 'This test link is no longer valid.');
        }
    }

    public function proceed(): void
    {
        $this->validate(['agreed' => 'accepted']);
        $this->redirect(route('student.test.landing', ['slug' => $this->slug]));
    }
}; ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Test Instructions — {{ $testLink->test->title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.0/dist/cdn.min.js"></script>
</head>
<body class="font-sans antialiased bg-[#f5f5f5] text-[#333]">

<header style="background:#1a3c5e;" class="py-3 px-4 sm:px-8 flex items-center justify-between">
    <div class="text-white font-bold text-lg">ExamSphere</div>
    <div class="text-white/70 text-sm">{{ $testLink->test->title }}</div>
</header>

<div class="max-w-4xl mx-auto px-4 py-8">
    <div class="bg-white border border-gray-200 rounded shadow-sm">
        <!-- Title bar -->
        <div style="background:#1a3c5e;" class="text-white px-6 py-4 rounded-t">
            <h1 class="text-xl font-bold">General Instructions</h1>
        </div>

        <div class="p-6 space-y-6 text-sm leading-relaxed">

            <!-- Test info bar -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 p-4 bg-blue-50 rounded border border-blue-100">
                <div>
                    <p class="text-xs text-gray-500 uppercase font-semibold">Subject</p>
                    <p class="font-bold mt-0.5">{{ strtoupper($testLink->test->exam_type) }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase font-semibold">Duration</p>
                    <p class="font-bold mt-0.5">{{ $testLink->test->duration_minutes }} Min</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase font-semibold">Total Questions</p>
                    <p class="font-bold mt-0.5">{{ $testLink->test->testQuestions()->count() }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase font-semibold">Max Marks</p>
                    <p class="font-bold mt-0.5">{{ $testLink->test->total_marks }}</p>
                </div>
            </div>

            <div>
                <h2 class="font-bold text-base mb-3" style="color:#1a3c5e;">Please read the following instructions carefully before starting the test:</h2>
                <ol class="list-decimal pl-5 space-y-2 text-gray-700">
                    <li>The clock will be set at the server. The countdown timer in the top right corner of screen will display the remaining time available for you to complete the examination. When the timer reaches zero, the examination will end by itself. You will not be required to end or submit your examination.</li>
                    <li>The Question Palette displayed on the right side of screen will show the status of each question using one of the following symbols:</li>
                </ol>
            </div>

            <!-- Status legend (NTA style) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                @foreach([
                    ['#999999','#fff','Not Visited','You have not visited the question yet.'],
                    ['#d00000','#fff','Not Answered','You have not answered the question.'],
                    ['#26a826','#fff','Answered','You have answered the question.'],
                    ['#7B2D8E','#fff','Marked for Review','You have marked the question for review, and have NOT answered it.'],
                    ['#7B2D8E','#fff','Answered & Marked for Review','The question(s) "Answered and Marked for Review" will be considered for evaluation.'],
                ] as [$bg,$fg,$label,$desc])
                <div class="flex items-start gap-3">
                    <div class="h-7 w-7 rounded shrink-0 flex items-center justify-center font-bold text-xs" style="background:{{ $bg }};color:{{ $fg }};">1</div>
                    <div>
                        <span class="font-semibold">{{ $label }}</span>
                        <p class="text-gray-500 text-xs mt-0.5">{{ $desc }}</p>
                    </div>
                </div>
                @endforeach
            </div>

            <ol class="list-decimal pl-5 space-y-2 text-gray-700" start="3">
                <li>You can click on the "&gt;" arrow which appears to the left of question palette to collapse the question palette thereby maximizing the question window. To view the question palette again, you can click on "&lt;" which appears on the right side of question window.</li>
                <li>You can click on your "Profile" image on top right corner of your screen to change the language during the exam for entire question paper. On clicking of Profile image you will get a drop-down to change the question content to the desired language.</li>
                <li>You can click on <strong>Save & Next</strong> to save your answer for the current question and then go to the next question.</li>
                <li>You can click on <strong>Mark for Review & Next</strong> to save your answer for the current question, mark it for review, and then go to the next question.</li>
                <li>To clear your answer for the current question, click on the <strong>Clear Response</strong> button.</li>
                <li>To change your answer to a question that has already been answered, first select that question for answering and then follow the procedure for answering that type of question.</li>
                <li>Note that ONLY Questions for which answers are saved or marked for review after answering will be considered for evaluation.</li>
                <li>Questions that are saved or marked for review after answering will ONLY be considered for evaluation.</li>
                <li>Sections in this question paper are displayed on the top bar of the screen. Questions in a section can be viewed by clicking on the section name. The section you are currently viewing is highlighted.</li>
                <li>After clicking the <strong>Save & Next</strong> button on the last question for a section, you will automatically be taken to the first question of the next section.</li>
                <li>You can shuffle between sections and questions anything during the examination as per your convenience only during the time stipulated.</li>
                <li>Candidate can view the corresponding section summary as part of the legend that appears in every section above the question palette.</li>
            </ol>

            <!-- Marking scheme box -->
            @if($testLink->test->testSections()->count())
            <div class="border border-gray-200 rounded">
                <div class="bg-gray-100 px-4 py-2 font-semibold text-sm" style="color:#1a3c5e;">Marking Scheme</div>
                <div class="p-4 text-sm space-y-1">
                    <p><span class="font-semibold text-green-700">Correct Answer:</span> +{{ $testLink->test->positive_marks ?? 4 }} marks</p>
                    <p><span class="font-semibold text-red-600">Wrong Answer:</span> -{{ $testLink->test->negative_marks ?? 1 }} marks</p>
                    <p><span class="font-semibold text-gray-600">Unanswered:</span> 0 marks</p>
                </div>
            </div>
            @endif

            <!-- Agreement checkbox -->
            <div class="pt-4 border-t border-gray-200">
                <form wire:submit="proceed">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input wire:model="agreed" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary">
                        <span class="text-sm">I have read and understood the instructions. All computer hardware allotted to me are in proper working condition. I declare that I am not in possession of / not wearing / not carrying any prohibited gadget like mobile phone, bluetooth devices etc. /any prohibited material with me into the Examination Hall. I agree that in case of not adhering to the instructions, I shall be liable to be debarred from this Test and/or to disciplinary action, which may include ban from future Tests / Examinations.</span>
                    </label>
                    @error('agreed')<p class="text-red-500 text-xs mt-2">{{ $message }}</p>@enderror

                    <div class="flex justify-end mt-6">
                        <button type="submit" style="background:#E8602C;" class="rounded px-8 py-2.5 text-white font-bold text-sm hover:opacity-90 transition-opacity">
                            I am ready to begin
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>
