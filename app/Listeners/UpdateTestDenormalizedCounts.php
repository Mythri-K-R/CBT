<?php

namespace App\Listeners;

use App\Events\TestSubmitted;
use App\Models\Test;
use App\Models\TestLink;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class UpdateTestDenormalizedCounts implements ShouldQueue
{
    public string $queue   = 'analytics';
    public int    $tries   = 3;
    public int    $backoff = 30;

    public function handle(TestSubmitted $event): void
    {
        $attempt = $event->attempt;
        Test::where('id', $attempt->test_id)->increment('total_attempts');
        TestLink::where('id', $attempt->link_id)?->increment('total_completions');
    }

    public function failed(TestSubmitted $event, \Throwable $e): void
    {
        Log::error('UpdateTestDenormalizedCounts failed', [
            'attempt_id' => $event->attempt->id,
            'test_id'    => $event->attempt->test_id,
            'error'      => $e->getMessage(),
        ]);
    }
}
