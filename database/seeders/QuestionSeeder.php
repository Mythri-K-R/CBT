<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Chapter;
use App\Models\User;
use Illuminate\Support\Str;

class QuestionSeeder extends Seeder
{
    /**
     * Real chapter-wise questions for NEET, JEE Main, and KCET.
     * Keyed as: exam_type => subject_name => chapter_name => [ questions ]
     * Each question: [ text, options[A-D], correct, explanation, difficulty, type ]
     */
    private array $questionBank = [

        // =====================================================================
        // NEET — PHYSICS
        // =====================================================================
        'neet' => [
            'Physics' => [
                'Kinematics' => [
                    [
                        'q' => 'A car accelerates uniformly from rest to 20 m/s in 10 seconds. What is the distance covered during this time?',
                        'opts' => ['A' => '100 m', 'B' => '200 m', 'C' => '150 m', 'D' => '50 m'],
                        'ans' => 'A',
                        'exp' => 'Using s = ut + ½at². u=0, a=2 m/s², t=10s. s = 0 + ½×2×100 = 100 m.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'A body is thrown vertically upward with a velocity of 49 m/s. The maximum height reached is (g = 9.8 m/s²):',
                        'opts' => ['A' => '122.5 m', 'B' => '100 m', 'C' => '245 m', 'D' => '49 m'],
                        'ans' => 'A',
                        'exp' => 'Using v² = u² - 2gh. At max height v=0. h = u²/2g = (49)²/(2×9.8) = 2401/19.6 = 122.5 m.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'A projectile is fired at 30° to the horizontal with a speed of 40 m/s. Its range on a horizontal plane is (g = 10 m/s²):',
                        'opts' => ['A' => '80√3 m', 'B' => '160 m', 'C' => '80 m', 'D' => '40√3 m'],
                        'ans' => 'A',
                        'exp' => 'Range R = u²sin2θ/g = (1600 × sin60°)/10 = 1600 × (√3/2)/10 = 80√3 m.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'Which of the following is a vector quantity?',
                        'opts' => ['A' => 'Speed', 'B' => 'Distance', 'C' => 'Displacement', 'D' => 'Mass'],
                        'ans' => 'C',
                        'exp' => 'Displacement has both magnitude and direction, so it is a vector quantity. Speed, distance and mass are scalars.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'A stone is dropped from a height of 80 m. The time taken to reach the ground is (g = 10 m/s²):',
                        'opts' => ['A' => '4 s', 'B' => '2 s', 'C' => '8 s', 'D' => '√8 s'],
                        'ans' => 'A',
                        'exp' => 'Using h = ½gt². 80 = ½×10×t². t² = 16. t = 4 s.',
                        'diff' => 'easy',
                    ],
                ],
                'Laws of Motion' => [
                    [
                        'q' => 'A 5 kg block is placed on a frictionless surface. A force of 20 N is applied horizontally. The acceleration of the block is:',
                        'opts' => ['A' => '4 m/s²', 'B' => '2 m/s²', 'C' => '10 m/s²', 'D' => '100 m/s²'],
                        'ans' => 'A',
                        'exp' => 'By Newton\'s second law: F = ma → a = F/m = 20/5 = 4 m/s².',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'A body of mass 2 kg is moving with a velocity of 10 m/s. A force acts on it for 5 seconds reducing its velocity to zero. The force applied is:',
                        'opts' => ['A' => '-4 N', 'B' => '4 N', 'C' => '-10 N', 'D' => '20 N'],
                        'ans' => 'A',
                        'exp' => 'a = (v-u)/t = (0-10)/5 = -2 m/s². F = ma = 2×(-2) = -4 N. Negative means retarding force.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'Newton\'s first law of motion defines:',
                        'opts' => ['A' => 'Force', 'B' => 'Inertia', 'C' => 'Momentum', 'D' => 'Energy'],
                        'ans' => 'B',
                        'exp' => 'Newton\'s first law states that a body continues in its state of rest or uniform motion unless acted upon by a net external force. This property is called Inertia.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'A cricket ball of mass 0.15 kg moving at 20 m/s is brought to rest in 0.05 s. The average force exerted on the ball is:',
                        'opts' => ['A' => '60 N', 'B' => '30 N', 'C' => '6 N', 'D' => '150 N'],
                        'ans' => 'A',
                        'exp' => 'F = Δp/t = m(v-u)/t = 0.15×(0-20)/0.05 = 0.15×(-400) = -60 N. Magnitude = 60 N.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'A gun fires a bullet. The backward recoil of the gun is an example of Newton\'s:',
                        'opts' => ['A' => 'First law', 'B' => 'Second law', 'C' => 'Third law', 'D' => 'Law of gravitation'],
                        'ans' => 'C',
                        'exp' => 'The bullet exerts a forward action force on the gun; the gun exerts an equal and opposite reaction — Newton\'s Third Law (action-reaction).',
                        'diff' => 'easy',
                    ],
                ],
                'Electrostatics' => [
                    [
                        'q' => 'The force between two point charges of 1 μC each separated by 1 m in vacuum is (K = 9×10⁹ N·m²/C²):',
                        'opts' => ['A' => '9×10⁻³ N', 'B' => '9×10⁻⁶ N', 'C' => '9×10³ N', 'D' => '9 N'],
                        'ans' => 'A',
                        'exp' => 'F = Kq₁q₂/r² = 9×10⁹ × (10⁻⁶)² / 1² = 9×10⁹ × 10⁻¹² = 9×10⁻³ N.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'Electric field inside a hollow conducting sphere is:',
                        'opts' => ['A' => 'Zero', 'B' => 'Maximum at centre', 'C' => 'Same as outside', 'D' => 'Proportional to radius'],
                        'ans' => 'A',
                        'exp' => 'By Gauss\'s law, the electric field inside a conductor (including hollow regions) is zero because all charges reside on the outer surface.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'Dielectric constant of a medium is always:',
                        'opts' => ['A' => 'Less than 1', 'B' => 'Equal to 1', 'C' => 'Greater than or equal to 1', 'D' => 'Less than or equal to 0'],
                        'ans' => 'C',
                        'exp' => 'Dielectric constant K = ε/ε₀ ≥ 1. For vacuum K = 1; for all other materials K > 1.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'Two capacitors of 4 μF and 6 μF are connected in series. Their effective capacitance is:',
                        'opts' => ['A' => '2.4 μF', 'B' => '10 μF', 'C' => '1.5 μF', 'D' => '24 μF'],
                        'ans' => 'A',
                        'exp' => '1/C = 1/4 + 1/6 = 3/12 + 2/12 = 5/12. C = 12/5 = 2.4 μF.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'Work done in moving a charge of 3 C through a potential difference of 12 V is:',
                        'opts' => ['A' => '36 J', 'B' => '4 J', 'C' => '12 J', 'D' => '3 J'],
                        'ans' => 'A',
                        'exp' => 'W = QV = 3 × 12 = 36 J.',
                        'diff' => 'easy',
                    ],
                ],
                'Optics' => [
                    [
                        'q' => 'A concave mirror has a focal length of 20 cm. An object is placed 40 cm in front of it. The image formed is:',
                        'opts' => ['A' => 'At 40 cm, real, inverted, same size', 'B' => 'At infinity', 'C' => 'At 20 cm, virtual', 'D' => 'At 60 cm, real'],
                        'ans' => 'A',
                        'exp' => '1/v + 1/u = 1/f. u = -40, f = -20. 1/v = 1/(-20) - 1/(-40) = -1/40. v = -40 cm. Real, inverted, same size.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'The refractive index of glass with respect to air is 1.5. The critical angle for total internal reflection is:',
                        'opts' => ['A' => 'sin⁻¹(2/3)', 'B' => 'sin⁻¹(3/2)', 'C' => 'cos⁻¹(2/3)', 'D' => '45°'],
                        'ans' => 'A',
                        'exp' => 'sin(C) = 1/μ = 1/1.5 = 2/3. So C = sin⁻¹(2/3) ≈ 41.8°.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'Which colour of white light has the highest frequency?',
                        'opts' => ['A' => 'Red', 'B' => 'Yellow', 'C' => 'Green', 'D' => 'Violet'],
                        'ans' => 'D',
                        'exp' => 'Violet light has the highest frequency (~7.5×10¹⁴ Hz) and shortest wavelength in the visible spectrum.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'In Young\'s double slit experiment, the fringe width is proportional to:',
                        'opts' => ['A' => 'Distance between slits', 'B' => 'Wavelength of light', 'C' => 'Square of distance to screen', 'D' => 'Square of wavelength'],
                        'ans' => 'B',
                        'exp' => 'Fringe width β = λD/d. β ∝ λ (directly proportional to wavelength). Also proportional to D and inversely to d.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'A plano-convex lens made of glass (μ=1.5) has a radius of curvature of 20 cm. Its focal length is:',
                        'opts' => ['A' => '40 cm', 'B' => '20 cm', 'C' => '10 cm', 'D' => '80 cm'],
                        'ans' => 'A',
                        'exp' => 'Lensmaker\'s eq: 1/f = (μ-1)[1/R₁ - 1/R₂]. For plano-convex: R₁=20, R₂=∞. 1/f = 0.5×(1/20) = 1/40. f = 40 cm.',
                        'diff' => 'hard',
                    ],
                ],

                // ---- NEET Chemistry ----
            ],
            'Chemistry' => [
                'Some Basic Concepts of Chemistry' => [
                    [
                        'q' => 'The number of atoms in 1 mole of hydrogen gas (H₂) is:',
                        'opts' => ['A' => '6.022×10²³', 'B' => '12.044×10²³', 'C' => '3.011×10²³', 'D' => '1'],
                        'ans' => 'B',
                        'exp' => 'H₂ is diatomic. 1 mole of H₂ = 2 × 6.022×10²³ = 12.044×10²³ atoms.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'Molarity of a solution containing 4 g of NaOH (mol. wt. 40) in 500 mL solution is:',
                        'opts' => ['A' => '0.2 M', 'B' => '0.4 M', 'C' => '2 M', 'D' => '1 M'],
                        'ans' => 'A',
                        'exp' => 'Moles of NaOH = 4/40 = 0.1 mol. Volume = 0.5 L. Molarity = 0.1/0.5 = 0.2 M.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'Which of the following has the highest molar mass?',
                        'opts' => ['A' => 'CO₂', 'B' => 'SO₂', 'C' => 'NO₂', 'D' => 'H₂O'],
                        'ans' => 'B',
                        'exp' => 'Molar masses: CO₂=44, SO₂=64, NO₂=46, H₂O=18. SO₂ has the highest molar mass.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'The empirical formula of glucose (C₆H₁₂O₆) is:',
                        'opts' => ['A' => 'CH₂O', 'B' => 'C₂H₄O₂', 'C' => 'C₃H₆O₃', 'D' => 'CHO'],
                        'ans' => 'A',
                        'exp' => 'Dividing all subscripts by 6: C₁H₂O₁ = CH₂O. This is the simplest whole number ratio.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'The limiting reagent in a reaction is the one that:',
                        'opts' => ['A' => 'Is in excess', 'B' => 'Determines the amount of product formed', 'C' => 'Has the highest molar mass', 'D' => 'React last'],
                        'ans' => 'B',
                        'exp' => 'The limiting reagent is consumed first and determines the theoretical yield of products.',
                        'diff' => 'easy',
                    ],
                ],
                'Chemical Bonding and Molecular Structure' => [
                    [
                        'q' => 'The shape of H₂O molecule is:',
                        'opts' => ['A' => 'Linear', 'B' => 'Tetrahedral', 'C' => 'Bent (V-shaped)', 'D' => 'Trigonal planar'],
                        'ans' => 'C',
                        'exp' => 'O in H₂O has 2 bond pairs and 2 lone pairs. VSEPR gives bent/V-shaped geometry with bond angle ~104.5°.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'In which of the following molecules does the central atom have sp³ hybridisation?',
                        'opts' => ['A' => 'BeCl₂', 'B' => 'BF₃', 'C' => 'NH₃', 'D' => 'CO₂'],
                        'ans' => 'C',
                        'exp' => 'N in NH₃ has 3 bond pairs and 1 lone pair → 4 electron pairs → sp³ hybridisation, pyramidal shape.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'The bond order of O₂ molecule is:',
                        'opts' => ['A' => '1', 'B' => '2', 'C' => '3', 'D' => '1.5'],
                        'ans' => 'B',
                        'exp' => 'O₂: (σ1s)²(σ*1s)²(σ2s)²(σ*2s)²(σ2p)²(π2p)⁴(π*2p)². Bond order = (10-6)/2 = 2.',
                        'diff' => 'hard',
                    ],
                    [
                        'q' => 'Which type of bond is present in NaCl?',
                        'opts' => ['A' => 'Covalent', 'B' => 'Ionic', 'C' => 'Metallic', 'D' => 'Hydrogen bond'],
                        'ans' => 'B',
                        'exp' => 'NaCl is formed by transfer of electron from Na to Cl. Na⁺ and Cl⁻ are held by electrostatic (ionic) bonds.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'The geometry of PCl₅ is:',
                        'opts' => ['A' => 'Octahedral', 'B' => 'Square planar', 'C' => 'Trigonal bipyramidal', 'D' => 'Tetrahedral'],
                        'ans' => 'C',
                        'exp' => 'PCl₅: P undergoes sp³d hybridisation. 5 bond pairs and 0 lone pairs → trigonal bipyramidal geometry.',
                        'diff' => 'medium',
                    ],
                ],
                'Electrochemistry' => [
                    [
                        'q' => 'The standard EMF of Daniell cell (Zn-Cu) is approximately:',
                        'opts' => ['A' => '1.10 V', 'B' => '0.76 V', 'C' => '0.34 V', 'D' => '1.50 V'],
                        'ans' => 'A',
                        'exp' => 'E°cell = E°cathode - E°anode = E°(Cu²⁺/Cu) - E°(Zn²⁺/Zn) = 0.34 - (-0.76) = 1.10 V.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'According to Faraday\'s first law, the mass of substance deposited is proportional to:',
                        'opts' => ['A' => 'Square of charge passed', 'B' => 'Charge passed', 'C' => 'Voltage applied', 'D' => 'Temperature'],
                        'ans' => 'B',
                        'exp' => 'Faraday\'s first law: m = ZIt = ZQ. Mass is directly proportional to the quantity of charge (Q) passed.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'During electrolysis of water, the gas evolved at the cathode is:',
                        'opts' => ['A' => 'Oxygen', 'B' => 'Hydrogen', 'C' => 'Ozone', 'D' => 'A mixture of H₂ and O₂'],
                        'ans' => 'B',
                        'exp' => 'At cathode (reduction): 2H₂O + 2e⁻ → H₂ + 2OH⁻. Hydrogen gas is evolved at the cathode.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'Conductance of an electrolyte solution increases with:',
                        'opts' => ['A' => 'Increase in concentration', 'B' => 'Decrease in temperature', 'C' => 'Dilution', 'D' => 'Addition of sugar'],
                        'ans' => 'C',
                        'exp' => 'On dilution, the degree of dissociation increases and more ions are available, increasing molar conductance.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'The Nernst equation is used to calculate the:',
                        'opts' => ['A' => 'Rate of reaction', 'B' => 'Equilibrium constant', 'C' => 'EMF of a cell under non-standard conditions', 'D' => 'Activation energy'],
                        'ans' => 'C',
                        'exp' => 'Nernst equation: E = E° - (RT/nF)lnQ relates cell EMF to standard EMF and ionic activities.',
                        'diff' => 'medium',
                    ],
                ],
            ],

            // ---- NEET Biology ----
            'Biology' => [
                'Cell Structure and Function' => [
                    [
                        'q' => 'The powerhouse of the cell is:',
                        'opts' => ['A' => 'Nucleus', 'B' => 'Ribosome', 'C' => 'Mitochondria', 'D' => 'Chloroplast'],
                        'ans' => 'C',
                        'exp' => 'Mitochondria produce ATP through cellular respiration (oxidative phosphorylation), earning the title "powerhouse of the cell".',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'Which organelle is responsible for protein synthesis?',
                        'opts' => ['A' => 'Golgi apparatus', 'B' => 'Ribosome', 'C' => 'Lysosome', 'D' => 'Vacuole'],
                        'ans' => 'B',
                        'exp' => 'Ribosomes (found free in cytoplasm or on rough ER) are the site of translation — protein synthesis.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'The fluid mosaic model of cell membrane was proposed by:',
                        'opts' => ['A' => 'Watson and Crick', 'B' => 'Singer and Nicolson', 'C' => 'Schleiden and Schwann', 'D' => 'Virchow'],
                        'ans' => 'B',
                        'exp' => 'Singer and Nicolson (1972) proposed the fluid mosaic model — a phospholipid bilayer with embedded proteins.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'Lysosomes are rich in:',
                        'opts' => ['A' => 'Digestive enzymes', 'B' => 'Ribozymes', 'C' => 'DNA', 'D' => 'Chlorophyll'],
                        'ans' => 'A',
                        'exp' => 'Lysosomes contain hydrolytic enzymes (acid hydrolases) that digest macromolecules, worn-out organelles, and foreign materials.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'The cell wall in plant cells is mainly composed of:',
                        'opts' => ['A' => 'Chitin', 'B' => 'Cellulose', 'C' => 'Peptidoglycan', 'D' => 'Lignin only'],
                        'ans' => 'B',
                        'exp' => 'Plant cell walls are primarily composed of cellulose (a polysaccharide of β-glucose units). Chitin is found in fungi; peptidoglycan in bacteria.',
                        'diff' => 'easy',
                    ],
                ],
                'Human Physiology' => [
                    [
                        'q' => 'The normal range of blood pH in humans is:',
                        'opts' => ['A' => '6.8 – 7.0', 'B' => '7.35 – 7.45', 'C' => '7.8 – 8.0', 'D' => '7.0 – 7.2'],
                        'ans' => 'B',
                        'exp' => 'Human blood is maintained at a slightly alkaline pH of 7.35–7.45. Deviation causes acidosis or alkalosis.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'The oxygen-carrying pigment in human RBCs is:',
                        'opts' => ['A' => 'Myoglobin', 'B' => 'Haemocyanin', 'C' => 'Haemoglobin', 'D' => 'Chlorophyll'],
                        'ans' => 'C',
                        'exp' => 'Haemoglobin in RBCs binds O₂ in the lungs and releases it in tissues. Each Hb carries 4 O₂ molecules.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'The largest gland in the human body is:',
                        'opts' => ['A' => 'Pancreas', 'B' => 'Thyroid', 'C' => 'Liver', 'D' => 'Adrenal'],
                        'ans' => 'C',
                        'exp' => 'The liver is the largest gland and the largest internal organ, weighing about 1.5 kg in an adult.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'Which part of the brain controls breathing?',
                        'opts' => ['A' => 'Cerebrum', 'B' => 'Cerebellum', 'C' => 'Medulla oblongata', 'D' => 'Thalamus'],
                        'ans' => 'C',
                        'exp' => 'The medulla oblongata (brainstem) contains the respiratory centre that controls the rate and depth of breathing.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'Insulin is secreted by the _____ cells of the pancreatic islets:',
                        'opts' => ['A' => 'Alpha (α)', 'B' => 'Beta (β)', 'C' => 'Delta (δ)', 'D' => 'F cells'],
                        'ans' => 'B',
                        'exp' => 'Beta (β) cells of the Islets of Langerhans secrete insulin, which lowers blood glucose levels.',
                        'diff' => 'medium',
                    ],
                ],
                'Genetics and Evolution' => [
                    [
                        'q' => 'The DNA double helix model was proposed by:',
                        'opts' => ['A' => 'Mendel and Morgan', 'B' => 'Watson and Crick', 'C' => 'Darwin and Wallace', 'D' => 'Hershey and Chase'],
                        'ans' => 'B',
                        'exp' => 'James Watson and Francis Crick proposed the double helix structure of DNA in 1953, based on X-ray data by Rosalind Franklin.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'If a man with blood group A (IAi) marries a woman with blood group B (IBi), the probability of their child having blood group O is:',
                        'opts' => ['A' => '25%', 'B' => '50%', 'C' => '0%', 'D' => '75%'],
                        'ans' => 'A',
                        'exp' => 'Cross IAi × IBi gives offspring: IAIB (AB), IAi (A), IBi (B), ii (O) in 1:1:1:1 ratio. P(O) = 1/4 = 25%.',
                        'diff' => 'hard',
                    ],
                    [
                        'q' => 'Mendel\'s law of independent assortment applies to genes located on:',
                        'opts' => ['A' => 'Same chromosome', 'B' => 'Different (non-homologous) chromosomes', 'C' => 'X chromosome only', 'D' => 'Mitochondrial DNA'],
                        'ans' => 'B',
                        'exp' => 'Independent assortment occurs for genes on different chromosomes. Linked genes (same chromosome) do not assort independently.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'The process by which mRNA is decoded to produce a protein is called:',
                        'opts' => ['A' => 'Transcription', 'B' => 'Replication', 'C' => 'Translation', 'D' => 'Transduction'],
                        'ans' => 'C',
                        'exp' => 'Translation is the process of reading mRNA codons and assembling amino acids into a polypeptide at the ribosome.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'Natural selection was proposed by:',
                        'opts' => ['A' => 'Lamarck', 'B' => 'Mendel', 'C' => 'Darwin', 'D' => 'Hugo de Vries'],
                        'ans' => 'C',
                        'exp' => 'Charles Darwin proposed the theory of evolution by Natural Selection in "On the Origin of Species" (1859).',
                        'diff' => 'easy',
                    ],
                ],
            ],
        ],

        // =====================================================================
        // JEE MAIN — MATHEMATICS
        // =====================================================================
        'jee_main' => [
            'Mathematics' => [
                'Limits, Continuity and Differentiability' => [
                    [
                        'q' => 'The value of lim(x→0) [sin x / x] is:',
                        'opts' => ['A' => '0', 'B' => '∞', 'C' => '1', 'D' => 'Does not exist'],
                        'ans' => 'C',
                        'exp' => 'The standard limit lim(x→0) (sin x)/x = 1 (x in radians). This is a fundamental result in calculus.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'If f(x) = x², then f\'(3) is:',
                        'opts' => ['A' => '3', 'B' => '9', 'C' => '6', 'D' => '12'],
                        'ans' => 'C',
                        'exp' => 'f\'(x) = 2x (power rule). f\'(3) = 2×3 = 6.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'The function f(x) = |x| is:',
                        'opts' => ['A' => 'Differentiable everywhere', 'B' => 'Differentiable everywhere except x=0', 'C' => 'Not continuous at x=0', 'D' => 'Discontinuous at x=1'],
                        'ans' => 'B',
                        'exp' => '|x| is continuous everywhere but not differentiable at x=0 because the left derivative (-1) ≠ right derivative (+1).',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'The derivative of sin(x²) with respect to x is:',
                        'opts' => ['A' => 'cos(x²)', 'B' => '2x cos(x²)', 'C' => '2x sin(x²)', 'D' => 'cos(2x)'],
                        'ans' => 'B',
                        'exp' => 'Using chain rule: d/dx[sin(x²)] = cos(x²) × 2x = 2x cos(x²).',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'lim(x→∞) (1 + 1/x)^x equals:',
                        'opts' => ['A' => '1', 'B' => 'e', 'C' => 'e²', 'D' => '∞'],
                        'ans' => 'B',
                        'exp' => 'This is the definition of Euler\'s number: lim(x→∞)(1+1/x)^x = e ≈ 2.718.',
                        'diff' => 'medium',
                    ],
                ],
                'Integral Calculus' => [
                    [
                        'q' => '∫sin(x) dx equals:',
                        'opts' => ['A' => 'cos(x) + C', 'B' => '-cos(x) + C', 'C' => 'sin(x) + C', 'D' => '-sin(x) + C'],
                        'ans' => 'B',
                        'exp' => 'The integral of sin(x) is -cos(x) + C. Verify by differentiating: d/dx(-cos x) = sin x. ✓',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => '∫₀¹ x² dx equals:',
                        'opts' => ['A' => '1/2', 'B' => '1/3', 'C' => '1', 'D' => '2/3'],
                        'ans' => 'B',
                        'exp' => '∫₀¹ x² dx = [x³/3]₀¹ = 1/3 - 0 = 1/3.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'The area bounded by y = x², x-axis, x=0 and x=3 is:',
                        'opts' => ['A' => '3', 'B' => '6', 'C' => '9', 'D' => '27'],
                        'ans' => 'C',
                        'exp' => 'Area = ∫₀³ x² dx = [x³/3]₀³ = 27/3 = 9 sq units.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => '∫e^x dx equals:',
                        'opts' => ['A' => 'e^x / x + C', 'B' => 'x e^x + C', 'C' => 'e^x + C', 'D' => 'e^(x+1) + C'],
                        'ans' => 'C',
                        'exp' => 'The integral of e^x is e^x + C (unique property of the exponential function).',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => '∫(2x + 3) dx equals:',
                        'opts' => ['A' => 'x² + 3x + C', 'B' => '2 + C', 'C' => 'x² + C', 'D' => '2x² + 3x + C'],
                        'ans' => 'A',
                        'exp' => '∫(2x+3)dx = 2(x²/2) + 3x + C = x² + 3x + C.',
                        'diff' => 'easy',
                    ],
                ],
                'Coordinate Geometry' => [
                    [
                        'q' => 'The distance between points (3, 4) and (0, 0) is:',
                        'opts' => ['A' => '7', 'B' => '5', 'C' => '25', 'D' => '√7'],
                        'ans' => 'B',
                        'exp' => 'd = √((3-0)² + (4-0)²) = √(9+16) = √25 = 5.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'The equation of a circle with centre (0,0) and radius 5 is:',
                        'opts' => ['A' => 'x + y = 5', 'B' => 'x² + y² = 5', 'C' => 'x² + y² = 25', 'D' => '(x+5)² + y² = 25'],
                        'ans' => 'C',
                        'exp' => 'Standard form: (x-h)² + (y-k)² = r². With h=k=0, r=5: x² + y² = 25.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'The slope of a line parallel to the x-axis is:',
                        'opts' => ['A' => 'Undefined', 'B' => '1', 'C' => '0', 'D' => '∞'],
                        'ans' => 'C',
                        'exp' => 'A line parallel to x-axis is horizontal. Horizontal lines have slope = 0 (no rise over run).',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'The midpoint of segment joining (2, 4) and (6, 8) is:',
                        'opts' => ['A' => '(4, 6)', 'B' => '(3, 5)', 'C' => '(8, 12)', 'D' => '(2, 4)'],
                        'ans' => 'A',
                        'exp' => 'Midpoint = ((x₁+x₂)/2, (y₁+y₂)/2) = ((2+6)/2, (4+8)/2) = (4, 6).',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'The eccentricity of a circle is:',
                        'opts' => ['A' => '1', 'B' => '0', 'C' => '> 1', 'D' => '< 0'],
                        'ans' => 'B',
                        'exp' => 'For a circle, e = 0. For an ellipse 0<e<1, parabola e=1, hyperbola e>1.',
                        'diff' => 'easy',
                    ],
                ],
                'Permutations and Combinations' => [
                    [
                        'q' => 'The number of ways to arrange 4 books on a shelf is:',
                        'opts' => ['A' => '4', 'B' => '16', 'C' => '24', 'D' => '256'],
                        'ans' => 'C',
                        'exp' => '4! = 4 × 3 × 2 × 1 = 24 ways.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'ⁿCᵣ when n=5, r=2 is:',
                        'opts' => ['A' => '10', 'B' => '20', 'C' => '5', 'D' => '120'],
                        'ans' => 'A',
                        'exp' => '⁵C₂ = 5!/(2! × 3!) = (5×4)/(2×1) = 10.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'In how many ways can a committee of 3 be selected from 6 people?',
                        'opts' => ['A' => '6', 'B' => '20', 'C' => '120', 'D' => '18'],
                        'ans' => 'B',
                        'exp' => '⁶C₃ = 6!/(3!×3!) = 720/(6×6) = 20.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'The number of 3-digit numbers formed using digits 1, 2, 3 without repetition is:',
                        'opts' => ['A' => '3', 'B' => '6', 'C' => '9', 'D' => '27'],
                        'ans' => 'B',
                        'exp' => 'Number of arrangements = ³P₃ = 3! = 6.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'ⁿC₀ + ⁿC₁ + ⁿC₂ + ... + ⁿCₙ equals:',
                        'opts' => ['A' => 'n', 'B' => 'n²', 'C' => '2ⁿ', 'D' => 'n!'],
                        'ans' => 'C',
                        'exp' => 'By Binomial theorem: (1+1)ⁿ = 2ⁿ = sum of all ⁿCᵣ from r=0 to n.',
                        'diff' => 'medium',
                    ],
                ],
            ],

            // JEE Physics (reuse some + add unique)
            'Physics' => [
                'Rotational Motion' => [
                    [
                        'q' => 'A solid sphere of mass M and radius R has moment of inertia about its diameter:',
                        'opts' => ['A' => 'MR²', 'B' => '½MR²', 'C' => '⅔MR²', 'D' => '⅖MR²'],
                        'ans' => 'D',
                        'exp' => 'I = (2/5)MR² for a solid sphere about a diameter (standard formula).',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'Torque is defined as:',
                        'opts' => ['A' => 'Force × velocity', 'B' => 'Force × displacement', 'C' => 'r × F (cross product)', 'D' => 'Mass × angular velocity'],
                        'ans' => 'C',
                        'exp' => 'Torque τ = r × F (vector cross product of position vector and force). |τ| = rF sinθ.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'When a spinning skater pulls in her arms, her angular velocity:',
                        'opts' => ['A' => 'Decreases', 'B' => 'Increases', 'C' => 'Remains same', 'D' => 'Becomes zero'],
                        'ans' => 'B',
                        'exp' => 'Angular momentum L = Iω is conserved. Pulling arms in reduces I, so ω must increase.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'The angular momentum of a planet around the sun is conserved because:',
                        'opts' => ['A' => 'Net torque on it is zero', 'B' => 'Net force is zero', 'C' => 'Speed is constant', 'D' => 'Mass is constant'],
                        'ans' => 'A',
                        'exp' => 'Gravitational force is central (directed toward sun through planet). So torque = r × F = 0 → L = constant.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'A wheel rotates at 300 rpm. Its angular velocity in rad/s is:',
                        'opts' => ['A' => '5π', 'B' => '10π', 'C' => '300π', 'D' => '150'],
                        'ans' => 'B',
                        'exp' => 'ω = 2πn/60 = 2π×300/60 = 2π×5 = 10π rad/s.',
                        'diff' => 'medium',
                    ],
                ],
                'Modern Physics' => [
                    [
                        'q' => 'The photoelectric effect can be explained by treating light as:',
                        'opts' => ['A' => 'Waves', 'B' => 'Photons (quanta)', 'C' => 'Electrons', 'D' => 'Magnetic field'],
                        'ans' => 'B',
                        'exp' => 'Einstein explained photoelectric effect by treating light as discrete packets of energy (photons): E = hf.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'The de Broglie wavelength of a particle is given by λ = h/p. For a particle with doubled momentum, the wavelength becomes:',
                        'opts' => ['A' => 'Double', 'B' => 'Half', 'C' => 'Same', 'D' => 'Four times'],
                        'ans' => 'B',
                        'exp' => 'λ = h/p. If p doubles, λ = h/(2p) = λ/2. The wavelength becomes half.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'In a nuclear reaction: ²³⁵U + ¹n → ¹⁴¹Ba + ⁹²Kr + x¹n. The value of x is:',
                        'opts' => ['A' => '1', 'B' => '2', 'C' => '3', 'D' => '4'],
                        'ans' => 'C',
                        'exp' => 'Mass number: 235+1 = 141+92+x → 236 = 233+x → x=3. Three neutrons are released.',
                        'diff' => 'hard',
                    ],
                    [
                        'q' => 'Half-life of a radioactive substance is 20 years. After 60 years, fraction remaining is:',
                        'opts' => ['A' => '1/2', 'B' => '1/4', 'C' => '1/6', 'D' => '1/8'],
                        'ans' => 'D',
                        'exp' => 'Number of half-lives = 60/20 = 3. Fraction remaining = (1/2)³ = 1/8.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'Hydrogen atom emits light in Balmer series when electron transitions to:',
                        'opts' => ['A' => 'n=1 from higher levels', 'B' => 'n=2 from higher levels', 'C' => 'n=3 from higher levels', 'D' => 'n=4 from higher levels'],
                        'ans' => 'B',
                        'exp' => 'Balmer series: transitions to n=2 from n>2. These are visible light emissions (λ: 380–700 nm).',
                        'diff' => 'medium',
                    ],
                ],
                'Waves' => [
                    [
                        'q' => 'The speed of sound in air at 0°C is approximately:',
                        'opts' => ['A' => '220 m/s', 'B' => '332 m/s', 'C' => '3×10⁸ m/s', 'D' => '1500 m/s'],
                        'ans' => 'B',
                        'exp' => 'Speed of sound in air at 0°C ≈ 332 m/s. It increases with temperature (~0.6 m/s per °C).',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'The phenomenon of beats occurs due to:',
                        'opts' => ['A' => 'Diffraction of sound', 'B' => 'Interference of two waves of slightly different frequencies', 'C' => 'Resonance', 'D' => 'Reflection'],
                        'ans' => 'B',
                        'exp' => 'Beats are alternating loud and soft sounds produced by superposition of two sound waves with close but unequal frequencies.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'The wavelength of a wave with frequency 500 Hz and speed 340 m/s is:',
                        'opts' => ['A' => '0.68 m', 'B' => '6.8 m', 'C' => '0.068 m', 'D' => '170 m'],
                        'ans' => 'A',
                        'exp' => 'λ = v/f = 340/500 = 0.68 m.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'Standing waves in a pipe open at both ends have:',
                        'opts' => ['A' => 'Nodes at both ends', 'B' => 'Antinodes at both ends', 'C' => 'Node at one end, antinode at other', 'D' => 'Only nodes'],
                        'ans' => 'B',
                        'exp' => 'In an open pipe, displacement antinodes (maximum amplitude) are formed at both open ends.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'The Doppler effect is observed when:',
                        'opts' => ['A' => 'Source and observer are stationary', 'B' => 'There is relative motion between source and observer', 'C' => 'Only the medium moves', 'D' => 'Frequency of source changes'],
                        'ans' => 'B',
                        'exp' => 'The Doppler effect is the change in observed frequency due to relative motion between the sound source and observer.',
                        'diff' => 'easy',
                    ],
                ],
            ],

            // JEE Chemistry
            'Chemistry' => [
                'Chemical Kinetics' => [
                    [
                        'q' => 'The rate of a reaction is expressed as: rate = k[A]². The order of this reaction is:',
                        'opts' => ['A' => '0', 'B' => '1', 'C' => '2', 'D' => '3'],
                        'ans' => 'C',
                        'exp' => 'The order equals the sum of exponents of concentration terms. Here rate ∝ [A]², so order = 2 (second order).',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'Increasing temperature generally increases reaction rate because:',
                        'opts' => ['A' => 'It decreases activation energy', 'B' => 'More molecules have energy ≥ activation energy', 'C' => 'It increases bond strength', 'D' => 'Concentration increases'],
                        'ans' => 'B',
                        'exp' => 'Higher temperature shifts the Maxwell-Boltzmann distribution so more molecules exceed the activation energy barrier.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'The Arrhenius equation k = Ae^(-Ea/RT) relates rate constant to:',
                        'opts' => ['A' => 'Pressure', 'B' => 'Temperature and activation energy', 'C' => 'Concentration only', 'D' => 'Volume'],
                        'ans' => 'B',
                        'exp' => 'Arrhenius equation shows k depends on pre-exponential factor A, activation energy Ea, and temperature T.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'A catalyst increases reaction rate by:',
                        'opts' => ['A' => 'Increasing activation energy', 'B' => 'Providing an alternative path with lower activation energy', 'C' => 'Increasing reactant concentration', 'D' => 'Raising temperature'],
                        'ans' => 'B',
                        'exp' => 'A catalyst provides an alternative reaction pathway with lower activation energy, increasing the fraction of effective collisions.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'For a first-order reaction, the half-life is:',
                        'opts' => ['A' => 'Proportional to initial concentration', 'B' => '0.693/k (independent of concentration)', 'C' => 'k/0.693', 'D' => '1/k[A]₀'],
                        'ans' => 'B',
                        'exp' => 't½ = 0.693/k for first-order reactions. It is independent of initial concentration — a key characteristic.',
                        'diff' => 'medium',
                    ],
                ],
                'p-block Elements' => [
                    [
                        'q' => 'The hybridisation of nitrogen in NH₃ is:',
                        'opts' => ['A' => 'sp', 'B' => 'sp²', 'C' => 'sp³', 'D' => 'sp³d'],
                        'ans' => 'C',
                        'exp' => 'N in NH₃: 3 bond pairs + 1 lone pair = 4 electron pairs → sp³ hybridisation, pyramidal shape.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'Which of the following is the strongest acid?',
                        'opts' => ['A' => 'HF', 'B' => 'HCl', 'C' => 'HBr', 'D' => 'HI'],
                        'ans' => 'D',
                        'exp' => 'Acid strength of HX increases down the group: HF < HCl < HBr < HI. H-I bond is weakest, ionises most easily.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'Ozone layer protects earth from:',
                        'opts' => ['A' => 'Infrared radiation', 'B' => 'UV-B and UV-C radiation', 'C' => 'Visible light', 'D' => 'X-rays'],
                        'ans' => 'B',
                        'exp' => 'The stratospheric ozone layer absorbs harmful UV-B (280–315 nm) and UV-C (100–280 nm) radiation from the sun.',
                        'diff' => 'easy',
                    ],
                    [
                        'q' => 'Which element forms the maximum number of allotropes?',
                        'opts' => ['A' => 'Oxygen', 'B' => 'Sulfur', 'C' => 'Carbon', 'D' => 'Phosphorus'],
                        'ans' => 'C',
                        'exp' => 'Carbon shows multiple allotropes: diamond, graphite, fullerene (C₆₀), graphene, carbon nanotubes, amorphous carbon, etc.',
                        'diff' => 'medium',
                    ],
                    [
                        'q' => 'Aqua regia is a mixture of:',
                        'opts' => ['A' => 'HCl + HNO₃ in 3:1 ratio', 'B' => 'H₂SO₄ + HNO₃', 'C' => 'HCl + H₂SO₄', 'D' => 'HNO₃ + H₂O'],
                        'ans' => 'A',
                        'exp' => 'Aqua regia = 3 parts conc. HCl + 1 part conc. HNO₃. It dissolves noble metals like gold and platinum.',
                        'diff' => 'easy',
                    ],
                ],
            ],
        ],
    ];

