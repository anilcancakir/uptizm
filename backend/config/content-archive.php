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
