<?php

namespace App\Support;

/**
 * Shared helper for LIKE-based search filters.
 *
 * Problem (Level 2 / S-02 / S-04 audit findings): every bus-module search
 * endpoint interpolated the user-supplied `search` term directly into the
 * LIKE pattern as `'%'.$term.'%'`. Sending `search=%` (URL-decoded) turned
 * the predicate into a wildcard match that returned EVERY row — an
 * information-disclosure / availability-finding, not a SQLi (Laravel bindings
 * still protect against injection — but the wildcard character makes the
 * filter ineffective as a search).
 *
 * Use this helper everywhere a LIKE pattern is built from user input.
 *
 * Default escape char: `\`. The default LIKE escape character for MySQL/MariaDB
 * (the project's DB) is `\`. For SQLite (test env) the escape is also `\`.
 * Callers that rely on a different escape char in their pattern should
 * pass it explicitly via `escapeChar`.
 */
class LikeWildcardEscaper
{
    /**
     * Escape `%`, `_`, and the escape character itself from a LIKE term so the
     * user cannot introduce a wildcard match by typing one of these chars.
     *
     * @param  string  $term        Raw user-supplied search term.
     * @param  string  $escapeChar  Escape char used in the LIKE pattern. Default `\`.
     * @return string  Escaped term safe to interpolate between LIKE wildcards.
     */
    public static function escape(string $term, string $escapeChar = '\\'): string
    {
        // Order matters: escape the escape char FIRST so we don't double-escape
        // the backslashes we add to % and _.
        return addcslashes($term, $escapeChar.'%_');
    }
}