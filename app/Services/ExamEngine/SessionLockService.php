<?php

namespace App\Services\ExamEngine;

use App\Models\TestAttempt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SessionLockService
{
    private const TTL_MINUTES = 5;

    public function createSession(TestAttempt $attempt): string
    {
        $token = Str::random(64);
        Cache::put(
            $this->cacheKey($attempt->id),
            $token,
            now()->addMinutes(self::TTL_MINUTES)
        );
        return $token;
    }

    public function validateSession(TestAttempt $attempt, string $token): bool
    {
        $stored = Cache::get($this->cacheKey($attempt->id));
        if ($stored && $stored === $token) {
            $this->refreshSession($attempt->id, $token);
            return true;
        }
        return false;
    }

    public function refreshSession(int $attemptId, string $token): void
    {
        Cache::put(
            $this->cacheKey($attemptId),
            $token,
            now()->addMinutes(self::TTL_MINUTES)
        );
    }

    public function destroySession(int $attemptId): void
    {
        Cache::forget($this->cacheKey($attemptId));
    }

    private function cacheKey(int $attemptId): string
    {
        return "exam_session_{$attemptId}";
    }
}
