<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inactive users must never retain application access — enforced even if a
 * session survives deactivation (their own or their roles' changes are
 * handled via cache invalidation; status is checked live here).
 *
 * Registered as: 'user.active'
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            // Destroy the session of a deactivated user immediately.
            $guard = app('auth')->guard();
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => __('Your account has been deactivated. Please contact an administrator.')]);
        }

        return $next($request);
    }
}
