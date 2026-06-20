<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after RankingService finishes computing all rankings for a test.
 * At this point every attempt has a rank, percentile, and completed status.
 */
class ResultPublished
{
    use Dispatchable;

    public function __construct(
        public readonly int $testId,
        public readonly int $rankCount,
    ) {}
}
