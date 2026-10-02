<?php

namespace App\Http\Middleware;

use App\Services\AuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level menu authorization. THE security boundary.
 *
 * Sidebar visibility is a UI representation only — this middleware is what
 * actually protects /admin/* routes against direct URL access.
 *
 * Registered as: 'menu.access'
 */
class EnsureMenuAccess
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Authenticated + active user guaranteed by preceding middleware
        // (auth + user.active). Defensive check anyway:
        if (! $user) {
            abort(401);
        }

        $routeName = optional(Route::current())->getName();

        if ($this->authorization->canAccessRoute($user, $routeName)) {
            return $next($request);
        }

        // ---- DENY: 403 + rate-limited audit entry (never redirect to a privileged page)
        $this->recordUnauthorizedAttempt($request, $routeName);

        abort(403, 'You do not have permission to access this section.');
    }

    /**
     * Audit unauthorized attempts, rate-limited per user+route so a hostile
     * (or buggy) client cannot flood the activity_log table.
     */
    private function recordUnauthorizedAttempt(Request $request, ?string $routeName): void
    {
        $key = sprintf(
            'rbac:unauth:%s:%s',
            $request->user()->id,
            sha1((string) $routeName)
        );

        // Cache::add returns false when the key already exists → skip logging.
        if (! cache()->add($key, true, now()->addMinutes(5))) {
            return;
        }

        activity('security')
            ->performedOn($request->user())
            ->withProperties([
                'user_id' => $request->user()->id,
                'username' => $request->user()->name,
                'email' => $request->user()->email,
                'route' => $routeName,
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'authorized_menus_for_route' => $routeName
                    ? array_map(fn ($m) => $m['slug'], $this->authorization->menusAuthorizingRoute($routeName))
                    : [],
            ])
            ->causedBy($request->user())
            ->event('unauthorized_access_attempt')
            ->log("Unauthorized access attempt to route [{$routeName}]");
    }
}
