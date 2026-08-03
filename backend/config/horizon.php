<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        /*
        | `feeds` (the catalog's official-status-feed ingestion) rides
        | supervisor-1 deliberately. `previews` and `content` below each earned
        | their own supervisor for a documented reason (a headless Chromium far
        | past this worker's memory ceiling; an rclone FUSE mount that can park a
        | process in an uninterruptible syscall). Feed ingestion is ordinary
        | outbound HTTP with a 10s timeout and a hard 60s-per-service floor, so
        | it has no such reason and a supervisor of its own would only add
        | processes to pay for. It sits LAST in the list because a single worker
        | drains these in order and a third party's status feed must never be
        | picked up ahead of a customer's uptime check.
        |
        | THIS LINE IS HALF OF A TWO-PLACE REGISTRATION. The other half is the
        | `queue:listen --queue=` list in composer.json's `scripts.dev`, which is
        | what drains the queue locally; this file is what drains it on the
        | server. With only the local half, the schedule fires, jobs queue,
        | `schedule:list` looks correct, and no feed is ever ingested in
        | production.
        */
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['scheduling', 'checks', 'processing', 'aggregates', 'ssl', 'ai', 'default', 'feeds'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],

        /*
        | Status-page preview rendering holds a headless Chromium for the whole
        | job, so it gets its own supervisor rather than riding supervisor-1:
        | one browser costs 300-500 MB, far past that worker's 128 MB ceiling,
        | and its process count would multiply the bill. Hence a high memory
        | ceiling and a deliberately small, per-environment process count.
        |
        | The 45s timeout is load bearing. It must stay ABOVE the render job's
        | own 40s timeout, so the job can record its failure before the worker
        | kills it, and BELOW the redis connection's retry_after, so a
        | still-running render is never released to a second worker. See the
        | invariant comment in config/queue.php; PreviewQueueConfigTest pins
        | the whole chain, including this supervisor's presence in every
        | environment below.
        */
        'previews' => [
            'connection' => 'redis',
            'queue' => ['previews'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 768,
            'tries' => 1,
            'timeout' => 45,
            'nice' => 0,
        ],

        /*
        | Monitor-content archive writes go through an rclone FUSE mount of a
        | Google Drive remote, which sustains roughly two file operations a second
        | and can park a process in an uninterruptible syscall. On supervisor-1
        | that stall would occupy a slot the monitoring checks need, so the
        | archive gets its own supervisor and a deliberately serial process count.
        | Memory stays modest: the job carries a spool-file PATH, and only the
        | compressed bytes (a fraction of the 1 MB body ceiling) are ever held.
        |
        | The 60s timeout is load bearing. It must stay ABOVE the archive job's own
        | 50s timeout, so the job can run the failure hook that releases its
        | claimed version row before the worker kills it, and BELOW the redis
        | connection's retry_after, so a still-running write is never released to a
        | second worker. See the invariant comment in config/queue.php;
        | ContentQueueConfigTest pins the whole chain, including this supervisor's
        | presence and its single process in every environment below.
        */
        'content' => [
            'connection' => 'redis',
            'queue' => ['content'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 192,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],

            /*
            | ONE process, and this ceiling is a CORRECTNESS bound rather than a
            | cost one. Do not raise it without first making the render
            | serialize per page.
            |
            | The render job is ShouldBeUniqueUntilProcessing, which releases its
            | lock the moment processing STARTS, so a second dispatch for the
            | same page can begin while the first is still holding a browser.
            | Both write the same single key (one PNG per page, overwritten in
            | place) and then stamp preview_rendered_at = now(). If the
            | earlier-started render finishes last, the stored file holds
            | pre-change pixels under a post-change timestamp, presented in the
            | editor under a customer-view label. That is exactly the drift this
            | whole feature exists to remove.
            |
            | Overlap is the NORMAL save path, not an exotic race: the client's
            | own component sync fires detach, attach and reorder in sequence and
            | every one of them dispatches a render.
            |
            | With a single process the queue itself serializes those renders, so
            | no lock is needed. A page renders a few times a day, so throughput
            | is not the constraint here. PreviewQueueConfigTest pins the 1.
            |
            | READ THE PRECONDITION, because this number alone does not carry
            | it. What serializes renders is exactly ONE consumer of `previews`
            | GLOBALLY, and this setting only bounds one Horizon master on one
            | host. Three things defeat it without anyone editing this value:
            | a second app instance running its own Horizon, the `queue:listen`
            | in composer's `dev` script running alongside Horizon locally, and
            | an ad-hoc `queue:work --queue=previews` started by hand to drain a
            | stuck queue (which is exactly what a live verification pass on a
            | dev machine found running). If the topology cannot guarantee one
            | consumer, the render needs a real mutex: wrap the render-and-write
            | in RenderStatusPagePreview::handle() with a Cache lock keyed on the
            | page id, which then makes this number a cost knob again.
            */
            'previews' => [
                'maxProcesses' => 1,
            ],

            /*
            | ONE process, and the mount is the reason. rclone over Google Drive
            | sustains roughly two file operations a second, and each archive write
            | costs two (a staged write plus a rename), so a second worker buys no
            | throughput and only lengthens the stall each one sits in. The write
            | itself stays CORRECT under concurrency (content-addressed target,
            | per-process staging name), so this is a throughput bound, not a
            | correctness one: raise it only with the mount measured first.
            */
            'content' => [
                'maxProcesses' => 1,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
            ],

            'previews' => [
                'maxProcesses' => 1,
            ],

            'content' => [
                'maxProcesses' => 1,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
