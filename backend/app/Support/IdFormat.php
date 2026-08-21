<?php

namespace App\Support;

/**
 * The format a client-supplied primary key has to pass BEFORE any validation
 * rule puts it in a query.
 *
 * PostgreSQL raises `SQLSTATE[22P02] invalid input syntax for type uuid` when a
 * non-uuid string is compared against a `uuid` column, and Laravel runs an
 * `exists` or `unique` rule as a real query. So `{"user_id": "x"}` on a nested
 * write answered 500 instead of 422 on five endpoints, measured against the dev
 * database:
 *
 *     select count(*) as "aggregate" from "team_user"
 *     where "user_id" = x and "team_id" = a26c03f7-...
 *
 * SQLite compares the same input happily and returns no rows, so the whole suite
 * saw a clean 422 and the defect only existed on the engine production runs. That
 * is the gap `.claude/rules/backend.md` names, and it is why CI runs the suite
 * once per engine.
 *
 * Two things this is NOT. It is not a substitute for the `exists` rule: the
 * format says the value could be an id, the query says it is one that the caller
 * may use. And it is not a hardcoded `uuid`, because the schema is UUID-OPTIONAL
 * (`magic-starter.use_uuids`, set from the database at install time); pinning
 * `uuid` here would reject every valid id in an integer-keyed install.
 *
 * Always paired with `bail` at the call site. Without it Laravel evaluates every
 * rule for the attribute, the `exists` query still runs on the malformed value,
 * and the 500 comes back with a field error attached to it.
 */
final class IdFormat
{
    /**
     * The format rules for one client-supplied key, in the order they run.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return config('magic-starter.use_uuids')
            ? ['string', 'uuid']
            : ['integer'];
    }
}
