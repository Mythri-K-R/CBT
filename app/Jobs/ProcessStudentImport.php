<?php

namespace App\Jobs;

use App\Models\Batch;
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

                foreach (['name', 'roll_number', 'Contact', 'Batch'] as $field) {
                    if (empty(trim($data[$field] ?? ''))) {
                        throw new \InvalidArgumentException("Row {$rowNum}: '{$field}' is required");
                    }
                }

                $batch = Batch::where('institution_id', $import->institution_id)
                    ->whereRaw('LOWER(name) = ?', [strtolower(trim($data['Batch']))])
                    ->first();

                if (!$batch) {
                    throw new \InvalidArgumentException("Row {$rowNum}: Batch '{$data['Batch']}' not found");
                }

                $student = $service->create($import->institution_id, [
                    'name'        => trim($data['name']),
                    'roll_number' => trim($data['roll_number']),
                    'phone'       => trim($data['Contact']),
                ]);

                $student->batches()->syncWithoutDetaching([
                    $batch->id => ['enrolled_at' => today(), 'status' => 'active']
                ]);

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
