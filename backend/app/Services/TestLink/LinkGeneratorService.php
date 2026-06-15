<?php

namespace App\Services\TestLink;

use App\Jobs\GenerateQrCode;
use App\Models\Test;
use App\Models\TestLink;
use Illuminate\Support\Str;

class LinkGeneratorService
{
    public function generate(Test $test, int $institutionId, ?\DateTimeInterface $expiresAt, bool $withAccessCode = false): TestLink
    {
        $slug = $this->uniqueSlug();

        $link = TestLink::create([
            'test_id'        => $test->id,
            'institution_id' => $institutionId,
            'slug'           => $slug,
            'access_code'    => $withAccessCode ? $this->generateAccessCode() : null,
            'is_active'      => true,
            'expires_at'     => $expiresAt,
        ]);

        GenerateQrCode::dispatch($link->id);

        return $link;
    }

    private function uniqueSlug(): string
    {
        $length = config('examsphere.link.slug_length', 8);
        do {
            $slug = Str::random($length);
        } while (TestLink::where('slug', $slug)->exists());

        return $slug;
    }

    private function generateAccessCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
