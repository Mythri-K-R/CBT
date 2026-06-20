<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by CalculateTestRankings job after all rankings have been persisted.
 */
class RankGenerated
{
    use Dispatchable;

    public function __construct(
        public readonly int $testId,
        public readonly int $totalParticipants,
    ) {}
}
