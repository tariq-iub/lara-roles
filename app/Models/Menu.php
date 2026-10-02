<?php

namespace App\Models;

use App\Support\RoutePatternMatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Menu extends Model
{
    /** @use HasFactory<\Database\Factories\MenuFactory> */
    use HasFactory;
    use SoftDeletes;
    use LogsActivity;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'route_name',
        'route_parameters',
        'icon',
        'sort_order',
        'is_active',
        'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'route_parameters' => 'array',
            'is_active' => 'boolean',
            'is_visible' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('menu');
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }

    /*
    |--------------------------------------------------------------------------
    | Behavior
    |--------------------------------------------------------------------------
    */

    /** Does this menu authorize the given named route (supports "admin.users.*")? */
    public function authorizesRoute(?string $routeName): bool
    {
        if ($routeName === null || $this->route_name === null || ! $this->is_active) {
            return false;
        }

        return RoutePatternMatcher::matches($this->route_name, $routeName);
    }

    /**
     * Cycle-safe ancestor check: returns true when $this is a descendant of $ancestorId.
     * Guarded by a depth limit + visited set so corrupt data cannot loop forever.
     */
    public function isDescendantOf(int $ancestorId, int $maxDepth = 25): bool
    {
        $current = $this;
        $visited = [$this->id];

        for ($i = 0; $i < $maxDepth; $i++) {
            if ($current->parent_id === null) {
                return false;
            }

            if ($current->parent_id === $ancestorId) {
                return true;
            }

            if (in_array($current->parent_id, $visited, true)) {
                return false; // cycle detected in stored data
            }

            $visited[] = $current->parent_id;
            $current = $current->parent ?? self::find($current->parent_id);

            if (! $current) {
                return false;
            }
        }

        return false;
    }
}
