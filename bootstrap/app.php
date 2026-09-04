<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectUsersTo(function (Request $request): string {
            $user = $request->user();

            if ($user && $user->b2bAccount()->exists()) {
                return route('account.dashboard');
            }

            return route('dashboard');
        });

        $middleware->preventRequestsDuringMaintenance([
            'dashboard',
            'dashboard/*',
            'admin',
            'admin/*',
            'admin/login',
            'admin/logout',
            'login',
            'logout',
            'register',
            'forgot-password',
            'reset-password/*',
            'password/*',
            'verify-email',
            'verify-email/*',
            'email/verify',
            'email/verify/*',
            'confirm-password',
            'sanctum/csrf-cookie',
            'livewire',
            'livewire/*',
            'storage',
            'storage/*',
            'build',
            'build/*',
            'front-theme',
            'front-theme/*',
            'up',
            '/up',
        ]);

        $middleware->alias([
            'admin.locale' => \App\Http\Middleware\SetAdminLocale::class,
            'admin.access' => \App\Http\Middleware\EnsureAdminAccess::class,
            'admin.ability' => \App\Http\Middleware\EnsureAdminAbility::class,
            'admin.maintenance-bypass' => \App\Http\Middleware\IssueMaintenanceBypassForPrivilegedAdmin::class,
            'front.locale' => \App\Http\Middleware\SetFrontendLocale::class,
            'front.device' => \App\Http\Middleware\DetectFrontendVariant::class,
            'catalog.feature' => \App\Http\Middleware\EnsureCatalogFeatureEnabled::class,
            'user.feature' => \App\Http\Middleware\EnsureUserFeatureEnabled::class,
            'api.user.enabled' => \App\Http\Middleware\EnsureApiUserEnabled::class,
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
