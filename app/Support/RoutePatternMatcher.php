<?php

namespace App\Support;

/**
 * Secure route-name pattern matching.
 *
 * A menu's route_name may be:
 *   - an exact named route:            "admin.users.index"
 *   - a resource wildcard pattern:     "admin.users.*"  (covers admin.users.index, etc.)
 *
 * Matching is SEGMENT-BASED (dot-delimited), never substring-based, so:
 *   "admin.users.*"  matches "admin.users.index"      ✓
 *   "admin.users.*"  matches "admin.users_roles"      ✗  (no unsafe prefix match)
 *   "admin.*.index"  matches "admin.users.index"      ✓  (single-segment wildcard)
 *   "admin.users.*"  does NOT match "admin.roles.index" ✗
 */
final class RoutePatternMatcher
{
    public static function matches(string $pattern, string $routeName): bool
    {
        $pattern = strtolower(trim($pattern));
        $routeName = strtolower(trim($routeName));

        if ($pattern === '' || $routeName === '') {
            return false;
        }

        // Full wildcard — deliberately not exposed through UI validation,
        // but handled defensively here.
        if ($pattern === '*') {
            return true;
        }

        $patternSegments = explode('.', $pattern);
        $routeSegments = explode('.', $routeName);

        // Trailing "*" means "this segment and any deeper segments".
        $trailingStar = end($patternSegments) === '*';

        if ($trailingStar) {
            array_pop($patternSegments); // drop the trailing star
            // route must have AT LEAST as many leading segments as the prefix
            if (count($routeSegments) < count($patternSegments)) {
                return false;
            }
        } else {
            if (count($routeSegments) !== count($patternSegments)) {
                return false;
            }
        }

        foreach ($patternSegments as $i => $segment) {
            if ($segment === '*') {
                continue; // single-segment wildcard
            }

            if (! isset($routeSegments[$i]) || $routeSegments[$i] !== $segment) {
                return false;
            }
        }

        return true;
    }

    /** Validate a pattern entered by an administrator. */
    public static function isValidPattern(?string $pattern): bool
    {
        if ($pattern === null || $pattern === '') {
            return true; // nullable (heading menus)
        }

        // letters/digits/underscore/dot/hyphen, wildcards only as whole segments
        return (bool) preg_match('/^[a-z0-9_\-]+(\.[a-z0-9_\-]+)*(\.\*)?$/i', $pattern)
            && ! str_contains($pattern, '..');
    }
}
