<?php

namespace App\Services;

use App\Models\Menu;
use App\Models\Role;
use App\Models\User;
use App\Support\RoutePatternMatcher;
use Illuminate\Support\Facades\Cache;

/**
 * THE single authoritative implementation of RBAC decisions.
 *
 * Middleware, controllers, Blade directives, sidebar rendering and tests all
 * resolve access through this service — there is no second source of truth.
 *
 * Caching strategy:
 *   - Effective role slugs cached per user:      rbac:user:{id}:roles
 *   - Effective menu records cached per user:    rbac:user:{id}:menus
 *   - Global active-menu snapshot (for route map / ancestors): rbac:menus:active
 *   - TTL is a safety net ONLY; every mutation path calls invalidate*()
 *     explicitly (Phase 13 / RbacCacheSubscriber).
 *   - Per-request memoization avoids repeated cache round-trips too.
 */
class AuthorizationService
{
    public const CACHE_TTL = 3600;

    /** @var array<int, array<string, mixed>> per-request memo */
    private array $memo = [];

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    */

    /**
     * Slugs of the user's ACTIVE roles (soft-deleted roles excluded by
     * default Scopes on Role). Cached + memoized.
     *
     * @return array<int,string>
     */
    public function effectiveRoleSlugs(User $user): array
    {
        if (isset($this->memo['role_slugs'])) {
            return $this->memo['role_slugs'];
        }

        return $this->memo['role_slugs'] = Cache::remember(
            self::roleCacheKey($user->id),
            self::CACHE_TTL,
            fn () => $user->activeRoles()->pluck('roles.slug')->all()
        );
    }

    public function hasRole(User $user, string $slug): bool
    {
        return in_array($slug, $this->effectiveRoleSlugs($user), true);
    }

    /** @param iterable<string> $slugs */
    public function hasAnyRole(User $user, iterable $slugs): bool
    {
        foreach ($slugs as $slug) {
            if ($this->hasRole($user, $slug)) {
                return true;
            }
        }

        return false;
    }

