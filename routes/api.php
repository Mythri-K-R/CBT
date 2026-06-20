<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\InstitutionRegisterController;
use App\Http\Controllers\SuperAdmin\DashboardController as SuperAdminDashboard;
use App\Http\Controllers\SuperAdmin\LeadController;
use App\Http\Controllers\SuperAdmin\InstitutionController as SuperAdminInstitutionController;
use App\Http\Controllers\SuperAdmin\SubscriptionController;
use App\Http\Controllers\SuperAdmin\ContentLibraryController;
use App\Http\Controllers\SuperAdmin\QuestionImportController as SuperAdminQuestionImport;
use App\Http\Controllers\SuperAdmin\SubjectStructureController;
use App\Http\Controllers\SuperAdmin\ExamTemplateController as SuperAdminTemplateController;
use App\Http\Controllers\SuperAdmin\SettingsController as SuperAdminSettings;
use App\Http\Controllers\Institution\DashboardController as InstDashboard;
use App\Http\Controllers\Institution\BatchController;
use App\Http\Controllers\Institution\StudentController;
use App\Http\Controllers\Institution\StudentImportController;
use App\Http\Controllers\Institution\FacultyController;
use App\Http\Controllers\Institution\QuestionController;
use App\Http\Controllers\Institution\QuestionImportController;
use App\Http\Controllers\Institution\TestController;
use App\Http\Controllers\Institution\TestWizardController;
use App\Http\Controllers\Institution\TestLinkController;
use App\Http\Controllers\Institution\ResultController;
use App\Http\Controllers\Institution\StudentAnalyticsController;
use App\Http\Controllers\Institution\BatchAnalyticsController;
use App\Http\Controllers\Institution\ReportController;
use App\Http\Controllers\Institution\SettingsController as InstSettings;
use App\Http\Controllers\TestAccess\TestJoinController;
use App\Http\Controllers\TestAccess\TestInstructionsController;
use App\Http\Controllers\TestAccess\StudentResultsController;
use App\Http\Controllers\Api\ExamEngineController;
use App\Http\Controllers\Api\ExamOfflineSyncController;
use App\Http\Controllers\Api\MonitoringController;
use App\Http\Controllers\Api\TimerSyncController;
use App\Http\Controllers\Api\ProctorController;
use Illuminate\Support\Facades\Route;

/*
|==========================================================================
| PUBLIC ROUTES — No authentication required
|==========================================================================
*/

// Enquiry & registration
Route::middleware('throttle:public')->group(function () {
    Route::post('/enquiry', [InstitutionRegisterController::class, 'submitEnquiry']);
    Route::post('/register-institution', [InstitutionRegisterController::class, 'register']);
});

// Authentication
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:login');
Route::middleware('throttle:public')->group(function () {
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink']);
    Route::post('/reset-password', [ForgotPasswordController::class, 'reset']);
});

// Student test access — PUBLIC (no auth)
Route::prefix('test')->group(function () {
    // Public join/identify — moderate rate limit (60/min per IP)
    Route::middleware('throttle:public')->group(function () {
        Route::get('/join/{slug}', [TestJoinController::class, 'show']);
        Route::get('/join/{slug}/students', [TestJoinController::class, 'listStudents']);
        Route::post('/join/{slug}/identify', [TestJoinController::class, 'identify']);
        Route::get('/join/{slug}/instructions', [TestInstructionsController::class, 'show']);
        Route::post('/join/{slug}/start', [ExamEngineController::class, 'start']);
    });

    // Exam engine (session-locked via attempt UUID — no Sanctum needed)
    Route::middleware('single.session')->group(function () {
        // Save: 120/min per attempt_uuid (2/sec — well above 10-second auto-save)
        Route::post('/exam/save-response', [ExamEngineController::class, 'saveResponse'])
            ->middleware('throttle:exam-save');

        Route::get('/exam/{attemptUuid}/timer', [TimerSyncController::class, 'sync'])
            ->middleware('throttle:public');

        // Submit: 3/min per attemptUuid (allows retry on network failure)
        Route::post('/exam/{attemptUuid}/submit', [ExamEngineController::class, 'submit'])
            ->middleware('throttle:exam-submit');

        Route::post('/exam/{attemptUuid}/proctor-event', [ProctorController::class, 'logEvent'])
            ->middleware('throttle:public');
    });

    // Results — moderate limit (no auth required)
    Route::middleware('throttle:public')->group(function () {
        Route::get('/exam/{attemptUuid}/result', [ExamEngineController::class, 'result']);
        Route::get('/exam/{attemptUuid}/solutions', [ExamEngineController::class, 'solutions']);
    });

    // Offline sync — outside single.session: the student may be coming back from a
    // network outage in a new tab with an expired session token. Authentication is
    // by the attempt UUID alone (same model as /result and /solutions).
    Route::post('/exam/{attemptUuid}/sync-queue', [ExamOfflineSyncController::class, 'sync'])
        ->middleware('throttle:exam-sync');
});

