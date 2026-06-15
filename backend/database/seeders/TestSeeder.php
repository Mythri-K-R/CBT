<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ExamTemplate;
use App\Models\Institution;
use App\Models\Test;
use App\Models\TestSection;
use App\Models\TestQuestion;
use App\Models\Question;
use App\Models\Batch;
use App\Models\User;

class TestSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'super_admin')->first();
        $institution = Institution::where('code', 'SAI001')->first();
        
        if (!$admin || !$institution) {
            $this->command->warn('Admin or Institution not found.');
            return;
        }

        // Create NEET Mock Test
        $neetTemplate = ExamTemplate::where('exam_type', 'neet')->first();
        if ($neetTemplate) {
            $neetTest = Test::create([
                'institution_id' => $institution->id,
                'created_by' => $admin->id,
                'template_id' => $neetTemplate->id,
                'title' => 'Weekly NEET Mock Test 1',
                'description' => 'First weekly mock test for NEET aspirants.',
                'exam_type' => 'neet',
                'test_category' => 'mock_test',
                'duration_minutes' => 200,
                'has_sectional_timing' => false,
                'allow_section_switching' => true,
                'scheduled_start' => now()->subDays(2),
                'scheduled_end' => now()->addDays(5),
                'status' => 'live',
            ]);

            $this->populateTest($neetTest, $neetTemplate, 'neet');

            // Attach to NEET batch
            $neetBatch = Batch::where('exam_type', 'NEET')->first();
            if ($neetBatch) {
                $neetTest->batches()->attach($neetBatch->id);
            }
        }

        // Create JEE Mock Test
        $jeeTemplate = ExamTemplate::where('exam_type', 'jee_main')->first();
        if ($jeeTemplate) {
            $jeeTest = Test::create([
                'institution_id' => $institution->id,
                'created_by' => $admin->id,
                'template_id' => $jeeTemplate->id,
                'title' => 'Weekly JEE Main Mock Test 1',
                'description' => 'First weekly mock test for JEE aspirants.',
                'exam_type' => 'jee_main',
                'test_category' => 'mock_test',
                'duration_minutes' => 180,
                'has_sectional_timing' => false,
                'allow_section_switching' => true,
                'scheduled_start' => now()->addDays(1),
                'scheduled_end' => now()->addDays(3),
                'status' => 'scheduled',
            ]);

            $this->populateTest($jeeTest, $jeeTemplate, 'jee_main');

            // Attach to JEE batch
            $jeeBatch = Batch::where('exam_type', 'JEE Main')->first();
            if ($jeeBatch) {
                $jeeTest->batches()->attach($jeeBatch->id);
            }
        }

        $this->command->info('Seeded mock tests successfully.');
    }

    private function populateTest(Test $test, ExamTemplate $template, string $examType)
    {
        foreach ($template->sections as $tplSection) {
            $section = TestSection::create([
                'test_id' => $test->id,
                'name' => $tplSection->name,
                'subject_id' => $tplSection->subject_id,
                'duration_minutes' => 60,
                'question_count' => min($tplSection->question_count, 10), // Limit for testing
                'positive_marks' => $tplSection->positive_marks,
                'negative_marks' => $tplSection->negative_marks,
                'display_order' => $tplSection->display_order,
            ]);

            // Get random questions for this subject
            $questions = Question::where('subject_id', $section->subject_id)
                ->inRandomOrder()
                ->limit($section->question_count)
                ->get();

            $qNum = 1;
            foreach ($questions as $question) {
                TestQuestion::create([
                    'test_id' => $test->id,
                    'section_id' => $section->id,
                    'question_id' => $question->id,
                    'question_number' => $qNum++,
                    'positive_marks' => $section->positive_marks,
                    'negative_marks' => $section->negative_marks,
                    'is_mandatory' => true,
                ]);
            }
        }
    }
}
