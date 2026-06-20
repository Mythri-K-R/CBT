<?php

namespace App\Services\ExamEngine;

use App\Models\TestAttempt;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class SessionLockService
{
    private const TTL_SECONDS = 300; // 5 minutes — matches examsphere.exam.session_token_ttl_minutes

    public function createSession(TestAttempt $attempt): string
    {
        $token = Str::random(64);
        Redis::connection('default')->setex($this->redisKey($attempt->id), self::TTL_SECONDS, $token);
        return $token;
    }

    public function validateSession(TestAttempt $attempt, string $token): bool
    {
        $stored = Redis::connection('default')->get($this->redisKey($attempt->id));
        if ($stored && $stored === $token) {
            $this->refreshSession($attempt->id, $token);
            return true;
        }
        return false;
    }

    public function refreshSession(int $attemptId, string $token): void
    {
        Redis::connection('default')->setex($this->redisKey($attemptId), self::TTL_SECONDS, $token);
    }

    public function destroySession(int $attemptId): void
    {
        Redis::connection('default')->del($this->redisKey($attemptId));
    }

    private function redisKey(int $attemptId): string
    {
        return "exam:session:{$attemptId}";
    }
}
