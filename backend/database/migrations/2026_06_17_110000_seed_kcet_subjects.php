<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Subject;
use App\Models\Chapter;

return new class extends Migration
{
    public function up(): void
    {
        $subjects = [
            'Physics' => [
                'Physical World and Measurement','Kinematics','Laws of Motion',
                'Work, Energy and Power','Motion of System of Particles and Rigid Body',
                'Gravitation','Mechanical Properties of Solids','Mechanical Properties of Fluids',
                'Thermal Properties of Matter','Thermodynamics','Kinetic Theory',
                'Oscillations','Waves','Electric Charges and Fields',
                'Electrostatic Potential and Capacitance','Current Electricity',
                'Moving Charges and Magnetism','Magnetism and Matter',
                'Electromagnetic Induction','Alternating Current','Electromagnetic Waves',
                'Ray Optics and Optical Instruments','Wave Optics',
                'Dual Nature of Radiation and Matter','Atoms','Nuclei',
                'Semiconductor Electronics',
            ],
            'Chemistry' => [
                'Some Basic Concepts of Chemistry','Structure of Atom',
                'Classification of Elements and Periodicity','Chemical Bonding and Molecular Structure',
                'States of Matter','Thermodynamics','Equilibrium','Redox Reactions',
                'Hydrogen','The s-Block Elements','The p-Block Elements',
                'Organic Chemistry - Basic Principles','Hydrocarbons','Environmental Chemistry',
                'The Solid State','Solutions','Electrochemistry','Chemical Kinetics',
                'Surface Chemistry','General Principles of Extraction',
                'The d- and f-Block Elements','Coordination Compounds',
                'Haloalkanes and Haloarenes','Alcohols, Phenols and Ethers',
                'Aldehydes, Ketones and Carboxylic Acids','Amines',
                'Biomolecules','Polymers','Chemistry in Everyday Life',
            ],
            'Mathematics' => [
                'Relations and Functions','Inverse Trigonometric Functions',
                'Matrices','Determinants','Continuity and Differentiability',
                'Applications of Derivatives','Integrals','Applications of Integrals',
                'Differential Equations','Vector Algebra','Three Dimensional Geometry',
                'Linear Programming','Probability','Complex Numbers and Quadratic Equations',
                'Linear Inequalities','Permutations and Combinations','Binomial Theorem',
                'Sequences and Series','Straight Lines','Conic Sections',
                'Trigonometric Functions','Statistics','Mathematical Reasoning',
            ],
            'Biology' => [
                'Diversity in Living World','Structural Organisation in Plants and Animals',
                'Cell Structure and Function','Plant Physiology','Human Physiology',
                'Reproduction','Genetics and Evolution','Biology and Human Welfare',
                'Biotechnology and Its Applications','Ecology and Environment',
            ],
        ];

        $order = 100;
        foreach ($subjects as $name => $chapters) {
            $subject = Subject::firstOrCreate(
                ['exam_type' => 'kcet', 'name' => $name, 'institution_id' => null],
                ['code' => strtoupper(substr($name, 0, 3)), 'display_order' => $order++, 'is_active' => true]
            );

            foreach ($chapters as $chapterOrder => $chapterName) {
                Chapter::firstOrCreate(
                    ['subject_id' => $subject->id, 'name' => $chapterName],
                    ['display_order' => $chapterOrder, 'is_active' => true]
                );
            }
        }
    }

    public function down(): void
    {
        $ids = Subject::where('exam_type', 'kcet')->whereNull('institution_id')->pluck('id');
        Chapter::whereIn('subject_id', $ids)->delete();
        Subject::whereIn('id', $ids)->delete();
    }
};
