<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Content Archive
    |--------------------------------------------------------------------------
    |
    | Configuration for the monitor-content archive: hash-deduped, compressed
    | snapshots of HTTP monitor response bodies, kept for a bounded retention
    | window and stored on the dedicated `content` disk (see
    | config/filesystems.php).
    |
    */

    // The largest response body, in bytes, that the archive will store. A
    // larger body is truncated or skipped upstream rather than archived whole.
    'max_bytes' => (int) env('CONTENT_ARCHIVE_MAX_BYTES', 1048576),

    // How long an archived snapshot is kept before pruning.
    'retention_days' => (int) env('CONTENT_ARCHIVE_RETENTION_DAYS', 30),

    // The filesystem disk (config/filesystems.php) archived snapshots are
    // written to. Referenced by this config key, never by `filesystems.default`
    // (see App\Models\StatusPage::PREVIEW_DISK for the same precedent): a
    // deploy that repoints the default disk must not silently move the archive.
    'disk' => env('CONTENT_ARCHIVE_DISK', 'content'),

    // The queue archive-writing jobs are dispatched onto.
    'queue' => env('CONTENT_ARCHIVE_QUEUE', 'content'),

    // The connection whose `retry_after` (300) is the wall the archive's 270s
    // budget fits under; the shared `redis` connection's 90 cannot hold it. See
    // the `redis-content` block in config/queue.php for why it exists at all.
    //
    // Overridable because `phpunit.xml` sets it to `sync`. The suite runs the
    // archive INLINE and asserts the blob it produced, so a job pinned to a redis
    // connection would enqueue instead of running and take that coverage away
    // without failing anything.
    'connection' => env('CONTENT_ARCHIVE_QUEUE_CONNECTION', 'redis-content'),

    // Bumped whenever the body-normalization algorithm changes, so an archived
    // hash can be recomputed instead of assumed stale-equivalent to a new one.
    'normalizer_version' => 2,

    // Circuit breaker for the archive write path: once the `queue` above holds
    // more than this many pending jobs, the check pipeline stops enqueueing new
    // archive writes rather than let an unbounded backlog build. MUST be a real
    // integer, never null, because the breaker compares with `>` and `1 > null`
    // is true in PHP, which would silently disable archiving the moment the
    // queue is non-empty.
    'queue_backlog_limit' => (int) env('CONTENT_ARCHIVE_QUEUE_BACKLOG_LIMIT', 500),

    // A file that must exist inside the disk root for the mount to be considered
    // live, guarding against the failure mode where a dead FUSE mount leaves an
    // ordinary empty directory behind and the `local` driver happily fills the
    // underlying disk. Null DISABLES the guard, which is the right default: the
    // local root is an ordinary directory nobody seeds, so a non-null default
    // would abort every write in development and in tests. Production sets it.
    'sentinel' => env('CONTENT_ARCHIVE_SENTINEL'),

    // When the archive is losing enough writes to be worth an operator's
    // attention. Read by App\Jobs\AlarmContentArchiveFailures, which logs once
    // per crossing rather than once per tick.
    //
    // EVERY NUMBER HERE IS REPLAYED AGAINST REAL HISTORY, and the first draft
    // was not. It shipped with a 60-minute window and a 20-attempt floor, and
    // replaying 143 hourly ticks over 2026-08-24..29 showed it firing ZERO
    // times: the archive attempts about 12 writes an hour, so a 60-minute
    // window never reached its own minimum and every tick skipped. An alarm
    // gated on a volume nobody measured is an alarm that cannot fire, which is
    // exactly the failure it was written to end.
    //
    // 180 minutes, measured: over 40 sampled windows the attempt count ran
    // min 34, p50 35, against min 11 / p50 12 for 60 minutes. Replayed with
    // these values the alarm fires on 2026-08-24 at 20%, which is the first day
    // the degradation was visible and five days before anyone noticed it.
    //
    // The raise bar was 0.15, chosen from the history where this path sat at
    // 5.8% on 2026-08-25 and reached 30.6% by 2026-08-28. It is 0.10 now, and
    // the reason is not that 15% became wrong: it is that the assumption under
    // it did. The bar was set to separate "the ordinary tail of a slow Google
    // Drive upload" from a degradation, on the understanding that a failed write
    // was a DELAY. It is not. `ArchiveContent::failed()` releases the claim and
    // the next check archives whatever the content has become by then, so a
    // failure loses that version permanently: 13 of 13 releases measured on
    // 2026-08-30..31 were never archived under any later row.
    //
    // Replayed against the post-fix history the way every other number here was
    // derived: 44 windows of 180 minutes over 2026-08-30..31, none of them under
    // the attempt floor, giving p50 2.9%, p90 5.7%, max 8.8%. So 0.10 sits above
    // every window that period actually produced (zero false alarms) while
    // catching a degradation at half the level 0.15 needed. 0.075 would have
    // fired once, on an 8.8% window that was the accepted baseline, which is how
    // an alarm starts being ignored.
    //
    // RE-DERIVE THESE ONCE THE STAT FIX HAS RUN A WEEK. The 2.9% baseline was
    // measured while every archive still spent a rate-limited directory listing
    // (see ContentArchive::store()). With that gone the baseline should fall, and
    // a bar tuned to the old floor is a bar that has quietly stopped meaning
    // anything.
    //
    // `clear_rate` is hysteresis, and it is not decoration. A rate hovering
    // around a single threshold crosses it repeatedly: replayed on one bar the
    // alarm fired 10 times in six days, and with a clear bar at half the raise
    // bar it fired TWICE, once when the degradation began and once when it got
    // worse. That is the difference between a signal and a thing people mute.
    // Half of 0.10 is 0.05, which stays above the measured p50 so an ordinary
    // window clears rather than latching the alarm on.
    'alarm' => [
        'window_minutes' => (int) env('CONTENT_ARCHIVE_ALARM_WINDOW_MINUTES', 180),
        'failure_rate' => (float) env('CONTENT_ARCHIVE_ALARM_FAILURE_RATE', 0.10),
        'clear_rate' => (float) env('CONTENT_ARCHIVE_ALARM_CLEAR_RATE', 0.05),
        'minimum_attempts' => (int) env('CONTENT_ARCHIVE_ALARM_MINIMUM_ATTEMPTS', 20),
    ],

    // The allowed response content types, pinned exactly: this list travels on
    // the HMAC-signed probe spec, and the TypeScript Cloudflare Worker
    // reimplements the matcher below from it, so its shape and order must never
    // drift.
    //
    // Every entry is lowercase. An entry ending in `/` is a PREFIX rule; any
    // other entry is an EXACT match against the media type. The match itself
    // is implemented once in App\Support\Monitoring\ContentTypeAllowList so the
    // semantics live in tested code, not in a per-caller reimplementation:
    // lowercase the raw header, cut at the first `;` to drop parameters such
    // as `charset=utf-8`, trim, then accept when the result equals an exact
    // entry or begins with a prefix entry. Under that rule
    // `text/html; charset=utf-8` is accepted via the `text/` prefix and
    // `application/pdf` is rejected; a null or empty header is rejected.
    'allowed_content_types' => [
        'text/',
        'application/json',
        'application/xml',
    ],
];
