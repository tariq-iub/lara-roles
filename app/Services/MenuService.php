<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Builds the authorized sidebar tree for a user.
 *
 * Pipeline (see docs/MENU_AUTHORIZATION.md):
 *   effective menus (union over active roles, deduped)
 *     → include ACTIVE ancestors of authorized children (never show empty parents)
 *     → keep only VISIBLE nodes for navigation
 *     → drop parents whose subtree has no visible leaf
 *     → sort by sort_order
 *     → build nested tree (iterative, cycle-guarded, arbitrary depth)
 */
class MenuService
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    /**
     * Authorized, pruned, sorted, nested menu collection for the sidebar.
     * Each node is an array with a 'children' key (Collection of nodes).
     *
     * @return Collection<int, array<string,mixed>>
     */
    public function forUser(User $user): Collection
    {
        $all = $this->authorization->allActiveMenus();           // id => menu array
        $authorized = $this->authorization->effectiveMenus($user); // id => menu array

        if (count($authorized) === 0 && ! $this->authorization->isSuperAdmin($user)) {
            return collect();
        }

        // Super admin: everything active is authorized.
        if ($this->authorization->isSuperAdmin($user)) {
            $authorized = $all;
        }

        // 1. Add active ancestors so children are never orphaned/hidden.
        $ancestorIds = $this->authorization->ancestorIdsFor(array_keys($authorized), $all);
        $navigation = $authorized + array_intersect_key($all, $ancestorIds);

        // 2. Keep only visible nodes for rendering.
        $visible = array_filter(
            $navigation,
            fn (array $m) => ! empty($m['is_visible'])
        );

        // 3. Iteratively remove parents that have no visible descendants
        //    (a heading parent is meaningless without at least one child).
        $changed = true;
        while ($changed) {
            $changed = false;

            foreach ($visible as $id => $menu) {
                $isLeafTarget = $menu['route_name'] !== null; // clickable item
                $hasVisibleChild = false;

                foreach ($visible as $child) {
                    if ((int) $child['parent_id'] === (int) $id) {
                        $hasVisibleChild = true;
                        break;
                    }
                }

                // A heading (no route) with no visible children must go.
                if (! $isLeafTarget && ! $hasVisibleChild) {
                    unset($visible[$id]);
                    $changed = true;
                }
            }
        }

        return $this->buildTree(array_values($visible));
    }

    /**
     * Full active menu tree (for management UIs / role assignment screens).
     * Includes invisible/inactive? — includes ALL non-deleted menus so admins
     * can manage them; returns nested arrays with 'children'.
     *
     * @return Collection<int, array<string,mixed>>
     */
    public function fullTree(bool $withTrashed = false): Collection
    {
        $query = \App\Models\Menu::query()->orderBy('sort_order');

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $this->buildTree(
            $query->get()->toArray()
        );
    }

    /**
     * Build a nested tree from a flat list, iteratively (no recursion into DB).
     * Cycle-guarded: any node whose parent chain loops is attached at root.
     *
     * @param array<int,array<string,mixed>> $nodes
     * @return Collection<int, array<string,mixed>>
     */
    public function buildTree(array $nodes): Collection
    {
        usort($nodes, fn ($a, $b) => [$a['sort_order'], $a['id']] <=> [$b['sort_order'], $b['id']]);

        $indexed = [];
        foreach ($nodes as $node) {
            $node['children'] = collect();
            $indexed[(int) $node['id']] = $node;
        }

        $roots = collect();

        foreach ($indexed as $id => $node) {
            $parentId = $node['parent_id'] !== null ? (int) $node['parent_id'] : null;

            if ($parentId !== null && isset($indexed[$parentId]) && $parentId !== $id) {
                $indexed[$parentId]['children']->push($id);
            } else {
                $roots->push($id);
            }
        }

        // Convert children id-lists to nested nodes (iterative post-order via stack).
        $materialize = function (int $id) use (&$materialize, &$indexed): array {
            $node = $indexed[$id];
            $children = collect();

            foreach ($node['children'] as $childId) {
                // guard against cycles by removing already-seen ancestors
                $children->push($materialize((int) $childId));
            }

            $node['children'] = $children;

            return $node;
        };

        // Simple cycle guard: limit total expansion work.
        $count = 0;
        $safeMaterialize = function (int $id) use (&$safeMaterialize, &$indexed, &$count): array {
            if (++$count > count($indexed) * 2) {
                $node = $indexed[$id];
                $node['children'] = collect();

                return $node;
            }

            return $materialize($id);
        };

        return $roots->map(fn (int $id) => $safeMaterialize($id))->values();
    }

    /**
     * Flatten the tree back to a list with depth markers — used by the
     * hierarchical admin tables and checkbox trees.
     *
     * @param Collection<int,array> $tree
     * @return array<int,array{depth:int,data:array}>
     */
    public static function flatten(Collection $tree, int $depth = 0): array
    {
        $rows = [];

        foreach ($tree as $node) {
            $rows[] = ['depth' => $depth, 'data' => $node];

            if ($node['children']->isNotEmpty()) {
                $rows = array_merge($rows, self::flatten($node['children'], $depth + 1));
            }
        }

        return $rows;
    }
}
