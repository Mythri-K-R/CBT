<?php

namespace App\Console\Commands;

use App\Models\Chapter;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Topic;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportQuestions extends Command
{
    protected $signature   = 'questions:import {--fresh : Truncate existing imported questions before importing}';
    protected $description = 'Import questions from biology.txt, Chemistry QB.txt, Maths QB.txt, Physics.txt';

    private array $examTypeMap = [
        'NEET'          => 'neet',
        'NEET UG'       => 'neet',
        'JEE'           => 'jee_main',
        'JEE MAIN'      => 'jee_main',
        'JEE Main'      => 'jee_main',
        'JEE_MAIN'      => 'jee_main',
        'JEE ADVANCED'  => 'jee_advanced',
        'KCET'          => 'kcet',
    ];

    private array $difficultyMap = [
        'Easy'   => 'easy',
        'easy'   => 'easy',
        'EASY'   => 'easy',
        'Medium' => 'medium',
        'medium' => 'medium',
        'MEDIUM' => 'medium',
        'Hard'   => 'hard',
        'hard'   => 'hard',
        'HARD'   => 'hard',
    ];

    private array $files;
    private array $subjectCache  = [];
    private array $chapterCache  = [];
    private array $topicCache    = [];
    private int   $imported      = 0;
    private int   $skipped       = 0;
    private int   $errors        = 0;

    public function handle(): int
    {
        $base = base_path() . DIRECTORY_SEPARATOR;
        $this->files = [
            $base . 'biology.txt',
            $base . 'Chemistry QB.txt',
            $base . 'Maths QB.txt',
            $base . 'Physics.txt',
        ];

        if ($this->option('fresh')) {
            $this->warn('Removing previously imported platform questions (institution_id = NULL, source_type = platform)…');
            Question::withoutGlobalScopes()
                ->whereNull('institution_id')
                ->where('source_type', 'platform')
                ->forceDelete();
        }

        foreach ($this->files as $path) {
            if (!file_exists($path)) {
                $this->error("File not found: {$path}");
                continue;
            }
            $this->importFile($path);
        }

        $this->info("Done. Imported: {$this->imported} | Skipped: {$this->skipped} | Errors: {$this->errors}");
        return self::SUCCESS;
    }

    private function importFile(string $path): void
    {
        $filename = basename($path);
        $this->info("Importing: {$filename}");

        $contents = file_get_contents($path);
        $questions = $this->parseMultiArray($contents);

        if (empty($questions)) {
            $this->error("  Could not parse any questions from {$filename}");
            $this->errors++;
            return;
        }

        $this->line("  Found " . count($questions) . " questions");

        $bar = $this->output->createProgressBar(count($questions));
        $bar->start();

        foreach ($questions as $raw) {
            try {
                $this->importOne($raw);
            } catch (\Throwable $e) {
                $this->errors++;
                $this->newLine();
                $qid = $raw['question_id'] ?? '?';
                $this->error("  Error on question {$qid}: " . $e->getMessage());
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function importOne(array $raw): void
    {
        // ── Resolve exam type ──────────────────────────────────────────────
        $rawExam  = $raw['exam_type'] ?? $raw['exam_category'] ?? null;
        $examType = $this->examTypeMap[trim((string)$rawExam)] ?? null;

        if (!$examType) {
            $this->newLine();
            $qid = $raw['question_id'] ?? '?';
            $this->warn("  Unknown exam type '{$rawExam}' for question {$qid} — skipping");
            $this->skipped++;
            return;
        }

        // ── Options ────────────────────────────────────────────────────────
        $optionsRaw = $raw['options'] ?? [];
        if (count($optionsRaw) < 2) {
            $this->skipped++;
            return;
        }

        // Build keyed dict {A: ..., B: ..., C: ..., D: ...}
        $keys    = ['A', 'B', 'C', 'D'];
        $options = [];
        foreach (array_values($optionsRaw) as $i => $text) {
            if (isset($keys[$i])) {
                $options[$keys[$i]] = (string)$text;
            }
        }

        // ── Correct answer ─────────────────────────────────────────────────
        $correctText   = (string)($raw['correct_answer'] ?? '');
        $correctKey    = null;

        // Try to match correct_answer text to one of the options
        $optionValues  = array_values($optionsRaw);
        $foundIndex    = array_search($correctText, $optionValues);

        if ($foundIndex !== false && isset($keys[$foundIndex])) {
            $correctKey = $keys[$foundIndex];
        } else {
            // Try case-insensitive
            $lowerCorrect = strtolower(trim($correctText));
            foreach ($optionValues as $i => $text) {
                if (strtolower(trim((string)$text)) === $lowerCorrect && isset($keys[$i])) {
                    $correctKey = $keys[$i];
                    break;
                }
            }
        }

        if (!$correctKey) {
            $this->newLine();
            $qid = $raw['question_id'] ?? '?';
            $this->warn("  Could not map correct answer '{$correctText}' for {$qid} — skipping");
            $this->skipped++;
            return;
        }

        // ── Dedup: skip if same question_text already exists ─────────────
        $questionText = trim((string)($raw['question'] ?? ''));
        if (!$questionText) {
            $this->skipped++;
            return;
        }

        $exists = Question::withoutGlobalScopes()
            ->where('question_text', $questionText)
            ->whereNull('institution_id')
            ->exists();

        if ($exists) {
            $this->skipped++;
            return;
        }

        // ── Subject / Chapter / Topic ──────────────────────────────────────
        $subjectName = trim((string)($raw['subject'] ?? 'General'));
        $chapterName = trim((string)($raw['chapter'] ?? 'General'));
        $topicName   = trim((string)($raw['topic'] ?? null));

        $subject = $this->findOrCreateSubject($examType, $subjectName);
        $chapter = $this->findOrCreateChapter($subject->id, $chapterName);
        $topic   = $topicName ? $this->findOrCreateTopic($chapter->id, $topicName) : null;

        // ── Difficulty ─────────────────────────────────────────────────────
        $rawDiff   = (string)($raw['difficulty'] ?? 'Medium');
        $difficulty = $this->difficultyMap[$rawDiff] ?? 'medium';

        // ── Create question ────────────────────────────────────────────────
        Question::withoutGlobalScopes()->create([
            'institution_id'      => null,          // platform-wide
            'created_by'          => null,
            'exam_type'           => $examType,
            'subject_id'          => $subject->id,
            'chapter_id'          => $chapter->id,
            'topic_id'            => $topic?->id,
            'difficulty'          => $difficulty,
            'type'                => (($raw['question_type'] ?? '') === 'Assertion Reason') ? 'assertion_reason' : 'single_mcq',
            'question_text'       => $questionText,
            'options'             => $options,
            'correct_answer'      => $correctKey,
            'positive_marks'      => (float)($raw['marks'] ?? 4),
            'negative_marks'      => (float)($raw['negative_marks'] ?? 1),
            'explanation'         => isset($raw['explanation']) ? trim((string)$raw['explanation']) : null,
            'tags'                => $raw['tags'] ?? [],
            'has_latex'           => false,
            'has_image'           => false,
            'source_type'         => 'platform',
            'source'              => $raw['question_id'] ?? null,
            'status'              => 'active',
            'language'            => 'english',
        ]);

        $this->imported++;
    }

    private function parseMultiArray(string $content): array
    {
        $content    = str_replace("\r", "", $content);
        $lines      = explode("\n", $content);
        $all        = [];
        $buf        = [];
        $inSection  = false;

        $flushBuf = function () use (&$buf, &$all) {
            if (empty($buf)) return;
            $json   = implode("\n", $buf);
            $parsed = json_decode($json, true);
            if ($parsed) {
                $all = array_merge($all, $parsed);
            } else {
                // Try trimming trailing incomplete object: find last "  }" in buf
                $lastClose = strrpos($json, "\n  }");
                if ($lastClose !== false) {
                    $trimmed = substr($json, 0, $lastClose + 4) . "\n]";
                    $parsed2 = json_decode($trimmed, true);
                    if ($parsed2) {
                        $all = array_merge($all, $parsed2);
                    }
                }
            }
            $buf = [];
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '[') {
                if ($inSection) {
                    // Orphan: new array starts before previous was closed — force flush
                    $buf[] = ']';
                    $flushBuf();
                }
                $buf      = ['['];
                $inSection = true;
                continue;
            }

            if ($inSection) {
                if ($trimmed === ']') {
                    $buf[] = ']';
                    $flushBuf();
                    $inSection = false;
                } else {
                    $buf[] = $line;
                }
            }
        }

        // Handle unclosed last section
        if ($inSection && !empty($buf)) {
            $buf[] = ']';
            $flushBuf();
        }

        return $all;
    }

    private function findOrCreateSubject(string $examType, string $name): Subject
    {
        $cacheKey = "{$examType}::{$name}";
        if (isset($this->subjectCache[$cacheKey])) {
            return $this->subjectCache[$cacheKey];
        }

        $subject = Subject::withoutGlobalScopes()
            ->where('exam_type', $examType)
            ->whereNull('institution_id')
            ->where('name', $name)
            ->first();

        if (!$subject) {
            $order   = Subject::withoutGlobalScopes()->where('exam_type', $examType)->whereNull('institution_id')->max('display_order') + 1;
            $subject = Subject::withoutGlobalScopes()->create([
                'institution_id' => null,
                'exam_type'      => $examType,
                'name'           => $name,
                'display_order'  => $order,
                'is_active'      => true,
            ]);
        }

        $this->subjectCache[$cacheKey] = $subject;
        return $subject;
    }

    private function findOrCreateChapter(int $subjectId, string $name): Chapter
    {
        $cacheKey = "{$subjectId}::{$name}";
        if (isset($this->chapterCache[$cacheKey])) {
            return $this->chapterCache[$cacheKey];
        }

        $chapter = Chapter::where('subject_id', $subjectId)->where('name', $name)->first();

        if (!$chapter) {
            $order   = Chapter::where('subject_id', $subjectId)->max('display_order') + 1;
            $chapter = Chapter::create([
                'subject_id'    => $subjectId,
                'name'          => $name,
                'display_order' => $order,
                'is_active'     => true,
            ]);
        }

        $this->chapterCache[$cacheKey] = $chapter;
        return $chapter;
    }

    private function findOrCreateTopic(int $chapterId, string $name): Topic
    {
        $cacheKey = "{$chapterId}::{$name}";
        if (isset($this->topicCache[$cacheKey])) {
            return $this->topicCache[$cacheKey];
        }

        $topic = Topic::where('chapter_id', $chapterId)->where('name', $name)->first();

        if (!$topic) {
            $order = Topic::where('chapter_id', $chapterId)->max('display_order') + 1;
            $topic = Topic::create([
                'chapter_id'    => $chapterId,
                'name'          => $name,
                'display_order' => $order,
                'is_active'     => true,
            ]);
        }

        $this->topicCache[$cacheKey] = $topic;
        return $topic;
    }
}