    public function run(): void
    {
        $subjects = Subject::with('chapters')->get();
        if ($subjects->isEmpty()) {
            $this->command->warn('No subjects found. Please run SubjectSeeder first.');
            return;
        }

        $admin = User::where('role', 'super_admin')->first();
        if (!$admin) {
            $this->command->warn('No super admin found. Please run SuperAdminSeeder first.');
            return;
        }

        $totalInserted = 0;
        $totalGeneric = 0;

        foreach ($subjects as $subject) {
            $examType    = $subject->exam_type;
            $subjectName = $subject->name;

            foreach ($subject->chapters as $chapter) {
                $chapterName = $chapter->name;
                $bankQuestions = $this->questionBank[$examType][$subjectName][$chapterName] ?? [];

                // --- Insert real questions from the bank ---
                foreach ($bankQuestions as $q) {
                    Question::firstOrCreate(
                        [
                            'question_text' => $q['q'],
                            'chapter_id'    => $chapter->id,
                        ],
                        [
                            'uuid'           => Str::uuid(),
                            'institution_id' => null,
                            'created_by'     => $admin->id,
                            'exam_type'      => $examType,
                            'subject_id'     => $subject->id,
                            'chapter_id'     => $chapter->id,
                            'topic_id'       => null,
                            'difficulty'     => $q['diff'],
                            'type'           => 'single_mcq',
                            'question_text'  => $q['q'],
                            'options'        => $q['opts'],
                            'correct_answer' => $q['ans'],
                            'explanation'    => $q['exp'],
                            'positive_marks' => 4.00,
                            'negative_marks' => 1.00,
                            'status'         => 'active',
                            'source_type'    => 'platform',
                        ]
                    );
                    $totalInserted++;
                }

                // --- Fill remaining with smart generic questions (up to 5 total) ---
                $needed = max(0, 5 - count($bankQuestions));
                for ($i = 1; $i <= $needed; $i++) {
                    $difficulty = collect(['easy', 'medium', 'hard'])->random();
                    $questionText = "In the context of {$chapterName} ({$subjectName}, " . strtoupper(str_replace('_', ' ', $examType)) . "), which of the following statements is correct? [Question $i]";

                    Question::firstOrCreate(
                        ['question_text' => $questionText, 'chapter_id' => $chapter->id],
                        [
                            'uuid'           => Str::uuid(),
                            'institution_id' => null,
                            'created_by'     => $admin->id,
                            'exam_type'      => $examType,
                            'subject_id'     => $subject->id,
                            'chapter_id'     => $chapter->id,
                            'topic_id'       => null,
                            'difficulty'     => $difficulty,
                            'type'           => 'single_mcq',
                            'question_text'  => $questionText,
                            'options'        => [
                                'A' => "Option A — correct statement about {$chapterName}",
                                'B' => "Option B — incorrect statement about {$chapterName}",
                                'C' => "Option C — partially correct statement",
                                'D' => "Option D — unrelated to {$chapterName}",
                            ],
                            'correct_answer' => 'A',
                            'explanation'    => "Option A is correct based on the principles of {$chapterName}.",
                            'positive_marks' => 4.00,
                            'negative_marks' => 1.00,
                            'status'         => 'active',
                            'source_type'    => 'platform',
                        ]
                    );
                    $totalGeneric++;
                }
            }
        }

        $this->command->info("✅ Seeded {$totalInserted} real questions and {$totalGeneric} generic placeholders.");
        $this->command->info("   Total: " . ($totalInserted + $totalGeneric) . " questions across all subjects.");
    }
}
