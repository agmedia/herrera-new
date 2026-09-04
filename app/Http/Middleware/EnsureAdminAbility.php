<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminAbility
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $routeName = $route?->getName();

        if (! is_string($routeName) || ! Str::startsWith($routeName, 'admin.')) {
            return $next($request);
        }

        $user = $request->user();
        abort_unless($user, 401);

        if ($user->isA('superadmin')) {
            return $next($request);
        }

        // Global admin gate.
        abort_unless($user->can('admin.access'), 403, 'Missing admin access ability.');

        $routeRule = $this->resolveRouteRule($routeName);

        if ($request->has('components') && is_array($request->input('components'))) {
            $this->authorizeLivewireCalls($request, $user);
        } else {
            $required = $this->requiredForHttpRequest($routeRule, $request);
            $this->authorizeAny($user, $required);
        }

        return $next($request);
    }

    /**
     * @param  array<string, mixed>|null  $routeRule
     * @return array<int, string>
     */
    private function requiredForHttpRequest(?array $routeRule, Request $request): array
    {
        if ($routeRule === null) {
            return [];
        }

        if (in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $this->normalizeAbilityList($routeRule['view'] ?? $routeRule['mutate'] ?? []);
        }

        return $this->normalizeAbilityList($routeRule['mutate'] ?? $routeRule['view'] ?? []);
    }

    private function authorizeLivewireCalls(Request $request, $user): void
    {
        $payloads = $request->input('components', []);
        if (! is_array($payloads)) {
            return;
        }

        foreach ($payloads as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $snapshotRaw = Arr::get($payload, 'snapshot');
            $snapshot = is_string($snapshotRaw) ? json_decode($snapshotRaw, true) : null;
            if (! is_array($snapshot)) {
                continue;
            }

            $originRouteName = $this->resolveRouteNameFromSnapshot($snapshot);
            if (! is_string($originRouteName) || ! Str::startsWith($originRouteName, 'admin.')) {
                continue;
            }

            $routeRule = $this->resolveRouteRule($originRouteName);
            $calls = Arr::get($payload, 'calls', []);
            if (! is_array($calls) || $calls === []) {
                $this->authorizeAny($user, $this->normalizeAbilityList($routeRule['view'] ?? []));

                continue;
            }

            foreach ($calls as $call) {
                $method = strtolower((string) Arr::get($call, 'method', ''));
                if ($method === '') {
                    continue;
                }

                $required = $this->requiredForLivewireMethod($routeRule, $method);
                $this->authorizeAny($user, $required);
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $routeRule
     * @return array<int, string>
     */
    private function requiredForLivewireMethod(?array $routeRule, string $method): array
    {
        if ($routeRule === null) {
            return [];
        }

        if ($this->isReadonlyLivewireMethod($method)) {
            return $this->normalizeAbilityList($routeRule['view'] ?? $routeRule['mutate'] ?? []);
        }

        if ($this->isDeleteLikeMethod($method)) {
            return $this->normalizeAbilityList($routeRule['delete'] ?? $routeRule['mutate'] ?? $routeRule['view'] ?? []);
        }

        if ($this->isMutatingLivewireMethod($method)) {
            return $this->normalizeAbilityList($routeRule['mutate'] ?? $routeRule['view'] ?? []);
        }

        return $this->normalizeAbilityList($routeRule['mutate'] ?? $routeRule['view'] ?? []);
    }

    private function isReadonlyLivewireMethod(string $method): bool
    {
        if (Str::startsWith($method, 'updated') || Str::startsWith($method, 'get')) {
            return true;
        }

        foreach ((array) config('admin_authorization.livewire_readonly_methods', []) as $readonlyMethod) {
            if (strtolower((string) $readonlyMethod) === $method) {
                return true;
            }
        }

        return false;
    }

    private function isDeleteLikeMethod(string $method): bool
    {
        foreach ((array) config('admin_authorization.livewire_delete_keywords', []) as $keyword) {
            if (Str::contains($method, (string) $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function isMutatingLivewireMethod(string $method): bool
    {
        foreach ((array) config('admin_authorization.livewire_mutate_keywords', []) as $keyword) {
            if (Str::contains($method, (string) $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function resolveRouteNameFromSnapshot(array $snapshot): ?string
    {
        $path = (string) data_get($snapshot, 'memo.path', '');
        if ($path === '') {
            return null;
        }

        $method = (string) data_get($snapshot, 'memo.method', 'GET');
        if ($method === '') {
            $method = 'GET';
        }

        $request = Request::create('/'.ltrim($path, '/'), $method);

        try {
            $route = app('router')->getRoutes()->match($request);
        } catch (\Throwable) {
            return null;
        }

        return $route->getName();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveRouteRule(string $routeName): ?array
    {
        /** @var array<string, array<string, mixed>> $rules */
        $rules = (array) config('admin_authorization.route_rules', []);

        foreach ($rules as $pattern => $rule) {
            if (Str::is($pattern, $routeName)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>|string  $abilities
     * @return array<int, string>
     */
    private function normalizeAbilityList(array|string $abilities): array
    {
        if (is_string($abilities)) {
            $abilities = [$abilities];
        }

        return collect($abilities)
            ->map(fn ($ability) => trim((string) $ability))
            ->filter(fn ($ability) => $ability !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $required
     */
    private function authorizeAny($user, array $required): void
    {
        if ($required === []) {
            return;
        }

        foreach ($required as $ability) {
            if ($user->can($ability)) {
                return;
            }
        }

        abort(403, 'Missing required ability.');
    }
}
