<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\SyncRoleMenusRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Models\Menu;
use App\Models\Role;
use App\Services\MenuService;
use App\Services\RbacCacheManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function __construct(
        private readonly RbacCacheManager $cache,
        private readonly MenuService $menuService,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        $roles = Role::query()
            ->withCount(['users', 'menus'])
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('slug', 'like', $like));
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.roles.index', compact('roles', 'search'));
    }

    public function create(): View
    {
        return view('admin.roles.create', [
            'role' => new Role(),
            'tree' => $this->menuService->fullTree(),
            'selected' => [],
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $role = DB::transaction(function () use ($data) {
            $role = Role::create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            if (! empty($data['menus'])) {
                $role->menus()->sync($data['menus']);
            }

            activity('role')
                ->performedOn($role)
                ->event('created')
                ->withProperties(['slug' => $role->slug, 'menus' => $data['menus'] ?? []])
                ->log('Role created');

            return $role;
        });

        $this->cache->roleMenusChanged($role);

        return redirect()->route('admin.roles.index')->with('success', "Role {$role->name} created.");
    }

    public function show(Role $role): View
    {
        $role->load(['users', 'menus']);

        return view('admin.roles.show', compact('role'));
    }

    public function edit(Role $role): View
    {
        return view('admin.roles.edit', [
            'role' => $role,
            'tree' => $this->menuService->fullTree(),
            'selected' => $role->menus()->pluck('menus.id')->all(),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $data = $request->validated();

        // Guard: never deactivate/rename away the last super admin role.
        if ($role->slug === Role::SUPER_ADMIN_SLUG && ($data['slug'] !== Role::SUPER_ADMIN_SLUG || ($data['is_active'] ?? true) === false)) {
            return back()->with('error', 'The Super Administrator role cannot be deactivated or re-slugged.');
        }

        DB::transaction(function () use ($data, $role) {
            $old = $role->only(['name', 'slug', 'description', 'is_active']);
            $role->update([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? $role->is_active,
            ]);

            if (array_key_exists('menus', $data)) {
                $role->menus()->sync($data['menus'] ?? []);
            }

            activity('role')
                ->performedOn($role)
                ->event('updated')
                ->withProperties(['old' => $old, 'new' => $role->only(['name', 'slug', 'description', 'is_active'])])
                ->log('Role updated');
        });

        // Slug/status/menu changes affect every holder immediately.
        $this->cache->roleChanged($role);

        return redirect()->route('admin.roles.index')->with('success', "Role {$role->name} updated.");
    }

    public function toggleActive(Role $role): RedirectResponse
    {
        if ($role->slug === Role::SUPER_ADMIN_SLUG) {
            return back()->with('error', 'The Super Administrator role cannot be deactivated.');
        }

        DB::transaction(function () use ($role) {
            $wasActive = $role->is_active;
            $role->update(['is_active' => ! $wasActive]);

            activity('role')
                ->performedOn($role)
                ->event($wasActive ? 'deactivated' : 'activated')
                ->withProperties(['old' => ['is_active' => $wasActive], 'new' => ['is_active' => ! $wasActive]])
                ->log($wasActive ? 'Role deactivated' : 'Role activated');
        });

        // Inactive role contributes ZERO permissions — invalidate holders now.
        $this->cache->roleChanged($role);

        return back()->with('success', "Role {$role->name} ".($role->is_active ? 'activated' : 'deactivated').'.');
    }

    /*
    |--------------------------------------------------------------------------
    | Role → Menu assignment UI (Phase 11 / §16)
    |--------------------------------------------------------------------------
    */

    public function menusForm(Role $role): View
    {
        return view('admin.roles.menus', [
            'role' => $role,
            'tree' => $this->menuService->fullTree(),
            'selected' => $role->menus()->pluck('menus.id')->all(),
        ]);
    }

    public function syncMenus(SyncRoleMenusRequest $request, Role $role): RedirectResponse
    {
        $menuIds = array_map('intval', $request->validated('menus'));

        DB::transaction(function () use ($role, $menuIds) {
            $old = $role->menus()->pluck('menus.id')->all();

            $role->menus()->sync($menuIds);

            $added = array_values(array_diff($menuIds, $old));
            $removed = array_values(array_diff($old, $menuIds));

            foreach ($added as $menuId) {
                activity('menu_assignment')
                    ->performedOn($role)
                    ->event('menu_assigned')
                    ->withProperties(['menu_id' => $menuId, 'menu' => Menu::find($menuId)?->name])
                    ->log('Menu assigned to role');
            }

            foreach ($removed as $menuId) {
                activity('menu_assignment')
                    ->performedOn($role)
                    ->event('menu_removed')
                    ->withProperties(['menu_id' => $menuId, 'menu' => Menu::withTrashed()->find($menuId)?->name])
                    ->log('Menu removed from role');
            }
        });

        // Scenario D: affected users lose/gain entries WITHOUT re-login.
        $this->cache->roleMenusChanged($role);

        return back()->with('success', 'Menu assignments updated.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->slug === Role::SUPER_ADMIN_SLUG) {
            return back()->with('error', 'The Super Administrator role cannot be deleted.');
        }

        DB::transaction(function () use ($role) {
            activity('role')
                ->performedOn($role)
                ->event('deleted')
                ->withProperties(['name' => $role->name, 'slug' => $role->slug, 'users' => $role->users()->count()])
                ->log('Role deleted');

            // Soft delete keeps historical auditability; pivot rows are removed
            // so no stale grant can survive (§35).
            $role->menus()->detach();
            $role->users()->detach();
            $role->delete();
        });

        $this->cache->roleChanged($role);

        return redirect()->route('admin.roles.index')->with('success', 'Role deleted.');
    }
}
