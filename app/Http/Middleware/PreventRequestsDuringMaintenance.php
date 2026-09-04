<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as Middleware;

class PreventRequestsDuringMaintenance extends Middleware
{
    /**
     * The URIs that should be reachable while the app is in maintenance mode.
     *
     * @var array<int, string>
     */
    protected $except = [
        'admin',
        'admin/*',
        'dashboard',
        'dashboard/*',
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
    ];
}
