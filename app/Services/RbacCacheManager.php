<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Centralized cache invalidation for RBAC.
 *
 * Strategy: versioned cache keys. A global "rbac:version" token is included
 * in every per-user authorization key; bumping the token atomically
 * invalidates ALL cached role/menu sets across any cache driver — no scans,
 * no stale entries, immediate effect (Scenario D/E). Explicit invalidation
 * is the guarantee; TTL is only a safety net.
 */
class RbacCacheManager
{
    public const VERSION_KEY = 'rbac:version';

    public function __construct(private readonly AuthorizationService $authorization) {}

    /** Current global RBAC cache version (1 if never bumped). */
    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    /** Bump the global RBAC cache version (invalidates everything). */
    public function bumpVersion(): void
    {
        if (! Cache::has(self::VERSION_KEY)) {
            Cache::forever(self::VERSION_KEY, 1);
        }

        Cache::increment(self::VERSION_KEY);
        // The memo inside this request's service instance must also reset.
        $this->authorization->flushAll();
    }

    /** A single user's role set changed (attach/detach/sync roles). */
    public function userRolesChanged(User|int $user): void
    {
        $id = $user instanceof User ? $user->id : $user;
        $this->authorization->forgetUser($id);
    }

    /** Role record changed (name/slug/is_active/deleted) → all holders affected. */
    public function roleChanged(Role|int $role): void
    {
        $id = $role instanceof Role ? $role->id : $role;
        $this->authorization->forgetUsersOfRoles($id);
        $this->bumpVersion();
    }

    /** Role↔Menu pivot changed → users holding that role are invalidated precisely. */
    public function roleMenusChanged(Role|int $role): void
    {
        $id = $role instanceof Role ? $role->id : $role;
        $this->authorization->forgetUsersOfRoles($id);
    }

    /** Menu record changed (status/route/hierarchy/order) → global snapshot + all users. */
    public function menuChanged(): void
    {
        $this->bumpVersion();
    }
}
