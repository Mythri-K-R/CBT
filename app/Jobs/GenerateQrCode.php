<?php

namespace App\Jobs;

use App\Models\TestLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class GenerateQrCode implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $linkId) {}

    public function handle(): void
    {
        $link = TestLink::findOrFail($this->linkId);
        $url  = config('app.frontend_url').'/test/'.$link->slug;

        $qrCode = QrCode::format('png')
                        ->size(300)
                        ->errorCorrection('H')
                        ->generate($url);

        $path = 'qrcodes/test_'.$link->test_id.'_'.$link->slug.'.png';
        Storage::put($path, $qrCode);

        $link->update(['qr_code_path' => $path]);
    }
}
