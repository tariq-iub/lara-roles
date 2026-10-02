<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\SyncUserRolesRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\RbacCacheManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private readonly RbacCacheManager $cache) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        $users = User::query()
            ->with('roles')
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $q->where(fn ($w) => $w
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like));
            })
            ->when($request->query('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.users.index', compact('users', 'search'));
    }

    public function create(): View
    {
        return view('admin.users.create', [
            'roles' => Role::orderBy('name')->get(),
            'selected' => [],
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Atomic: create user + attach roles in one transaction.
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'], // hashed via cast
                'is_active' => $data['is_active'] ?? true,
            ]);

            if (! empty($data['roles'])) {
                $user->roles()->sync($data['roles']);
            }

            activity('user')
                ->performedOn($user)
                ->event('created')
                ->withProperties(['roles' => $data['roles'] ?? []])
                ->log('User created');

            return $user;
        });

        $this->cache->userRolesChanged($user);

        return redirect()
            ->route('admin.users.index')
            ->with('success', "User {$user->name} created.");
    }

    public function show(User $user): View
    {
        $user->load('roles');

        return view('admin.users.show', compact('user'));
    }

    public function edit(User $user): View
    {
        return view('admin.users.edit', [
            'user' => $user->load('roles'),
            'roles' => Role::orderBy('name')->get(),
            'selected' => $user->roles->pluck('id')->all(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $user) {
            $attributes = array_filter([
                'name' => $data['name'],
                'email' => $data['email'],
                'is_active' => $data['is_active'] ?? null,
            ], fn ($v) => $v !== null);

            $old = $user->only(array_keys($attributes));
            $user->fill($attributes);

            if (! empty($data['password'])) {
                $user->password = $data['password']; // NEVER logged
            }

            $user->save();

            if (array_key_exists('roles', $data)) {
                $before = $user->roles()->pluck('roles.id')->all();
                $user->roles()->sync($data['roles']);

                activity('role_assignment')
                    ->performedOn($user)
                    ->event('roles_synced')
                    ->withProperties([
                        'old_roles' => $before,
                        'new_roles' => array_map('intval', $data['roles']),
                    ])
                    ->log('User roles updated');
            }

            activity('user')
                ->performedOn($user)
                ->event('updated')
                ->withProperties([
                    'old' => $old,
                    'attributes_changed' => $user->getChanges(),
                ])
                ->log('User updated');
        });

        $this->cache->userRolesChanged($user);

        return redirect()
            ->route('admin.users.index')
            ->with('success', "User {$user->name} updated.");
    }

    /** Toggle activation — inactive users lose access immediately. */
    public function toggleActive(User $user): RedirectResponse
    {
        // Never allow an admin to lock themselves out of their own account.
        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        DB::transaction(function () use ($user) {
            $wasActive = $user->is_active;
            $user->update(['is_active' => ! $user->is_active]);

            activity('user')
                ->performedOn($user)
                ->event($wasActive ? 'deactivated' : 'activated')
                ->withProperties(['old' => ['is_active' => $wasActive], 'new' => ['is_active' => ! $wasActive]])
                ->log($wasActive ? 'User deactivated' : 'User activated');
        });

        $this->cache->userRolesChanged($user);

        return back()->with('success', "User {$user->name} ".($user->is_active ? 'activated' : 'deactivated').'.');
    }

    /** Dedicated endpoint for role assignment UI (Phase 10). */
    public function syncRoles(SyncUserRolesRequest $request, User $user): RedirectResponse
    {
        $newRoleIds = array_map('intval', $request->validated('roles'));

        DB::transaction(function () use ($user, $newRoleIds) {
            $oldRoleIds = $user->roles()->pluck('roles.id')->all();

            $user->roles()->sync($newRoleIds);

            $added = array_values(array_diff($newRoleIds, $oldRoleIds));
            $removed = array_values(array_diff($oldRoleIds, $newRoleIds));

            foreach ($added as $roleId) {
                activity('role_assignment')
                    ->performedOn($user)
                    ->event('role_assigned')
                    ->withProperties(['role_id' => $roleId, 'role' => Role::find($roleId)?->name])
                    ->log('Role assigned to user');
            }

            foreach ($removed as $roleId) {
                activity('role_assignment')
                    ->performedOn($user)
                    ->event('role_removed')
                    ->withProperties(['role_id' => $roleId, 'role' => Role::withTrashed()->find($roleId)?->name])
                    ->log('Role removed from user');
            }
        });

        $this->cache->userRolesChanged($user);

        return back()->with('success', 'Roles updated.');
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        // Protect the last super administrator.
        if ($user->hasRole(Role::SUPER_ADMIN_SLUG) &&
            User::whereHas('roles', fn ($q) => $q->where('slug', Role::SUPER_ADMIN_SLUG))->count() <= 1) {
            return back()->with('error', 'Cannot delete the last super administrator.');
        }

        DB::transaction(function () use ($user) {
            activity('user')
                ->performedOn($user)
                ->event('deleted')
                ->withProperties([
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles()->pluck('roles.slug')->all(),
                ])
                ->log('User deleted');

            $user->roles()->detach();
            $user->delete();
        });

        $this->cache->userRolesChanged($user);

        return redirect()->route('admin.users.index')->with('success', 'User deleted.');
    }
}
