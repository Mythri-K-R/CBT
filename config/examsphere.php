<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Subscription Plan Limits
    |--------------------------------------------------------------------------
    */
    'plans' => [
        'trial' => [
            'student_limit'  => env('TRIAL_STUDENT_LIMIT', 50),
            'faculty_limit'  => env('TRIAL_FACULTY_LIMIT', 3),
            'question_limit' => env('TRIAL_QUESTION_LIMIT', 5000),
            'duration_days'  => env('TRIAL_DURATION_DAYS', 30),
            'price'          => 0,
        ],
        'starter' => [
            'student_limit'  => 300,
            'faculty_limit'  => 10,
            'question_limit' => 20000,
            'price'          => 2999,
        ],
        'growth' => [
            'student_limit'  => 1000,
            'faculty_limit'  => 30,
            'question_limit' => 100000,
            'price'          => 7999,
        ],
        'enterprise' => [
            'student_limit'  => 999999,
            'faculty_limit'  => 999,
            'question_limit' => 999999,
            'price'          => 0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Roll Number Generation
    |--------------------------------------------------------------------------
    */
    'roll_number' => [
        'prefix_map' => [
            'neet'         => 'NEET',
            'jee_main'     => 'JEE',
            'jee_advanced' => 'JEEA',
            'kcet'         => 'KCET',
            'general'      => 'GEN',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exam Engine
    |--------------------------------------------------------------------------
    */
    'exam' => [
        'timer_sync_interval_seconds' => 30,
        'auto_submit_grace_seconds'   => 60,
        'max_tab_switches'            => 3,
        'session_token_ttl_minutes'   => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Test Link
    |--------------------------------------------------------------------------
    */
    'link' => [
        'slug_length'        => 8,
        'access_code_length' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Analytics aggregation
    |--------------------------------------------------------------------------
    */
    'analytics' => [
        'aggregate_hour' => 2,  // 2 AM daily
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription expiry warnings (days before expiry)
    |--------------------------------------------------------------------------
    */
    'expiry_warnings' => [30, 15, 7, 3, 1],
];
