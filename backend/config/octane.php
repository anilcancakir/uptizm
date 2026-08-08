<?php

use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\TickTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\Listeners\CloseMonologHandlers;
use Laravel\Octane\Listeners\CollectGarbage;
use Laravel\Octane\Listeners\DisconnectFromDatabases;
use Laravel\Octane\Listeners\EnsureUploadedFilesAreValid;
use Laravel\Octane\Listeners\EnsureUploadedFilesCanBeMoved;
use Laravel\Octane\Listeners\FlushLocaleState;
use Laravel\Octane\Listeners\FlushOnce;
use Laravel\Octane\Listeners\FlushTemporaryContainerInstances;
use Laravel\Octane\Listeners\FlushUploadedFiles;
use Laravel\Octane\Listeners\ReportException;
use Laravel\Octane\Listeners\StopWorkerIfNecessary;
use Laravel\Octane\Octane;

return [

    /*
    |--------------------------------------------------------------------------
    | Octane Server
    |--------------------------------------------------------------------------
    |
    | This value determines the default "server" that will be used by Octane
    | when starting, restarting, or stopping your server via the CLI. You
    | are free to change this to the supported server of your choosing.
    |
    | Supported: "roadrunner", "swoole", "frankenphp"
    |
    */

    'server' => env('OCTANE_SERVER', 'roadrunner'),

    /*
    |--------------------------------------------------------------------------
    | Force HTTPS
    |--------------------------------------------------------------------------
    |
    | When this configuration value is set to "true", Octane will inform the
    | framework that all absolute links must be generated using the HTTPS
    | protocol. Otherwise your links may be generated using plain HTTP.
    |
    */

    'https' => env('OCTANE_HTTPS', false),

    /*
    |--------------------------------------------------------------------------
    | Octane Listeners
    |--------------------------------------------------------------------------
    |
    | All of the event listeners for Octane's events are defined below. These
    | listeners are responsible for resetting your application's state for
    | the next request. You may even add your own listeners to the list.
    |
    */

    'listeners' => [
        WorkerStarting::class => [
            EnsureUploadedFilesAreValid::class,
            EnsureUploadedFilesCanBeMoved::class,
        ],

        RequestReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            ...Octane::prepareApplicationForNextRequest(),

            /*
             * Return the translator to the configured locale before each request.
             *
             * Octane's own default list does NOT include this (it ships
             * FlushTranslatorCache, which drops loaded messages but leaves the
             * locale alone), and `App::setLocale()` mutates the long-lived
             * translator singleton. So without this listener a visitor reading the
             * Turkish landing page leaves the worker set to `tr`, and the next
             * visitor to reach that same worker is served Turkish on a URL that
             * promises English. Cross-request state, and invisible under
             * `artisan serve` because there every request is a fresh process.
             */
            FlushLocaleState::class,
        ],

        RequestHandled::class => [
            //
        ],

        RequestTerminated::class => [
            // FlushUploadedFiles::class,
        ],

        TaskReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            //
        ],

        TaskTerminated::class => [
            //
        ],

        TickReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            //
        ],

        TickTerminated::class => [
            //
        ],

        OperationTerminated::class => [
            FlushOnce::class,
            FlushTemporaryContainerInstances::class,
            // DisconnectFromDatabases::class,
            // CollectGarbage::class,
        ],

        WorkerErrorOccurred::class => [
            ReportException::class,
            StopWorkerIfNecessary::class,
        ],

        WorkerStopping::class => [
            CloseMonologHandlers::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Warm / Flush Bindings
    |--------------------------------------------------------------------------
    |
    | The bindings listed below will either be pre-warmed when a worker boots
    | or they will be flushed before every new request. Flushing a binding
    | will force the container to resolve that binding again when asked.
    |
    */

    'warm' => [
        ...Octane::defaultServicesToWarm(),
    ],

    'flush' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Octane Swoole Tables
    |--------------------------------------------------------------------------
    |
    | While using Swoole, you may define additional tables as required by the
    | application. These tables can be used to store data that needs to be
    | quickly accessed by other workers on the particular Swoole server.
    |
    */

    'tables' => [
        'example:1000' => [
            'name' => 'string:1000',
            'votes' => 'int',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Octane Swoole Cache Table
    |--------------------------------------------------------------------------
    |
    | While using Swoole, you may leverage the Octane cache, which is powered
    | by a Swoole table. You may set the maximum number of rows as well as
    | the number of bytes per row using the configuration options below.
    |
    */

    'cache' => [
        'rows' => 1000,
        'bytes' => 10000,
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watching
    |--------------------------------------------------------------------------
    |
    | The following list of files and directories will be watched when using
    | the --watch option offered by Octane. If any of the directories and
    | files are changed, Octane will automatically reload your workers.
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
        '.env',
    ],

    /*
    |--------------------------------------------------------------------------
    | Garbage Collection Threshold
    |--------------------------------------------------------------------------
    |
    | When executing long-lived PHP scripts such as Octane, memory can build
    | up before being cleared by PHP. You can force Octane to run garbage
    | collection if your application consumes this amount of megabytes.
    |
    */

    'garbage' => 50,

    /*
    |--------------------------------------------------------------------------
    | Maximum Execution Time
    |--------------------------------------------------------------------------
    |
    | The following setting configures the maximum execution time for requests
    | being handled by Octane. You may set this value to 0 to indicate that
    | there isn't a specific time limit on Octane request execution time.
    |
    | This is one of the walls a slow request runs into. They have to stay in
    | this order, innermost first, or the innermost one never gets to do its job.
    | The list is deliberately not counted: it grew by one the day a wall nobody
    | had written down turned out to be the binding one, and a number in this
    | sentence would have gone stale rather than the list being wrong.
    |
    |   an UNIDENTIFIED proxy wall (60, measured, see below)
    |     < Octane, here (90)
    |     < Cloudflare's origin timeout (~100, not ours to set)
    |     < the Flutter client (`lib/config/network.dart`, 120)
    |
    | `ai.request_budget_seconds` (150) is no longer part of this chain, and it
    | is worth saying explicitly because it USED TO be the innermost entry.
    | `POST /monitors/analyze`'s model calls ran inside this same request, so the
    | budget had to clear every wall above it. They now run inside
    | `App\Jobs\AnalyzeMonitorJob` on its own `analyze` Horizon queue, so 150 is
    | a WORKER bound: it has to clear that job's own `$timeout` (160) under the
    | `analyze` supervisor's `timeout` (170) under the `redis-analyze`
    | connection's `retry_after` (200), none of which are on this list.
    | `AnalyzeQueueConfigTest` pins that chain; `AiDeadlineTest` pins 150 against
    | the supervisor half of it. It is named here anyway, out of the ordering,
    | because this comment is the one place the whole picture used to live, and
    | the next reader chasing the 60 below should learn that the budget moved
    | rather than assume it vanished.
    |
    | The probe moved the other way, in effect: it stays in the request, with
    | its own 30 second timeout (`MonitorController::transientMonitor()`), so it
    | no longer shares a clock with the AI budget the way the next paragraph
    | describes for when it did.
    |
    | This wall, at 90, is NOT the binding one, and believing it was cost an
    | operator two 504s while the AI budget still ran inside this request.
    | Something between the client and this worker cuts at 60 seconds regardless:
    | measured at 60.1 on 2026-08-07, against an api vhost whose
    | `proxy_read_timeout` is 3600 and this setting at 90. It is not the
    | Cloudflare line above either: that one is documented at ~100, and its own
    | timeout error is 524 rather than the 504 we measured. So the owner of the 60
    | is genuinely unknown, and it still sits on the path of every OTHER request
    | this server answers even after analyze moved off it. It is pinned as an
    | observation with its evidence in `AiDeadlineTest::OBSERVED_PROXY_WALL_SECONDS`.
    | Identify it before trusting this list again; that is step 13 of the
    | async-analyze plan, run before the deploy that removes the only known
    | reproducer.
    |
    | It was 30, which is below the AI budget AND below the 30 second probe
    | timeout `MonitorController::transientMonitor()` sets, so a slow provider on
    | `POST /monitors/analyze` produced a PHP fatal inside Guzzle's curl handler
    | and a 500, instead of the graceful degrade to a deterministic suggestion
    | that the gateway is written to perform on exactly that condition. The
    | timeout that was supposed to protect the request sat ABOVE the wall that
    | killed it, so it could never fire. Measured in production on 2026-08-07.
    |
    */

    'max_execution_time' => (int) env('OCTANE_MAX_EXECUTION_TIME', 90),

];
