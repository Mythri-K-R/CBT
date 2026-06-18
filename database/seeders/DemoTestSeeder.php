<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Chapter;
use App\Models\Institution;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Test;
use App\Models\TestLink;
use App\Models\TestQuestion;
use App\Models\TestSection;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoTestSeeder extends Seeder
{
    public function run(): void
    {
        $institution = Institution::where('code', 'SAI001')->first();
        if (!$institution) {
            $this->command->error('Run InstitutionSeeder first.');
            return;
        }

        $admin = User::where('institution_id', $institution->id)
            ->where('role', 'institution_admin')
            ->first();

        if (!$admin) {
            $this->command->error('No institution admin found.');
            return;
        }

        // ── Ensure subjects and chapters exist ──────────────────────────────
        $physSubject = Subject::firstOrCreate(
            ['exam_type' => 'neet', 'name' => 'Physics', 'institution_id' => null],
            ['code' => 'PHY', 'display_order' => 1, 'is_active' => true]
        );
        $chemSubject = Subject::firstOrCreate(
            ['exam_type' => 'neet', 'name' => 'Chemistry', 'institution_id' => null],
            ['code' => 'CHE', 'display_order' => 2, 'is_active' => true]
        );

        $mechChapter = Chapter::firstOrCreate(
            ['subject_id' => $physSubject->id, 'name' => 'Kinematics'],
            ['display_order' => 1, 'is_active' => true]
        );
        $chemChapter = Chapter::firstOrCreate(
            ['subject_id' => $chemSubject->id, 'name' => 'Some Basic Concepts of Chemistry'],
            ['display_order' => 1, 'is_active' => true]
        );

        // ── Create 15 Physics questions ──────────────────────────────────────
        $physicsQs = $this->createPhysicsQuestions($institution, $admin, $physSubject, $mechChapter);

        // ── Create 15 Chemistry questions ────────────────────────────────────
        $chemQs = $this->createChemistryQuestions($institution, $admin, $chemSubject, $chemChapter);

        // ── Create the test ──────────────────────────────────────────────────
        $test = Test::firstOrCreate(
            ['institution_id' => $institution->id, 'title' => 'NEET Demo Test — Physics & Chemistry'],
            [
                'created_by'              => $admin->id,
                'exam_type'               => 'neet',
                'test_category'           => 'mock_test',
                'duration_minutes'        => 60,
                'has_sectional_timing'    => false,
                'allow_section_switching' => true,
                'shuffle_questions'       => false,
                'shuffle_options'         => false,
                'show_result_immediately' => true,
                'status'                  => 'live',
            ]
        );

        // Sections (idempotent by checking existing)
        if ($test->sections()->count() === 0) {
            $physSection = TestSection::create([
                'test_id'        => $test->id,
                'name'           => 'Physics',
                'subject_id'     => $physSubject->id,
                'question_count' => 15,
                'positive_marks' => 4,
                'negative_marks' => 1,
                'display_order'  => 1,
            ]);

            $chemSection = TestSection::create([
                'test_id'        => $test->id,
                'name'           => 'Chemistry',
                'subject_id'     => $chemSubject->id,
                'question_count' => 15,
                'positive_marks' => 4,
                'negative_marks' => 1,
                'display_order'  => 2,
            ]);

            $qNum = 1;
            foreach ($physicsQs as $q) {
                TestQuestion::create([
                    'test_id'         => $test->id,
                    'section_id'      => $physSection->id,
                    'question_id'     => $q->id,
                    'question_number' => $qNum++,
                    'positive_marks'  => 4,
                    'negative_marks'  => 1,
                    'is_mandatory'    => true,
                ]);
            }

            foreach ($chemQs as $q) {
                TestQuestion::create([
                    'test_id'         => $test->id,
                    'section_id'      => $chemSection->id,
                    'question_id'     => $q->id,
                    'question_number' => $qNum++,
                    'positive_marks'  => 4,
                    'negative_marks'  => 1,
                    'is_mandatory'    => true,
                ]);
            }

            $this->command->info('Created 2 sections with 30 questions.');

            // Set total_marks (30 questions × 4 marks each)
            $test->update(['total_marks' => 30 * 4]);
        }

        // ── Attach NEET batch ────────────────────────────────────────────────
        $neetBatch = Batch::where('institution_id', $institution->id)
            ->whereIn('exam_type', ['neet', 'NEET'])->first();

        if ($neetBatch && !$test->batches()->where('batch_id', $neetBatch->id)->exists()) {
            $test->batches()->attach($neetBatch->id);
            $this->command->info('Attached NEET batch to test.');
        }

        // ── Create test link ─────────────────────────────────────────────────
        $existingLink = TestLink::where('test_id', $test->id)->where('is_active', true)->first();
        if (!$existingLink) {
            $link = TestLink::create([
                'test_id'        => $test->id,
                'institution_id' => $institution->id,
                'slug'           => 'demo-neet-'.Str::random(6),
                'is_active'      => true,
                'expires_at'     => null,
            ]);
            $this->command->info("Test link: /t/{$link->slug}");
        } else {
            $this->command->info("Existing link: /t/{$existingLink->slug}");
        }

        // Ensure demo students exist in the NEET batch
        if ($neetBatch) {
            $demoStudents = [
                ['Rahul Sharma',    'DEMO001'],
                ['Priya Patel',     'DEMO002'],
                ['Arjun Singh',     'DEMO003'],
                ['Ananya Reddy',    'DEMO004'],
                ['Kiran Kumar',     'DEMO005'],
            ];
            foreach ($demoStudents as [$name, $roll]) {
                $student = \App\Models\Student::firstOrCreate(
                    ['institution_id' => $institution->id, 'roll_number' => $roll],
                    ['name' => $name, 'is_active' => true]
                );
                if (!$neetBatch->students()->where('student_id', $student->id)->exists()) {
                    $neetBatch->students()->attach($student->id, ['enrolled_at' => now(), 'status' => 'active']);
                }
            }
            $this->command->info('Created 5 demo students in NEET batch.');
        }

        // Print student rolls for testing
        $students = $neetBatch?->students()->limit(5)->get();
        if ($students?->count()) {
            $this->command->info('Sample roll numbers to test with:');
            foreach ($students as $s) {
                $this->command->line("  → {$s->name}: {$s->roll_number}");
            }
        }

        $this->command->info('DemoTestSeeder complete.');
    }

    private function createPhysicsQuestions($institution, $admin, $subject, $chapter): array
    {
        $questions = [
            ['A body starts from rest and travels with uniform acceleration. If it covers 20 m in the first 2 seconds, what is its acceleration?', 'A' => '5 m/s²', 'B' => '10 m/s²', 'C' => '20 m/s²', 'D' => '40 m/s²', 'ans' => 'B'],
            ['A ball is thrown vertically upward with a speed of 20 m/s. How long does it take to reach the maximum height? (g = 10 m/s²)', 'A' => '1 s', 'B' => '2 s', 'C' => '4 s', 'D' => '0.5 s', 'ans' => 'B'],
            ['A car travels 40 km at 40 km/h and another 40 km at 60 km/h. The average speed is:', 'A' => '50 km/h', 'B' => '48 km/h', 'C' => '45 km/h', 'D' => '55 km/h', 'ans' => 'B'],
            ['An object is thrown horizontally from a height of 19.6 m. The time taken to reach the ground is (g = 9.8 m/s²):', 'A' => '1 s', 'B' => '2 s', 'C' => '3 s', 'D' => '4 s', 'ans' => 'B'],
            ['A train starts from rest and accelerates uniformly to a speed of 20 m/s in 50 s. The distance covered is:', 'A' => '250 m', 'B' => '500 m', 'C' => '1000 m', 'D' => '2000 m', 'ans' => 'B'],
            ['Two balls A and B are thrown from the same height simultaneously. Ball A is thrown horizontally and Ball B is dropped. Which ball reaches the ground first?', 'A' => 'Ball A', 'B' => 'Ball B', 'C' => 'Both reach simultaneously', 'D' => 'Depends on mass', 'ans' => 'C'],
            ['A particle starts from rest and moves with constant acceleration. The ratio of distances covered in the 1st and 3rd second is:', 'A' => '1:3', 'B' => '1:5', 'C' => '1:9', 'D' => '3:5', 'ans' => 'A'],
            ['The velocity-time graph of a body is a straight line parallel to the time axis. The body is:', 'A' => 'at rest', 'B' => 'moving with uniform velocity', 'C' => 'moving with uniform acceleration', 'D' => 'moving with non-uniform acceleration', 'ans' => 'B'],
            ['A particle moves in a circle of radius r with uniform speed v. The centripetal acceleration is:', 'A' => 'v/r', 'B' => 'v²/r', 'C' => 'r/v²', 'D' => 'v²r', 'ans' => 'B'],
            ['If a particle completes one revolution in a circle of radius 7 m, the displacement is:', 'A' => '44 m', 'B' => '22 m', 'C' => '14 m', 'D' => '0 m', 'ans' => 'D'],
            ['A bullet of mass 10 g is fired with a velocity of 800 m/s. If the barrel of the gun is 1 m long, the average force exerted on the bullet is:', 'A' => '3200 N', 'B' => '6400 N', 'C' => '800 N', 'D' => '1600 N', 'ans' => 'A'],
            ['Newton\'s first law of motion defines:', 'A' => 'force', 'B' => 'inertia', 'C' => 'momentum', 'D' => 'acceleration', 'ans' => 'B'],
            ['The momentum of a body of mass 2 kg moving with velocity 5 m/s is:', 'A' => '2.5 kg m/s', 'B' => '5 kg m/s', 'C' => '10 kg m/s', 'D' => '0.4 kg m/s', 'ans' => 'C'],
            ['A force of 50 N is applied on a body of mass 10 kg at rest. The velocity after 5 s is:', 'A' => '10 m/s', 'B' => '25 m/s', 'C' => '50 m/s', 'D' => '250 m/s', 'ans' => 'B'],
            ['The action and reaction forces in Newton\'s third law act on:', 'A' => 'same body', 'B' => 'different bodies', 'C' => 'same direction', 'D' => 'perpendicular directions', 'ans' => 'B'],
        ];

        return $this->insertQuestions($institution, $admin, $subject, $chapter, $questions, 'neet');
    }

    private function createChemistryQuestions($institution, $admin, $subject, $chapter): array
    {
        $questions = [
            ['One mole of any substance contains how many atoms/molecules?', 'A' => '6.022 × 10²¹', 'B' => '6.022 × 10²³', 'C' => '6.022 × 10²⁴', 'D' => '6.022 × 10²²', 'ans' => 'B'],
            ['The mass of one mole of oxygen gas (O₂) is:', 'A' => '16 g', 'B' => '32 g', 'C' => '8 g', 'D' => '64 g', 'ans' => 'B'],
            ['The number of moles in 9 g of water is:', 'A' => '0.5 mol', 'B' => '1 mol', 'C' => '2 mol', 'D' => '9 mol', 'ans' => 'A'],
            ['Which of the following has the highest molar mass?', 'A' => 'H₂SO₄', 'B' => 'HCl', 'C' => 'NaCl', 'D' => 'KOH', 'ans' => 'A'],
            ['In the reaction 2H₂ + O₂ → 2H₂O, the ratio of moles of H₂ to O₂ consumed is:', 'A' => '1:1', 'B' => '1:2', 'C' => '2:1', 'D' => '4:1', 'ans' => 'C'],
            ['The empirical formula of glucose (C₆H₁₂O₆) is:', 'A' => 'C₆H₁₂O₆', 'B' => 'CH₂O', 'C' => 'C₂H₄O₂', 'D' => 'CHO₂', 'ans' => 'B'],
            ['What is the percentage of nitrogen in ammonia (NH₃)?', 'A' => '82.35%', 'B' => '17.65%', 'C' => '50%', 'D' => '75%', 'ans' => 'A'],
            ['The number of significant figures in 0.00340 is:', 'A' => '3', 'B' => '5', 'C' => '4', 'D' => '6', 'ans' => 'A'],
            ['Avogadro\'s number is approximately:', 'A' => '6.022 × 10²¹', 'B' => '6.022 × 10²³', 'C' => '3.011 × 10²³', 'D' => '12.044 × 10²²', 'ans' => 'B'],
            ['The law of conservation of mass states that:', 'A' => 'mass increases in a reaction', 'B' => 'mass decreases in a reaction', 'C' => 'total mass of reactants equals total mass of products', 'D' => 'mass is created during reaction', 'ans' => 'C'],
            ['How many molecules are present in 2 moles of CO₂?', 'A' => '6.022 × 10²³', 'B' => '12.044 × 10²³', 'C' => '3.011 × 10²³', 'D' => '1.806 × 10²⁴', 'ans' => 'B'],
            ['The limiting reagent is the one that:', 'A' => 'is present in excess', 'B' => 'determines the maximum product formed', 'C' => 'reacts faster', 'D' => 'has higher molecular mass', 'ans' => 'B'],
            ['The molarity of a solution containing 4 g of NaOH (M=40) in 500 mL is:', 'A' => '0.1 M', 'B' => '0.2 M', 'C' => '0.4 M', 'D' => '1 M', 'ans' => 'B'],
            ['1 amu is equal to:', 'A' => '1.66 × 10⁻²⁷ g', 'B' => '1.66 × 10⁻²⁴ g', 'C' => '1.66 × 10⁻²³ g', 'D' => '1.66 × 10⁻²² g', 'ans' => 'B'],
            ['Which law states that gases combine in simple ratios by volume at the same temperature and pressure?', 'A' => 'Dalton\'s Law', 'B' => 'Law of Multiple Proportions', 'C' => 'Gay-Lussac\'s Law of Combining Volumes', 'D' => 'Avogadro\'s Law', 'ans' => 'C'],
        ];

        return $this->insertQuestions($institution, $admin, $subject, $chapter, $questions, 'neet');
    }

    private function insertQuestions($institution, $admin, $subject, $chapter, array $data, string $examType): array
    {
        $created = [];
        foreach ($data as $qData) {
            $text = $qData[0];
            $existing = Question::where('institution_id', $institution->id)
                ->where('subject_id', $subject->id)
                ->whereRaw('SUBSTRING(question_text, 1, 80) = ?', [substr($text, 0, 80)])
                ->first();

            if ($existing) {
                $created[] = $existing;
                continue;
            }

            $created[] = Question::create([
                'institution_id' => $institution->id,
                'created_by'     => $admin->id,
                'exam_type'      => $examType,
                'subject_id'     => $subject->id,
                'chapter_id'     => $chapter->id,
                'difficulty'     => 'medium',
                'type'           => 'single_mcq',
                'question_text'  => $text,
                'options'        => [
                    'A' => $qData['A'],
                    'B' => $qData['B'],
                    'C' => $qData['C'],
                    'D' => $qData['D'],
                ],
                'correct_answer' => $qData['ans'],
                'positive_marks' => 4.00,
                'negative_marks' => 1.00,
                'has_latex'      => false,
                'has_image'      => false,
                'source_type'    => 'manual',
                'status'         => 'active',
            ]);
        }
        return $created;
    }
}
