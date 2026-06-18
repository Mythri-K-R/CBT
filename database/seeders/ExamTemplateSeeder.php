<?php

namespace Database\Seeders;

use App\Models\ExamTemplate;
use App\Models\Subject;
use Illuminate\Database\Seeder;

class ExamTemplateSeeder extends Seeder
{
    public function run(): void
    {
        // NEET Full Mock template
        $neetPhysics  = Subject::where('exam_type','neet')->where('name','Physics')->first();
        $neetChemistry= Subject::where('exam_type','neet')->where('name','Chemistry')->first();
        $neetBiology  = Subject::where('exam_type','neet')->where('name','Biology')->first();

        $neetTemplate = ExamTemplate::firstOrCreate(
            ['name' => 'NEET UG Full Mock', 'exam_type' => 'neet', 'institution_id' => null],
            [
                'total_duration_minutes'  => 200,
                'total_questions'         => 180,
                'total_marks'             => 720,
                'has_sectional_timing'    => false,
                'allow_section_switching' => true,
                'instructions'            => 'This is a NEET UG pattern mock test with 180 questions across Physics, Chemistry, and Biology.',
                'is_active'               => true,
            ]
        );

        if ($neetTemplate->sections()->count() === 0) {
            $sections = [
                ['name' => 'Physics',   'subject_id' => $neetPhysics?->id,   'question_count' => 45, 'positive_marks' => 4, 'negative_marks' => 1, 'question_type' => 'single_mcq'],
                ['name' => 'Chemistry', 'subject_id' => $neetChemistry?->id, 'question_count' => 45, 'positive_marks' => 4, 'negative_marks' => 1, 'question_type' => 'single_mcq'],
                ['name' => 'Botany',    'subject_id' => $neetBiology?->id,   'question_count' => 45, 'positive_marks' => 4, 'negative_marks' => 1, 'question_type' => 'single_mcq'],
                ['name' => 'Zoology',   'subject_id' => $neetBiology?->id,   'question_count' => 45, 'positive_marks' => 4, 'negative_marks' => 1, 'question_type' => 'single_mcq'],
            ];
            foreach ($sections as $order => $s) {
                $neetTemplate->sections()->create(array_merge($s, ['display_order' => $order]));
            }
        }

        // JEE Main template
        $jeePhysics  = Subject::where('exam_type','jee_main')->where('name','Physics')->first();
        $jeeChem     = Subject::where('exam_type','jee_main')->where('name','Chemistry')->first();
        $jeeMath     = Subject::where('exam_type','jee_main')->where('name','Mathematics')->first();

        $jeeTemplate = ExamTemplate::firstOrCreate(
            ['name' => 'JEE Main Full Mock', 'exam_type' => 'jee_main', 'institution_id' => null],
            [
                'total_duration_minutes'  => 180,
                'total_questions'         => 75,
                'total_marks'             => 300,
                'has_sectional_timing'    => false,
                'allow_section_switching' => true,
                'instructions'            => 'JEE Main pattern mock. 75 questions, 180 minutes.',
                'is_active'               => true,
            ]
        );

        if ($jeeTemplate->sections()->count() === 0) {
            $sections = [
                ['name' => 'Physics',     'subject_id' => $jeePhysics?->id, 'question_count' => 25, 'positive_marks' => 4, 'negative_marks' => 1, 'question_type' => 'mixed'],
                ['name' => 'Chemistry',   'subject_id' => $jeeChem?->id,    'question_count' => 25, 'positive_marks' => 4, 'negative_marks' => 1, 'question_type' => 'mixed'],
                ['name' => 'Mathematics', 'subject_id' => $jeeMath?->id,    'question_count' => 25, 'positive_marks' => 4, 'negative_marks' => 1, 'question_type' => 'mixed'],
            ];
            foreach ($sections as $order => $s) {
                $jeeTemplate->sections()->create(array_merge($s, ['display_order' => $order]));
            }
        }

        $this->command->info('Exam templates seeded.');
    }
}
