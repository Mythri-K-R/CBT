<?php

namespace App\Providers;

use App\Models\Batch;
use App\Models\Question;
use App\Models\Student;
use App\Models\Test;
use App\Models\TestLink;
use App\Policies\BatchPolicy;
use App\Policies\QuestionPolicy;
use App\Policies\StudentPolicy;
use App\Policies\TestLinkPolicy;
use App\Policies\TestPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Batch::class    => BatchPolicy::class,
        Student::class  => StudentPolicy::class,
        Question::class => QuestionPolicy::class,
        Test::class     => TestPolicy::class,
        TestLink::class => TestLinkPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        Gate::define('super-admin', fn ($user) => $user->role === 'super_admin');
        Gate::define('institution-admin', fn ($user) => $user->role === 'institution_admin');
        Gate::define('faculty', fn ($user) => $user->role === 'faculty');
        Gate::define('manage-institution', fn ($user) => in_array($user->role, ['super_admin', 'institution_admin']));
    }
}
