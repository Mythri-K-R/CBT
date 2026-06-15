<?php

namespace App\Listeners;

use App\Events\TestSubmitted;
use App\Models\Test;
use App\Models\TestLink;
use Illuminate\Contracts\Queue\ShouldQueue;

class UpdateTestDenormalizedCounts implements ShouldQueue
{
    public function handle(TestSubmitted $event): void
    {
        $attempt = $event->attempt;
        Test::where('id', $attempt->test_id)->increment('total_attempts');
        TestLink::where('id', $attempt->link_id)?->increment('total_completions');
    }
}
