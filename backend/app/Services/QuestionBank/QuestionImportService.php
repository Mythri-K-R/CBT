<?php

namespace App\Services\QuestionBank;

use App\Models\Question;
use App\Models\QuestionImport;
use Illuminate\Support\Facades\Storage;

class QuestionImportService
{
    public function process(QuestionImport $import): void
    {
        $import->update(['status' => 'processing']);

        try {
            $rows = $this->parseFile($import);
            $import->update(['total_rows' => count($rows)]);

            $imported  = 0;
            $failed    = 0;
            $duplicate = 0;
            $errors    = [];

            foreach ($rows as $i => $row) {
                try {
                    $validated = $this->validateRow($row, $i + 2);

                    // Check for duplicate
                    $exists = Question::withoutGlobalScopes()
                        ->where('institution_id', $import->institution_id)
                        ->where('question_text', $validated['question_text'])
                        ->where('subject_id', $validated['subject_id'])
                        ->exists();

                    if ($exists) {
                        $duplicate++;
                        continue;
                    }

                    Question::withoutGlobalScopes()->create(array_merge($validated, [
                        'institution_id' => $import->institution_id,
                        'created_by'     => $import->uploaded_by,
                        'source_type'    => 'excel_import',
                    ]));
                    $imported++;
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = ['row' => $i + 2, 'error' => $e->getMessage()];
                }
            }

            $import->update([
                'imported'           => $imported,
                'failed'             => $failed,
                'duplicate_skipped'  => $duplicate,
                'error_log'          => $errors ?: null,
                'status'             => $failed > 0 && $imported === 0 ? 'failed' : 'completed',
                'completed_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            $import->update(['status' => 'failed', 'error_log' => [['row' => 0, 'error' => $e->getMessage()]]]);
            throw $e;
        }
    }

    private function parseFile(QuestionImport $import): array
    {
        $path = Storage::path($import->file_path);

        if ($import->import_type === 'pdf') {
            $pdfExtractor = app(\App\Services\PdfExtractionService::class);
            $results = $pdfExtractor->extract($path);
            
            $rows = [];
            foreach ($results['clean'] as $item) {
                $rows[] = [
                    'exam_type'      => $import->exam_type,
                    'subject_id'     => $import->subject_id,
                    'chapter_id'     => $import->chapter_id,
                    'question_text'  => $item['question'],
                    'option_A'       => $item['options']['a'] ?? '',
                    'option_B'       => $item['options']['b'] ?? '',
                    'option_C'       => $item['options']['c'] ?? '',
                    'option_D'       => $item['options']['d'] ?? '',
                    'correct_answer' => strtoupper($item['correct_answer']),
                    'source'         => $item['year'] ?? null,
                ];
            }
            
            if (!empty($results['review'])) {
                \Illuminate\Support\Facades\Log::warning("PDF Extraction had " . count($results['review']) . " items flagged for review.", ['import_id' => $import->id]);
            }
            
            return $rows;
        }

        if ($import->import_type === 'json') {
            return json_decode(file_get_contents($path), true) ?? [];
        }

        // CSV/Excel — use basic CSV reader; for xlsx, Maatwebsite Excel would be used via Job
        $rows   = [];
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($header, $row);
        }
        fclose($handle);
        return $rows;
    }

    private function validateRow(array $row, int $rowNum): array
    {
        $required = ['question_text', 'correct_answer', 'subject_id', 'chapter_id', 'exam_type'];
        foreach ($required as $field) {
            if (empty($row[$field])) {
                throw new \InvalidArgumentException("Row {$rowNum}: Missing required field '{$field}'");
            }
        }

        return [
            'exam_type'      => $row['exam_type'],
            'subject_id'     => (int) $row['subject_id'],
            'chapter_id'     => (int) $row['chapter_id'],
            'topic_id'       => isset($row['topic_id']) && $row['topic_id'] ? (int) $row['topic_id'] : null,
            'difficulty'     => $row['difficulty'] ?? 'medium',
            'type'           => $row['type'] ?? 'single_mcq',
            'question_text'  => trim($row['question_text']),
            'options'        => $this->parseOptions($row),
            'correct_answer' => trim($row['correct_answer']),
            'positive_marks' => (float) ($row['positive_marks'] ?? 4),
            'negative_marks' => (float) ($row['negative_marks'] ?? 1),
            'explanation'    => $row['explanation'] ?? null,
            'source'         => $row['source'] ?? null,
            'language'       => $row['language'] ?? 'english',
            'status'         => 'active',
        ];
    }

    private function parseOptions(array $row): array
    {
        $options = [];
        foreach (['A', 'B', 'C', 'D'] as $key) {
            $field = "option_{$key}";
            if (isset($row[$field]) && $row[$field] !== '') {
                $options[] = ['key' => $key, 'text' => $row[$field], 'image' => null];
            }
        }
        return $options;
    }
}
