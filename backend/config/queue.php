<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    | Invariant: every connection's "retry_after" MUST stay strictly greater
    | than the longest worker/job "timeout" (default worker timeout is 60s).
    | If a still-running billing/webhook job outlives "retry_after", the queue
    | re-dispatches it to a second worker and the SAME Stripe event is processed
    | twice. The 90s floor here keeps a comfortable margin over the 60s timeout;
    | idempotency (ProcessedWebhookEvent) is the second line of defense.
    |
    | One job outgrew that 90 rather than breaking the invariant: monitor analyze
    | needs 160 seconds, so it got its own connection ("redis-analyze" below,
    | retry_after 200) instead of a raise here. The invariant is per connection,
    | so read it as: no worker may outlive the retry_after of the connection it
    | names.
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            // Block on Redis instead of waking every --sleep seconds. Idle workers stop
            // polling and a queued job is picked up immediately rather than up to a
            // sleep cycle later. Never set this to 0: that blocks SIGTERM handling until
            // the next job arrives, which stalls Horizon restarts and deploys.
            'block_for' => (int) env('REDIS_QUEUE_BLOCK_FOR', 5),
            'after_commit' => false,
        ],

        /*
        | The analyze queue's own connection, and it exists for exactly ONE
        | reason: to carry a `retry_after` above the shared 90 the invariant note
        | at the top of this file governs.
        |
        | App\Jobs\AnalyzeMonitorJob is the first job in this repo whose timeout
        | crosses that 90. It funds up to three model calls plus their
        | serialization out of `ai.request_budget_seconds` (150), so its own
        | `$timeout` is 160 and the Horizon supervisor above it is 170. On the
        | shared `redis` connection Redis would release a still-running analyze
        | back to the ready list at 90 seconds and a second worker would pick the
        | SAME run up: two AI spends, two broadcast streams, and two writes into
        | one run's state. That double-spend is precisely what moving analyze off
        | the request thread is meant to make structurally impossible, so the
        | connection is part of the correctness argument and not a tidiness one.
        |
        | 200 is the smallest round number above the 170 supervisor timeout. Do
        | NOT solve this by raising `redis` instead: that connection carries the
        | customer uptime checks, and a stuck check would then sit 200 seconds
        | before re-dispatch rather than 90.
        |
        | The full chain, pinned by Tests\Unit\AnalyzeQueueConfigTest:
        | retry_after 200 > supervisor timeout 170 > job $timeout 160 >
        | ai.request_budget_seconds 150.
        |
        | `queue` defaults to `analyze` rather than to `default`, and that
        | default is load bearing. Both connections point at the same Redis
        | connection, so the list a job lands in is `queues:{queue}` either way:
        | an `onConnection('redis-analyze')` that forgot its `onQueue()` would
        | land in the very same `queues:default` list supervisor-1 drains with a
        | 60-second timeout, and a 150-second analyze there is killed at 60 with
        | nothing to show the operator.
        |
        | READ THE PRECONDITION, because this value alone does not carry it.
        | `retry_after` is a property of the CONSUMER's connection config, not of
        | the Redis list, and both connections share one list namespace. So a
        | worker that drains `analyze` while naming a different connection gets
        | that connection's number instead: `composer dev`'s single
        | `queue:listen` runs on `queue.default` (redis, 90) and would re-run a
        | >90s analyze once it finished the first pass, and an ad-hoc
        | `php artisan queue:work --queue=analyze` does the same. Drain this
        | queue by hand as:
        |
        |   php artisan queue:work redis-analyze --queue=analyze --tries=1
        |
        | The connection is POSITIONAL. `--connection=redis-analyze` is not a
        | thing (`WorkCommand`'s signature takes it as an argument) and errors
        | out with `The "--connection" option does not exist.`, which is at
        | least loud; this comment carried that wrong flag until it was run.
        */
        'redis-analyze' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_ANALYZE_QUEUE', 'analyze'),
            'retry_after' => (int) env('REDIS_ANALYZE_QUEUE_RETRY_AFTER', 200),
            'block_for' => (int) env('REDIS_ANALYZE_QUEUE_BLOCK_FOR', 5),
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