// Student results lookup
Route::post('/results/lookup', [StudentResultsController::class, 'lookup']);
Route::get('/results/{attemptUuid}', [StudentResultsController::class, 'show']);

/*
|==========================================================================
| AUTHENTICATED ROUTES
|==========================================================================
*/

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

    Route::post('/logout', [LoginController::class, 'logout']);
    Route::get('/me', [LoginController::class, 'me']);
    Route::post('/change-password', [LoginController::class, 'changePassword']);

    /*
    |----------------------------------------------------------------------
    | SUPER ADMIN ROUTES
    |----------------------------------------------------------------------
    */
    Route::middleware(['role:super_admin'])->prefix('super-admin')->group(function () {
        Route::get('/dashboard', [SuperAdminDashboard::class, 'index']);

        // Leads / Enquiries
        Route::apiResource('leads', LeadController::class);
        Route::patch('/leads/{lead}/status', [LeadController::class, 'updateStatus']);
        Route::post('/leads/{lead}/convert', [LeadController::class, 'convertToInstitution']);

        // Institutions
        Route::apiResource('institutions', SuperAdminInstitutionController::class);
        Route::post('/institutions/{institution}/toggle-active', [SuperAdminInstitutionController::class, 'toggleActive']);
        Route::post('/institutions/{institution}/impersonate', [SuperAdminInstitutionController::class, 'impersonate']);

        // Subscriptions
        Route::get('/subscriptions', [SubscriptionController::class, 'index']);
        Route::post('/institutions/{institution}/subscription', [SubscriptionController::class, 'update']);
        Route::get('/revenue', [SubscriptionController::class, 'revenue']);

        // Platform question bank
        Route::prefix('content')->group(function () {
            Route::get('/questions', [ContentLibraryController::class, 'index']);
            Route::post('/questions', [ContentLibraryController::class, 'store']);
            Route::get('/questions/{question}', [ContentLibraryController::class, 'show']);
            Route::put('/questions/{question}', [ContentLibraryController::class, 'update']);
            Route::delete('/questions/{question}', [ContentLibraryController::class, 'destroy']);
            Route::post('/import', [SuperAdminQuestionImport::class, 'store']);
            Route::get('/import/{import}', [SuperAdminQuestionImport::class, 'show']);
        });

        // Subject hierarchy
        Route::prefix('subjects')->group(function () {
            Route::get('/', [SubjectStructureController::class, 'index']);
            Route::post('/', [SubjectStructureController::class, 'storeSubject']);
            Route::put('/{subject}', [SubjectStructureController::class, 'updateSubject']);
            Route::delete('/{subject}', [SubjectStructureController::class, 'destroySubject']);
            Route::post('/{subject}/chapters', [SubjectStructureController::class, 'storeChapter']);
            Route::put('/chapters/{chapter}', [SubjectStructureController::class, 'updateChapter']);
            Route::delete('/chapters/{chapter}', [SubjectStructureController::class, 'destroyChapter']);
            Route::post('/chapters/{chapter}/topics', [SubjectStructureController::class, 'storeTopic']);
            Route::put('/topics/{topic}', [SubjectStructureController::class, 'updateTopic']);
            Route::delete('/topics/{topic}', [SubjectStructureController::class, 'destroyTopic']);
        });

        // Exam templates
        Route::apiResource('exam-templates', SuperAdminTemplateController::class);

        // Platform settings
        Route::get('/settings', [SuperAdminSettings::class, 'index']);
        Route::post('/settings', [SuperAdminSettings::class, 'update']);

        // Notifications
        Route::get('/notifications', [\App\Http\Controllers\Institution\NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [\App\Http\Controllers\Institution\NotificationController::class, 'readAll']);
    });

    /*
    |----------------------------------------------------------------------
    | INSTITUTION ADMIN + FACULTY ROUTES
    |----------------------------------------------------------------------
    */
    Route::middleware(['role:institution_admin,faculty', 'institution.scope', 'subscription'])->group(function () {

        Route::get('/dashboard', [InstDashboard::class, 'index']);

        // Batches
        Route::apiResource('batches', BatchController::class);
        Route::post('/batches/{batch}/students', [BatchController::class, 'addStudents']);
        Route::delete('/batches/{batch}/students/{student}', [BatchController::class, 'removeStudent']);
        Route::post('/batches/{batch}/faculty', [BatchController::class, 'addFaculty']);
        Route::delete('/batches/{batch}/faculty/{faculty}', [BatchController::class, 'removeFaculty']);

        // Students
        Route::middleware('faculty.permission:can_manage_students')->group(function () {
            Route::apiResource('students', StudentController::class);
            Route::post('/students/import', [StudentImportController::class, 'store']);
            Route::get('/students/import/{import}/status', [StudentImportController::class, 'status']);
        });

        // Faculty management (admin only)
        Route::middleware('role:institution_admin')->group(function () {
            Route::apiResource('faculty', FacultyController::class);
            Route::put('/faculty/{faculty}/permissions', [FacultyController::class, 'updatePermissions']);
            Route::put('/faculty/{faculty}/subjects', [FacultyController::class, 'updateSubjects']);
            Route::post('/faculty/{faculty}/reset-password', [FacultyController::class, 'resetPassword']);
        });

        // Questions
        Route::middleware('faculty.permission:can_create_questions')->group(function () {
            Route::post('/questions', [QuestionController::class, 'store']);
            Route::post('/questions/import', [QuestionImportController::class, 'store']);
            Route::get('/questions/import/{import}/status', [QuestionImportController::class, 'status']);
        });
        Route::middleware('faculty.permission:can_edit_questions')->group(function () {
            Route::put('/questions/{question}', [QuestionController::class, 'update']);
            Route::delete('/questions/{question}', [QuestionController::class, 'destroy']);
        });
        Route::get('/questions', [QuestionController::class, 'index']);
        Route::get('/questions/{question}', [QuestionController::class, 'show']);

        // Tests
        Route::middleware('faculty.permission:can_create_tests')->group(function () {
            Route::post('/tests', [TestController::class, 'store']);
            Route::put('/tests/{test}', [TestController::class, 'update']);
            Route::delete('/tests/{test}', [TestController::class, 'destroy']);
            // Test wizard
            Route::post('/tests/wizard/create', [TestWizardController::class, 'create']);
            Route::put('/tests/wizard/{test}/sections', [TestWizardController::class, 'updateSections']);
            Route::put('/tests/wizard/{test}/questions', [TestWizardController::class, 'updateQuestions']);
            Route::put('/tests/wizard/{test}/settings', [TestWizardController::class, 'updateSettings']);
            Route::post('/tests/wizard/{test}/publish', [TestWizardController::class, 'publish']);
        });
        Route::get('/tests', [TestController::class, 'index']);
        Route::get('/tests/{test}', [TestController::class, 'show']);

        // Test scheduling
        Route::middleware('faculty.permission:can_schedule_tests')->group(function () {
            Route::post('/tests/{test}/schedule', [TestController::class, 'schedule']);
            Route::post('/tests/{test}/activate', [TestController::class, 'activate']);
            Route::post('/tests/{test}/complete', [TestController::class, 'complete']);
        });

        // Test links
        Route::middleware('faculty.permission:can_generate_links')->group(function () {
            Route::get('/tests/{test}/links', [TestLinkController::class, 'index']);
            Route::post('/tests/{test}/links', [TestLinkController::class, 'store']);
            Route::delete('/tests/{test}/links/{link}', [TestLinkController::class, 'destroy']);
            Route::post('/tests/{test}/links/{link}/toggle', [TestLinkController::class, 'toggle']);
            Route::get('/tests/{test}/links/{link}/qr', [TestLinkController::class, 'qrCode']);
            Route::get('/tests/{test}/links/{link}/analytics', [TestLinkController::class, 'analytics']);
        });

        // Results & Analytics
        Route::middleware('faculty.permission:can_view_results')->group(function () {
            Route::get('/tests/{test}/results', [ResultController::class, 'index']);
            Route::get('/tests/{test}/results/{attempt}', [ResultController::class, 'show']);
            Route::get('/tests/{test}/rankings', [ResultController::class, 'rankings']);
            Route::get('/tests/{test}/analytics', [ResultController::class, 'analytics']);
        });

        Route::middleware('faculty.permission:can_view_analytics')->group(function () {
            Route::get('/analytics/students/{student}', [StudentAnalyticsController::class, 'show']);
            Route::get('/analytics/batches/{batch}', [BatchAnalyticsController::class, 'show']);
            Route::get('/analytics/overview', [BatchAnalyticsController::class, 'overview']);
        });

        // Reports
        Route::post('/reports/test/{test}', [ReportController::class, 'testReport']);
        Route::post('/reports/student/{student}', [ReportController::class, 'studentReport']);
        Route::get('/reports/{report}/download', [ReportController::class, 'download']);

        // Subjects (read-only for institution users)
        Route::get('/subjects', [SubjectStructureController::class, 'index']);
        Route::get('/subjects/{subject}/chapters', [SubjectStructureController::class, 'chapters']);
        Route::get('/chapters/{chapter}/topics', [SubjectStructureController::class, 'topics']);

        // Notifications
        Route::get('/notifications', [\App\Http\Controllers\Institution\NotificationController::class, 'index']);
        Route::post('/notifications/{notification}/read', [\App\Http\Controllers\Institution\NotificationController::class, 'markRead']);
        Route::post('/notifications/read-all', [\App\Http\Controllers\Institution\NotificationController::class, 'readAll']);

        // Institution settings (admin only)
        Route::middleware('role:institution_admin')->group(function () {
            Route::get('/settings', [InstSettings::class, 'show']);
            Route::put('/settings', [InstSettings::class, 'update']);
            Route::post('/settings/logo', [InstSettings::class, 'uploadLogo']);
        });

        // ── Real-Time Exam Monitoring (Feature 5) ─────────────────────────────
        // Accessible to institution_admin and faculty with can_view_results permission.
        // SSE stream holds one FPM worker per open connection — see MonitoringController
        // for the recommended Nginx / FPM pool configuration.
        Route::prefix('monitoring')
            ->middleware('faculty.permission:can_view_results')
            ->group(function () {
                Route::get('/health', [MonitoringController::class, 'health']);
                Route::get('/recovery', [MonitoringController::class, 'recovery']);   // Feature 7
                Route::get('/overview', [MonitoringController::class, 'overview']);
                Route::get('/exams/{testId}/snapshot', [MonitoringController::class, 'snapshotExam']);
                // SSE: long-lived connection — must be last in group (no middleware after it runs)
                Route::get('/exams/{testId}/stream', [MonitoringController::class, 'streamExam']);
            });
    });
});
