<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Chapter;
use App\Models\User;

class QuestionSeeder extends Seeder
{
    public function run(): void
    {
        $subjects = Subject::with('chapters')->get();
        if ($subjects->isEmpty()) {
            $this->command->warn('No subjects found. Please run SubjectSeeder first.');
            return;
        }

        $admin = User::where('role', 'super_admin')->first();
        if (!$admin) {
            $this->command->warn('No super admin found.');
            return;
        }

        $count = 0;
        foreach ($subjects as $subject) {
            foreach ($subject->chapters as $chapter) {
                // Generate 5 questions per chapter
                for ($i = 1; $i <= 5; $i++) {
                    $difficulty = collect(['easy', 'medium', 'hard'])->random();
                    $type = collect(['single_mcq', 'multiple_mcq', 'numerical'])->random();
                    
                    $options = null;
                    $correctAnswer = '';
                    
                    if ($type === 'single_mcq') {
                        $options = [
                            'A' => 'Option A for ' . $chapter->name,
                            'B' => 'Option B for ' . $chapter->name,
                            'C' => 'Option C for ' . $chapter->name,
                            'D' => 'Option D for ' . $chapter->name,
                        ];
                        $correctAnswer = collect(['A', 'B', 'C', 'D'])->random();
                    } elseif ($type === 'multiple_mcq') {
                        $options = [
                            'A' => 'Option A',
                            'B' => 'Option B',
                            'C' => 'Option C',
                            'D' => 'Option D',
                        ];
                        $correctAnswer = 'A,C';
                    } elseif ($type === 'numerical') {
                        $correctAnswer = (string) rand(1, 100);
                    }

                    Question::create([
                        'institution_id' => null, // Global question
                        'created_by' => $admin->id,
                        'exam_type' => $subject->exam_type,
                        'subject_id' => $subject->id,
                        'chapter_id' => $chapter->id,
                        'topic_id' => null,
                        'difficulty' => $difficulty,
                        'type' => $type,
                        'question_text' => "Sample $difficulty question $i about {$chapter->name} ({$subject->name})?",
                        'options' => $options,
                        'correct_answer' => $correctAnswer,
                        'positive_marks' => 4.00,
                        'negative_marks' => 1.00,
                        'status' => 'active',
                    ]);
                    
                    $count++;
                }
            }
        }

        $this->command->info("Seeded {$count} questions successfully.");
    }
}
