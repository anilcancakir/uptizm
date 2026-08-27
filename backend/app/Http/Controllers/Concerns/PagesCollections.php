<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Shared plumbing for the cursor-paginated `index` actions.
 *
 * Six collections page this way now (a monitor's check history plus the five
 * team rosters), and every one of them needs the same two things: a `per_page`
 * read that a client cannot use to ask for the whole table, and an ordering a
 * cursor can actually walk.
 *
 * CURSOR, NOT OFFSET, and the difference is correctness rather than taste.
 * These collections are written to while an operator reads them: a check lands
 * every interval, an incident opens the moment something breaks. An offset
 * names a position in a result set that has already moved by the time page two
 * is asked for, so a row inserted at the head shifts everything down and the
 * reader sees row 15 twice and never sees row 16. A cursor names a position in
 * the ORDERING instead, so it cannot drift.
 *
 * That only holds if the ordering is unique, which is why {@see cursorOrder()}
 * exists: every roster here sorts by a timestamp, and two rows created in the
 * same second are a tie the cursor cannot resolve. It would silently skip or
 * repeat exactly at a page boundary, which is the hardest kind of bug to see.
 */
trait PagesCollections
{
    /**
     * The clamped `per_page` for this request.
     *
     * The floor stops a `?per_page=0` turning into an infinite loop of empty
     * pages on the client. The ceiling is the real point: without it a client
     * asks for one page of everything and the pagination is decoration.
     */
    protected function perPage(Request $request, int $default = 25, int $max = 200): int
    {
        $perPage = (int) $request->query('per_page', (string) $default);

        return max(1, min($perPage, $max));
    }

    /**
     * Order [$query] newest-first by [$column], with the primary key breaking
     * ties so a cursor can walk it.
     *
     * Exists so the tiebreaker cannot be forgotten. Every roster here sorts by
     * a timestamp that is not unique: two monitors created in the same second,
     * two incidents opened by the same outage. `cursorPaginate` builds its
     * token out of the order-by columns, so a tie at a page boundary is a row
     * the reader either sees twice or never sees at all, and nothing about the
     * response says so.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function cursorOrder(
        Builder $query,
        string $column,
    ): Builder {
        return $query->orderByDesc($column)->orderByDesc($query->getModel()->getKeyName());
    }
}
