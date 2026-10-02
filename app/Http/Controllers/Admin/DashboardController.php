<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuthorizationService;
use App\Services\MenuService;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        // Aggregate counts — cheap, and only exposed to whoever can reach this
        // route (dashboard menu / super-admin). No sensitive audit rows here.
        $counts = [
            'users' => \App\Models\User::count(),
            'active_users' => \App\Models\User::where('is_active', true)->count(),
            'roles' => \App\Models\Role::count(),
            'active_roles' => \App\Models\Role::where('is_active', true)->count(),
            'menus' => \App\Models\Menu::count(),
            'active_menus' => \App\Models\Menu::where('is_active', true)->count(),
        ];

        // Recent activity is only shown to users authorized for the activity log
        // (or super admins) — visibility ≠ authorization, but we also gate data.
        $canViewActivity = app(AuthorizationService::class)->canAccessRoute($user, 'admin.activity-logs.index');

        $recentActivities = $canViewActivity
            ? \Spatie\Activitylog\Models\Activity::query()
                ->with('causer')
                ->latest()
                ->limit(10)
                ->get()
            : collect();

        return view('admin.dashboard', compact('counts', 'recentActivities', 'canViewActivity'));
    }
}
