<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Landing page
Route::view('/', 'welcome')->name('home');

// Student results lookup (public)
Volt::route('results', 'pages.student.results-lookup')->name('student.results');

// Student Test Portal (public — no auth)
Volt::route('t/{slug}', 'pages.student.test-landing')->name('student.test.landing');
Volt::route('t/{slug}/instructions', 'pages.student.test-instructions')->name('student.test.instructions');
Volt::route('t/{slug}/exam', 'pages.student.test-attempt')->name('student.test.attempt');
Volt::route('t/{slug}/result', 'pages.student.test-result')->name('student.test.result');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('profile', 'profile')->name('profile');

    // Super Admin Portal
    Route::middleware('role:super_admin')->prefix('admin')->name('admin.')->group(function () {
        Volt::route('dashboard', 'pages.admin.dashboard')->name('dashboard');
        Volt::route('leads', 'pages.admin.leads')->name('leads');
        Volt::route('leads/{lead}', 'pages.admin.leads.show')->name('lead.detail');

        Volt::route('institutions', 'pages.admin.institutions')->name('institutions');
        Volt::route('institutions/{institution}', 'pages.admin.institutions.show')->name('institutions.show');
        Volt::route('subjects', 'pages.admin.subjects')->name('subjects');
        Volt::route('exam-templates', 'pages.admin.exam-templates')->name('exam-templates');
        Volt::route('settings', 'pages.admin.settings')->name('settings');
    });

    // Institution / Faculty Portal
    Route::middleware(['role:institution_admin,faculty', 'institution.scope', 'subscription'])->prefix('institution')->name('institution.')->group(function () {
        Volt::route('dashboard', 'pages.institution.dashboard')->name('dashboard');
        Volt::route('batches', 'pages.institution.batches.index')->name('batches');
        Volt::route('batches/{batch}', 'pages.institution.batches.show')->name('batches.show');
        Volt::route('students', 'pages.institution.students')->name('students');
        Volt::route('students/{student}', 'pages.institution.students.show')->name('students.show');
        Volt::route('faculty', 'pages.institution.faculty')->name('faculty');
        Volt::route('questions', 'pages.institution.questions.index')->name('questions');
        Volt::route('questions/create', 'pages.institution.questions.create')->name('questions.create');
        Volt::route('questions/{question}/edit', 'pages.institution.questions.edit')->name('questions.edit');
        Volt::route('tests', 'pages.institution.tests.index')->name('tests');
        Volt::route('tests/create', 'pages.institution.tests.create')->name('tests.create');
        Volt::route('tests/{test}', 'pages.institution.tests.show')->name('tests.show');
        Volt::route('results', 'pages.institution.results')->name('results');
        Volt::route('results/{test}', 'pages.institution.results.show')->name('results.show');
        Volt::route('analytics', 'pages.institution.analytics')->name('analytics');
        Volt::route('settings', 'pages.institution.settings')->name('settings');
    });
});

require __DIR__.'/auth.php';

// Ensure logout route is defined globally for admin layout
Route::post('logout', function (App\Livewire\Actions\Logout $logout) {
    $logout();
    return redirect('/');
})->name('logout');
