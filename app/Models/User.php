<?php

namespace App\Models;

use App\Services\AuthorizationService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        // NEVER log password / remember_token — explicit allow-list only.
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('user');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /** All roles attached to the user (including inactive ones). */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    /** Only ACTIVE roles — the authoritative source of effective permissions. */
    public function activeRoles(): BelongsToMany
    {
        return $this->roles()->where('roles.is_active', true);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization API (thin delegates to AuthorizationService)
    |--------------------------------------------------------------------------
    | Results are memoized per-request inside the service so repeated calls
    | never generate repeated queries.
    */

    public function hasRole(string $slug): bool
    {
        return app(AuthorizationService::class)->hasRole($this, $slug);
    }

    /** @param array<int,string> $slugs */
    public function hasAnyRole(array $slugs): bool
    {
        return app(AuthorizationService::class)->hasAnyRole($this, $slugs);
    }

    /** @param array<int,string> $slugs */
    public function hasAllRoles(array $slugs): bool
    {
        return app(AuthorizationService::class)->hasAllRoles($this, $slugs);
    }

    public function isSuperAdmin(): bool
    {
        return app(AuthorizationService::class)->isSuperAdmin($this);
    }

    /** Effective menu slugs (union over active roles), deduplicated. */
    public function accessibleMenus(): array
    {
        return app(AuthorizationService::class)->accessibleMenuSlugs($this);
    }

    public function canAccessMenu(string $slug): bool
    {
        return app(AuthorizationService::class)->canAccessMenu($this, $slug);
    }

    public function canAccessRoute(?string $routeName): bool
    {
        return app(AuthorizationService::class)->canAccessRoute($this, $routeName);
    }
}
