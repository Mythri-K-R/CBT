<?php

namespace App\Jobs;

use App\Events\QuestionsImported;
use App\Models\QuestionImport;
use App\Services\QuestionBank\QuestionImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessQuestionImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries   = 2;

    public function __construct(public readonly int $importId) {}

    public function handle(QuestionImportService $service): void
    {
        $import = QuestionImport::findOrFail($this->importId);
        $service->process($import);
        event(new QuestionsImported($import));
    }

    public function failed(\Throwable $exception): void
    {
        QuestionImport::where('id', $this->importId)->update(['status' => 'failed']);
    }
}
