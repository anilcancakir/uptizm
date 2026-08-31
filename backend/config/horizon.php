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
        | The queues a customer is waiting on, and the only ones that keep a
        | process pool each. They are one pipeline plus its outbox:
        | `scheduling` fans out to `checks`, `checks` dispatches to `processing`,
        | and `default` carries what the product then tells the customer
        | (DispatchEscalationStep, AnnounceIncident, and the IncidentOpened /
        | IncidentResolved / IncidentEscalated notifications, none of which call
        | `onQueue()` and so land there by omission).
        |
        | Two members of that list read like background work and are not, so
        | both are worth naming rather than leaving to be rediscovered.
        |
        | `default` looks like the leftovers queue and it is the paging path.
        | Behind a 60-second feed ingest it is an alert that reaches an on-call
        | engineer a minute after it mattered.
        |
        | `ai` looks like the model-call queue, and most of it is: weekly
        | digests, anomaly triage, suggestion sweeps, none of which anyone is
        | waiting on. But `TranslateStatusPageText` rides it too, fanned out
        | from ThresholdEvaluator, PublishAiIncidentUpdate, PerformSslCheck and
        | the maintenance and status-page controllers, and what it translates is
        | the incident titles, postmortem bodies and update messages a reader
        | sees on the PUBLIC status page. Queued behind a feed tick that is a
        | non-source-locale reader looking at untranslated text during the one
        | event the page exists for. It degrades rather than breaks, which is
        | exactly why nobody would have reported it.
        |
        | `balance` stays `auto` here for that reason. It opens a pool per
        | queue, which costs a resident worker each, and that cost is the price
        | of these five never queueing behind one another. The tolerant queues
        | do not need it and moved to `background` below.
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
            'queue' => ['scheduling', 'checks', 'processing', 'default', 'ai'],
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
        | The tolerant queues, collapsed into ONE pool rather than three.
        |
        | Horizon's idle cost is the QUEUE COUNT of a supervisor, not its
        | `maxProcesses`, and nothing in this file used to say so.
        | `Supervisor::createProcessPools()` opens a pool per queue whenever
        | `balance` is `auto` or `simple`, and every pool holds at least
        | `minProcesses` workers, which Horizon refuses to let fall below one:
        | `ProvisioningPlan::convert()` throws "must be greater than 0" at boot,
        | so scaling a pool to zero is not available as a fix. These three
        | queues therefore held three workers resident around the clock while
        | all three measured zero depth: feeds 110 MB, aggregates 42, ssl 34,
        | measured on the production box on 2026-08-30.
        |
        | `balance` is `off`, which builds a single pool for the whole list. The
        | floor is one worker, and the autoscaler still adds more up to
        | `maxProcesses` once the summed backlog across the three exceeds the
        | running count. `autoScalingStrategy` is absent rather than forgotten:
        | the non-balancing branch of AutoScaler::numberOfWorkersPerQueue() sizes
        | on backlog directly and never reads it.
        |
        | The order is the drain order now, not a list. Laravel's worker walks
        | `--queue=` left to right and returns on the first job it finds, so
        | `feeds` last finally means what this file always claimed: a third
        | party's status feed is never picked up ahead of our own work. Under
        | `balance: auto` that sentence was decorative, because every queue had a
        | dedicated worker and there was no single worker to order anything for.
        |
        | What this saves is paid for in isolation: one worker on a 60-second
        | feed ingest is a worker not polling `ssl`. Membership here is therefore
        | decided by whether anything downstream is WAITING, not by how heavy the
        | job is, and the two queues that failed that test are named above:
        | `default` carries the paging path and `ai` carries the public status
        | page's translations, so both stay on their own pools.
        | BackgroundQueueConfigTest pins the split, the order, and the fact that
        | those two stayed out of it.
        */
        'background' => [
            'connection' => 'redis',
            'queue' => ['ssl', 'aggregates', 'feeds'],
            'balance' => 'off',
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
        | The 85s timeout is load bearing. It must stay ABOVE the archive job's own
        | 80s timeout, so the job can run the failure hook that releases its
        | claimed version row before the worker kills it, and BELOW the redis
        | connection's retry_after (90), so a still-running write is never released
        | to a second worker. See the invariant comment in config/queue.php;
        | ContentQueueConfigTest pins the whole chain, including this supervisor's
        | presence and its single process in every environment below.
        |
        | It was 60 over a 50s job until 2026-08-29, and that budget was losing
        | writes: measured Drive latency through this mount is bimodal (p50 735 ms,
        | p90 17.3 s, max 34.5 s over twelve cold writes), and the failure rate had
        | climbed from 6% to 39% in five days. 85/80 absorbs the measured tail and
        | is the most this connection allows. The reasoning lives on
        | ArchiveContent::$timeout, next to the number it justifies.
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
            'timeout' => 85,
            'nice' => 0,
        ],

        /*
        | Monitor analyze makes up to three model calls behind one operator
        | click, and `ai.request_budget_seconds` funds 150 seconds of wall time
        | for them. supervisor-1 cannot host that, for two independent reasons
        | and only the first is about the analyze itself: its `timeout` is 60, so
        | the worker would kill a 150-second run at 60 and the operator would get
        | nothing back; and it runs the customer uptime `checks` at production
        | maxProcesses 7, so an analyze parked in one of those slots delays
        | outage detection, which is the one thing this product exists to do.
        |
        | TWO processes, and unlike `previews` and `content` above this is a COST
        | bound rather than a correctness one: the run is already single-in-flight
        | per team through a cache lock, so this number only decides how many
        | teams may analyze at once. Two suits a product that meters analyze per
        | team, and each process has to be able to hold a full response body.
        |
        | `PublishAiIncidentUpdate` shares these two processes, because it is the
        | same shape (an analysis, then a draft, holding the same evidence) and
        | needs the same connection. It is rare next to operator analyze and was
        | measured before it was moved here: two monitors carry `ai_auto_updates`
        | against 65 analyses in seven days, so it does not crowd the slots an
        | operator is waiting on. Raise this number, not the queue list, if that
        | ever stops being true.
        |
        | `memory` 512 is sized on RSS rather than on the payload. The job carries
        | a probe response body (1 MB ceiling) and JSON-encodes it into a prompt
        | fence, so that body exists two or three times over inside one worker,
        | before the model client's own buffers. supervisor-1's 128 would restart
        | the worker mid-run, and with `tries` 1 a restart is a lost run the
        | operator is watching for.
        |
        | The 170 timeout is load bearing. It must stay ABOVE the analyze job's
        | own 160s timeout, so the job can run failed(), which writes the
        | terminal state and broadcasts it; without that the operator's form spins
        | forever. And it must stay BELOW its connection's retry_after, so a
        | still-running analyze is never released to a second worker.
        |
        | `connection` is `redis-analyze` and NOT `redis`, and that is the
        | load-bearing half. The shared connection's retry_after is 90, below
        | this timeout, so on it Redis would hand every analyze that passes 90
        | seconds to a second worker: two AI spends, two broadcast streams, two
        | writers on one run. See the invariant comment in config/queue.php;
        | AnalyzeQueueConfigTest pins the whole chain, including this supervisor's
        | presence in every environment below.
        |
        | This was written claiming analyze was "the first job in the repo to
        | cross that 90". It was not: `PublishAiIncidentUpdate` declared 180 and
        | rode the shared connection, and had been double-delivered in production
        | since 2026-08-17. The claim was made in good faith by reading the jobs
        | that had a chain, which is the one place the answer was not. It is a
        | sweep now rather than a sentence: JobTimeoutFitsItsConnectionTest reads
        | every job in app/Jobs and fails on any timeout its connection cannot
        | carry.
        */
        'analyze' => [
            'connection' => 'redis-analyze',
            'queue' => ['analyze'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 512,
            'tries' => 1,
            'timeout' => 170,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            /*
            | SEVEN, down from ten when this supervisor still carried eight
            | queues. Per-queue burst is UNCHANGED rather than improved, and the
            | arithmetic is worth writing down because it is not obvious: Horizon
            | caps one pool at `maxProcesses - ((pools - 1) * minProcesses)`, so
            | across the old eight pools any single queue could reach 10 - 7 = 3,
            | and across these five it reaches 7 - 4 = 3. The same three, with
            | none of them now spendable on a status feed.
            |
            | The total is what the cut is for, and on this host it matters as
            | much as the split. Adding the `background` ceiling to an unchanged
            | ten would have raised the whole Horizon ceiling from 14 workers to
            | 17 while lowering the idle floor, trading a steady-state saving for
            | a worse worst case on a box that was already paging. At seven the
            | ceiling stays exactly 14.
            */
            'supervisor-1' => [
                'maxProcesses' => 7,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],

            /*
            | THREE at the ceiling, ONE resident. A single pool serves all four
            | tolerant queues, so this number is only reached when their summed
            | backlog does, and the steady state on this host is one worker.
            | Three is enough that a 60-second feed ingest cannot hold up the
            | daily aggregate behind it for long, and small enough that the
            | ceiling stays under what the four dedicated workers used to cost
            | while doing nothing.
            */
            'background' => [
                'maxProcesses' => 3,
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

            /*
            | TWO processes, a cost bound. The per-team in-flight lock is what
            | prevents one team spending its meter twice, so this number only
            | caps how many DIFFERENT teams may analyze at once; raising it costs
            | 512 MB and two model calls apiece. Zero is the failure that matters
            | here: Horizon's ProvisioningPlan skips a supervisor provisioning no
            | processes, the analyze queue is then consumed by nobody, and every
            | run sits at `queued` until its cache key expires while the operator
            | watches five spinners.
            */
            'analyze' => [
                'maxProcesses' => 2,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
            ],

            'background' => [
                'maxProcesses' => 2,
            ],

            'previews' => [
                'maxProcesses' => 1,
            ],

            'content' => [
                'maxProcesses' => 1,
            ],

            'analyze' => [
                'maxProcesses' => 2,
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