    /** @param iterable<string> $slugs */
    public function hasAllRoles(User $user, iterable $slugs): bool
    {
        $effective = $this->effectiveRoleSlugs($user);

        foreach ($slugs as $slug) {
            if (! in_array($slug, $effective, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * SUPER-ADMIN BYPASS — the ONE centralized location.
     * Never scatter `if (role == 'super-admin')` elsewhere.
     */
    public function isSuperAdmin(User $user): bool
    {
        return $this->hasRole($user, Role::SUPER_ADMIN_SLUG);
    }

    /*
    |--------------------------------------------------------------------------
    | Menus
    |--------------------------------------------------------------------------
    */

    /**
     * Union of ACTIVE menus across the user's ACTIVE roles, deduplicated.
     * Super administrators receive the full active menu set (centralized
     * bypass — see isSuperAdmin()); this keeps sidebar generation uniform
     * while route authorization still bypasses via canAccessRoute().
     * Returns lightweight arrays (id, parent_id, slug, name, route_name, ...)
     * — one query for a fresh cache, zero queries afterwards.
     *
     * @return array<int, array<string,mixed>> keyed by menu id
     */
    public function effectiveMenus(User $user): array
    {
        if (isset($this->memo['menus'])) {
            return $this->memo['menus'];
        }

        return $this->memo['menus'] = Cache::remember(
            self::menuCacheKey($user->id),
            self::CACHE_TTL,
            function () use ($user) {
                // Inactive roles contribute ZERO permissions; soft-deleted
                // roles/menus are excluded via default scopes.
                $roleIds = $user->activeRoles()->pluck('roles.id');

                if ($roleIds->isEmpty()) {
                    return [];
                }

                $query = Menu::query();

                if ($this->hasRole($user, Role::SUPER_ADMIN_SLUG)) {
                    // centralized super-admin bypass: all active menus
                    return $this->allActiveMenus();
                }

                return $query
                    ->active()
                    ->whereHas('roles', function ($q) use ($roleIds) {
                        $q->whereIn('roles.id', $roleIds)
                          ->where('roles.is_active', true);
                    })
                    ->orderBy('sort_order')
                    ->get([
                        'id', 'parent_id', 'name', 'slug', 'icon',
                        'route_name', 'route_parameters', 'sort_order',
                        'is_active', 'is_visible',
                    ])
                    ->keyBy('id')
                    ->toArray();
            }
        );
    }

    /** @return array<int,string> */
    public function accessibleMenuSlugs(User $user): array
    {
        if ($this->isSuperAdmin($user)) {
            return array_values(array_map(
                fn (array $m) => $m['slug'],
                $this->allActiveMenus()
            ));
        }

        return array_values(array_unique(array_map(
            fn (array $m) => $m['slug'],
            $this->effectiveMenus($user)
        )));
    }

    public function canAccessMenu(User $user, string $slug): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true; // centralized bypass
        }

        foreach ($this->effectiveMenus($user) as $menu) {
            if ($menu['slug'] === $slug && $menu['is_active']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Core security decision: may this user hit the given named route?
     * Exact or wildcard menu patterns qualify. Super-admin bypasses here only.
     */
    public function canAccessRoute(User $user, ?string $routeName): bool
    {
        if ($routeName === null || $routeName === '') {
            return false;
        }

        if ($this->isSuperAdmin($user)) {
            return true; // centralized bypass
        }

        foreach ($this->effectiveMenus($user) as $menu) {
            if (empty($menu['is_active']) || empty($menu['route_name'])) {
                continue;
            }

            if (RoutePatternMatcher::matches($menu['route_name'], $routeName)) {
                return true;
            }
        }

        return false;
    }

    /** The menu records that authorize a route (for middleware auditing). */
    public function menusAuthorizingRoute(string $routeName): array
    {
        return array_values(array_filter(
            $this->allActiveMenus(),
            fn (array $m) => RoutePatternMatcher::matches($m['route_name'], $routeName)
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Global menu snapshot (ancestors, super-admin tree, route map)
    |--------------------------------------------------------------------------
    */

    /** All active (non-deleted) menus — cached globally, invalidated on menu writes. */
    public function allActiveMenus(): array
    {
        if (isset($this->memo['all_menus'])) {
            return $this->memo['all_menus'];
        }

        return $this->memo['all_menus'] = Cache::remember(
            'rbac:menus:active:'.RbacCacheManager::version(),
            self::CACHE_TTL,
            fn () => Menu::query()
                ->active()
                ->orderBy('sort_order')
                ->get([
                    'id', 'parent_id', 'name', 'slug', 'icon',
                    'route_name', 'route_parameters', 'sort_order',
                    'is_active', 'is_visible',
                ])
                ->keyBy('id')
                ->toArray()
        );
    }

    /**
     * Walk up the parent chain to include ancestor heading menus.
     * Cycle-guarded; arbitrary depth supported without recursion.
     *
     * @param array<int,array> $menusById
     * @return array<int,int> ancestor ids to include
     */
    public function ancestorIdsFor(array $menuIds, array $menusById): array
    {
        $extra = [];
        $guard = 0;

        foreach ($menuIds as $id) {
            $current = $menusById[$id] ?? null;

            while ($current && $current['parent_id'] !== null && $guard++ < 1000) {
                $parentId = $current['parent_id'];

                if (isset($menuIds[$parentId]) || isset($extra[$parentId])) {
                    break; // already included / cycle-safe via guard
                }

                $parent = $menusById[$parentId] ?? null;

                if (! $parent || empty($parent['is_active'])) {
                    break; // inactive parent breaks the chain
                }

                $extra[$parentId] = $parentId;
                $current = $parent;
            }
        }

        return $extra;
    }

    /*
    |--------------------------------------------------------------------------
    | Cache keys & invalidation
    |--------------------------------------------------------------------------
    */

    public static function roleCacheKey(int|string $userId): string
    {
        // Versioned key: bumping rbac:version atomically invalidates every
        // cached authorization set on any cache driver (RbacCacheManager).
        $v = RbacCacheManager::version();

        return "rbac:v{$v}:user:{$userId}:roles";
    }

    public static function menuCacheKey(int|string $userId): string
    {
        $v = RbacCacheManager::version();

        return "rbac:v{$v}:user:{$userId}:menus";
    }

    public function forgetUser(int|string $userId): void
    {
        Cache::forget(self::roleCacheKey($userId));
        Cache::forget(self::menuCacheKey($userId));
        unset($this->memo['role_slugs'], $this->memo['menus']);
    }

    /** Invalidate every user (roles/menus changed globally). */
    public function flushAll(): void
    {
        Cache::forget('rbac:menus:active');

        // Forget the current-version global snapshot too (versioned key).
        Cache::forget('rbac:menus:active:'.RbacCacheManager::version());
        $this->memo = [];
    }

    /**
     * Invalidate the per-user caches of every user attached to the given
     * role(s). Used when a role or its menu assignments change so affected
     * users see the new authorization immediately — without logging out.
     *
     * @param iterable<int>|int $roleIds
     */
    public function forgetUsersOfRoles(int|iterable $roleIds): void
    {
        $roleIds = is_array($roleIds) || $roleIds instanceof \Illuminate\Support\Collection
            ? $roleIds
            : (is_iterable($roleIds) ? iterator_to_array($roleIds) : [$roleIds]);

        $userIds = \App\Models\User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('roles.id', $roleIds))
            ->pluck('id');

        foreach ($userIds as $userId) {
            $this->forgetUser($userId);
        }
    }
}
