<?php

namespace App\Services;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Exception;

class PdfExtractionService
{
    /**
     * Extract MCQ questions from any PDF using the Python universal extractor.
     *
     * Returns:
     *   [
     *     'clean'  => [ ...question arrays... ],
     *     'review' => [ ...flagged arrays... ],
     *   ]
     *
     * Each clean question has keys:
     *   question_number, question, options{a,b,c,d}, correct_answer,
     *   subject_auto, difficulty, diagram, has_latex
     */
    public function extract(string $pdfPath): array
    {
        if (!file_exists($pdfPath)) {
            throw new Exception("PDF not found: {$pdfPath}");
        }

        $timestamp = now()->timestamp . '_' . uniqid();

        $baseDir     = storage_path('app/pdf_extractions');
        $diagramsDir = storage_path("app/public/diagrams/{$timestamp}");

        foreach ([$baseDir, $diagramsDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $cleanOut  = "{$baseDir}/clean_{$timestamp}.json";
        $reviewOut = "{$baseDir}/review_{$timestamp}.json";
        $script = base_path('services/pdf_extractor/extract_questions.py');

        // Allow PHP to run longer than its default limit (extraction can take minutes)
        $previousLimit = ini_get('max_execution_time');
        set_time_limit(0);

        $process = new Process(
            ['python', $script, $pdfPath, $diagramsDir, $cleanOut, $reviewOut],
            null,
            null,
            null,
            600  // 10-minute timeout for large PDFs
        );

        $process->run(function ($type, $buffer) {
            // Stream Python stdout/stderr to PHP error log for debugging
            error_log('[PDF Extractor] ' . trim($buffer));
        });

        // Restore previous time limit
        set_time_limit((int) $previousLimit);

        if (!$process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());
            $exit   = $process->getExitCode();
            throw new Exception(
                "PDF extraction failed (exit {$exit})" .
                ($stderr ? ": {$stderr}" : '')
            );
        }

        if (!file_exists($cleanOut)) {
            throw new Exception('Extractor ran but produced no output. Check the PDF is valid.');
        }

        $clean  = json_decode(file_get_contents($cleanOut),  true) ?: [];
        $review = json_decode(file_get_contents($reviewOut), true) ?: [];

        // Attach public diagram URLs
        foreach ($clean as &$q) {
            if (!empty($q['diagram'])) {
                $q['diagram_url'] = 'storage/diagrams/' . $timestamp . '/' . $q['diagram'];
            }
        }
        unset($q);

        // Clean up temp JSON files; keep diagrams in storage/public
        @unlink($cleanOut);
        @unlink($reviewOut);

        return ['clean' => $clean, 'review' => $review];
    }
}
