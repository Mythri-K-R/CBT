<?php

namespace Database\Seeders;

use App\Models\Chapter;
use App\Models\Subject;
use Illuminate\Database\Seeder;

class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'neet' => [
                'Physics' => [
                    'Physical World and Measurement','Kinematics','Laws of Motion',
                    'Work, Energy and Power','Motion of System of Particles and Rigid Body',
                    'Gravitation','Properties of Bulk Matter','Thermodynamics',
                    'Behaviour of Perfect Gas and Kinetic Theory','Oscillations and Waves',
                    'Electrostatics','Current Electricity','Magnetic Effects of Current and Magnetism',
                    'Electromagnetic Induction and Alternating Currents','Electromagnetic Waves',
                    'Optics','Dual Nature of Matter and Radiation','Atoms and Nuclei',
                    'Electronic Devices',
                ],
                'Chemistry' => [
                    'Some Basic Concepts of Chemistry','Structure of Atom','Classification of Elements',
                    'Chemical Bonding and Molecular Structure','States of Matter','Thermodynamics',
                    'Equilibrium','Redox Reactions','Hydrogen','s-Block Elements',
                    'p-Block Elements','Organic Chemistry - Basic Principles','Hydrocarbons',
                    'Environmental Chemistry','Solid State','Solutions','Electrochemistry',
                    'Chemical Kinetics','Surface Chemistry','General Principles of Isolation',
                    'd and f Block Elements','Coordination Compounds','Haloalkanes and Haloarenes',
                    'Alcohols, Phenols and Ethers','Aldehydes, Ketones and Carboxylic Acids',
                    'Organic Compounds Containing Nitrogen','Biomolecules','Polymers','Chemistry in Everyday Life',
                ],
                'Biology' => [
                    'Diversity in Living World','Structural Organisation in Plants and Animals',
                    'Cell Structure and Function','Plant Physiology','Human Physiology',
                    'Reproduction','Genetics and Evolution','Biology and Human Welfare',
                    'Biotechnology and Its Applications','Ecology and Environment',
                ],
            ],
            'jee_main' => [
                'Physics' => [
                    'Units and Measurements','Kinematics','Laws of Motion',
                    'Work, Energy and Power','Rotational Motion','Gravitation',
                    'Properties of Solids and Liquids','Thermodynamics','Kinetic Theory of Gases',
                    'Oscillations','Waves','Electrostatics','Current Electricity',
                    'Magnetic Effects of Current','EMI and AC','Electromagnetic Waves',
                    'Optics','Modern Physics','Electronic Devices','Communication Systems',
                ],
                'Chemistry' => [
                    'Some Basic Concepts in Chemistry','Atomic Structure','Chemical Bonding',
                    'Chemical Equilibrium','Ionic Equilibrium','Thermodynamics','Thermochemistry',
                    'Redox Reactions and Electrochemistry','Chemical Kinetics','Surface Chemistry',
                    'Nuclear Chemistry','Classification of Elements','Hydrogen and s-block',
                    'p-block Elements','d and f-block Elements','Coordination Chemistry',
                    'Basic Principles of Organic Chemistry','Hydrocarbons',
                    'Organic Compounds with Functional Groups','Biomolecules','Polymers',
                ],
                'Mathematics' => [
                    'Sets, Relations and Functions','Complex Numbers','Matrices and Determinants',
                    'Permutations and Combinations','Binomial Theorem','Sequences and Series',
                    'Limits, Continuity and Differentiability','Integral Calculus',
                    'Differential Equations','Coordinate Geometry','Three Dimensional Geometry',
                    'Vector Algebra','Statistics and Probability','Trigonometry',
                    'Mathematical Reasoning',
                ],
            ],
        ];

        $order = 0;
        foreach ($data as $examType => $subjects) {
            foreach ($subjects as $subjectName => $chapters) {
                $subject = Subject::firstOrCreate(
                    ['exam_type' => $examType, 'name' => $subjectName, 'institution_id' => null],
                    ['code' => strtoupper(substr($subjectName, 0, 3)), 'display_order' => $order++, 'is_active' => true]
                );

                foreach ($chapters as $chapterOrder => $chapterName) {
                    Chapter::firstOrCreate(
                        ['subject_id' => $subject->id, 'name' => $chapterName],
                        ['display_order' => $chapterOrder, 'is_active' => true]
                    );
                }

                $this->command->info("Seeded: {$examType} > {$subjectName} (".count($chapters)." chapters)");
            }
        }
    }
}
