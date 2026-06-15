<?php

namespace App\Jobs;

use App\Models\QuestionImport;
use App\Models\Student;
use App\Services\StudentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ProcessStudentImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries   = 2;

    public function __construct(
        public readonly int  $importId,
        public readonly ?int $batchId
    ) {}

    public function handle(StudentService $service): void
    {
        $import = QuestionImport::findOrFail($this->importId);
        $import->update(['status' => 'processing']);

        $path    = Storage::path($import->file_path);
        $handle  = fopen($path, 'r');
        $header  = fgetcsv($handle);

        $imported = 0;
        $failed   = 0;
        $errors   = [];
        $rowNum   = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            try {
                $data = array_combine($header, $row);

                if (empty($data['name'])) {
                    throw new \InvalidArgumentException('Missing student name');
                }

                $student = $service->create($import->institution_id, [
                    'name'         => $data['name'],
                    'phone'        => $data['phone'] ?? null,
                    'parent_phone' => $data['parent_phone'] ?? null,
                    'roll_number'  => $data['roll_number'] ?? null,
                ]);

                if ($this->batchId) {
                    $student->batches()->syncWithoutDetaching([
                        $this->batchId => ['enrolled_at' => today(), 'status' => 'active']
                    ]);
                }

                $imported++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['row' => $rowNum, 'error' => $e->getMessage()];
            }
        }

        fclose($handle);

        $import->update([
            'total_rows'   => $imported + $failed,
            'imported'     => $imported,
            'failed'       => $failed,
            'error_log'    => $errors ?: null,
            'status'       => 'completed',
            'completed_at' => now(),
        ]);
    }
}
