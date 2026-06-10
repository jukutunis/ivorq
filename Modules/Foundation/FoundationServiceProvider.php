<?php

namespace Modules\Foundation;

use Illuminate\Support\ServiceProvider;
use Modules\Foundation\Activity\ActivityServiceProvider;
use Modules\Foundation\Audit\AuditServiceProvider;
use Modules\Foundation\Authentication\AuthenticationServiceProvider;
use Modules\Foundation\Authorization\AuthorizationServiceProvider;
use Modules\Foundation\Department\DepartmentServiceProvider;
use Modules\Foundation\Property\PropertyServiceProvider;
use Modules\Foundation\User\UserServiceProvider;

class FoundationServiceProvider extends ServiceProvider
{
    protected array $providers = [
        // Order matters: upstream modules must boot before downstream
        PropertyServiceProvider::class,
        DepartmentServiceProvider::class,
        UserServiceProvider::class,
        AuthenticationServiceProvider::class,
        AuthorizationServiceProvider::class,
        AuditServiceProvider::class,
        ActivityServiceProvider::class,
        \Modules\Foundation\Approval\ApprovalServiceProvider::class,
    ];

    public function register(): void
    {
        foreach ($this->providers as $provider) {
            $this->app->register($provider);
        }

        $this->app->singleton(
            \Shared\Services\CurrentPropertyService::class,
            \Shared\Services\CurrentPropertyService::class
        );
    }

    public function boot(): void {}
}
