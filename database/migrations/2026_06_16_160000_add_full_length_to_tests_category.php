<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE tests MODIFY COLUMN test_category
            ENUM('chapter_test','subject_test','weekly_test','mock_test',
                 'grand_test','practice_test','custom','full_length')
            NOT NULL DEFAULT 'chapter_test'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tests MODIFY COLUMN test_category
            ENUM('chapter_test','subject_test','weekly_test','mock_test',
                 'grand_test','practice_test','custom')
            NOT NULL DEFAULT 'chapter_test'");
    }
};
