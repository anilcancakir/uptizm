<?php

use App\Support\Sentry\SentryScrubber;
use App\Support\Sentry\SentryTraceSampler;

/**
 * Sentry Laravel SDK configuration file.
 *
 * @see https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/
 */
return [

    /*
     * The DSN, and the environment gate around it.
     *
     * An absent DSN disables the SDK completely: `Integration::handles()` in
     * `bootstrap/app.php` still runs, the facade still resolves, and nothing is
     * transmitted. That is the mechanism this gate uses.
     *
     * WHY A GATE RATHER THAN JUST LEAVING IT OUT OF THE LOCAL `.env`. Relying on
     * absence means the protection is "nobody pasted the production DSN into
     * their own env file", which is exactly the sort of thing that happens once
     * while debugging and then stays. Development noise filed against the
     * production project is not just clutter: this org's plan carries
     * `onDemandMaxSpend = 0`, so quota consumed by a local loop is quota that
     * silently DROPS real production events for the rest of the month.
     *
     * `production` and nothing else, deliberately. `local` and `testing` are the
     * two that exist today, and a future `staging` should be added here
     * consciously (with its own project, not this one) rather than inherited by
     * a wildcard.
     *
     * @see https://docs.sentry.io/concepts/key-terms/dsn-explainer/
     */
    'dsn' => env('APP_ENV') === 'production'
        ? env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN'))
        : null,

    // @see https://spotlightjs.com/
    // 'spotlight' => env('SENTRY_SPOTLIGHT', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#logger
    // 'logger' => Sentry\Logger\DebugFileLogger::class, // By default this will log to `storage_path('logs/sentry.log')`

    // The release version of your application
    // Example with dynamic git hash: trim(exec('git --git-dir ' . base_path('.git') . ' log --pretty="%h" -n1 HEAD'))
    'release' => env('SENTRY_RELEASE'),

    // When left empty or `null` the Laravel environment will be used (usually discovered from `APP_ENV` in your `.env`)
    'environment' => env('SENTRY_ENVIRONMENT'),

    // Override the organization ID used for trace continuation checks.
    'org_id' => env('SENTRY_ORG_ID') === null ? null : (int) env('SENTRY_ORG_ID'),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#sample_rate
    'sample_rate' => env('SENTRY_SAMPLE_RATE') === null ? 1.0 : (float) env('SENTRY_SAMPLE_RATE'),

    /*
     * NOT SET, deliberately, and the sampler below is why.
     *
     * `traces_sampler` overrides `traces_sample_rate` whenever both exist, so
     * leaving a number here would be a value that looks authoritative and is
     * silently ignored. {@see SentryTraceSampler} carries the rates and the
     * arithmetic behind each of them.
     *
     * @see https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#traces_sample_rate
     */
    'traces_sample_rate' => null,

    /*
     * Per-transaction sampling, because this application's workloads differ by
     * four orders of magnitude in volume: ~43M probe jobs a month against a few
     * hundred AI analyze runs. One rate cannot serve both, and Sentry bills
     * spans on what it RECEIVES, so this callback is the only cost lever there
     * is. An array callable rather than a closure, for the `config:cache`
     * reason spelled out at `before_send` below.
     *
     * @see https://docs.sentry.io/platforms/php/guides/laravel/configuration/sampling/
     */
    'traces_sampler' => [SentryTraceSampler::class, 'sample'],

    /*
     * Profiling, which is RELATIVE to the sampler above rather than absolute.
     *
     * The two multiply: an API request is sampled at 0.2, so at 0.1 here one in
     * fifty API requests carries a profile. That relationship is easy to read
     * backwards and expensive when you do, since profiling is billed by
     * duration rather than by count.
     *
     * IT ONLY EVER PROFILES THE QUEUE, and that is a property of the server
     * rather than a setting. Measured on the production box: two separate PHP
     * builds run this application. Octane serves HTTP through the frankenphp
     * binary, which carries its OWN embedded PHP (8.5.6, ZTS, modules in
     * `/usr/lib/frankenphp/modules`), while Horizon and the scheduler run on the
     * system CLI (8.5.7, NTS, `/usr/lib/php/20250925`). `pecl install excimer`
     * builds against the CLI, and the two are incompatible by ABI as well as by
     * path, so the extension the queue loads is invisible to the web tier.
     *
     * That split is acceptable here rather than merely tolerated: the work worth
     * profiling is the long work, and the long work is queued. `analyze` runs
     * for up to 160 seconds and nobody can currently say where it spends them.
     * The HTTP tier keeps transaction and span timing, including per-query
     * duration and the call site of anything over 100ms.
     *
     * A missing excimer is SILENT, not an error, so this rate staying at 0.1
     * costs nothing on the web tier and does not need a second config key.
     *
     * @see https://docs.sentry.io/platforms/php/guides/laravel/profiling/
     */
    'profiles_sample_rate' => env('SENTRY_PROFILES_SAMPLE_RATE') === null ? 0.1 : (float) env('SENTRY_PROFILES_SAMPLE_RATE'),

    // Only continue incoming traces when the organization IDs are compatible with this SDK instance.
    'strict_trace_continuation' => env('SENTRY_STRICT_TRACE_CONTINUATION', false),

    /*
     * Structured logs, which are a SEPARATE product from breadcrumbs and draw
     * on a separate quota (5GB a month, not the span allowance).
     *
     * Breadcrumbs already attach the last few log lines to each event, so this
     * is not about error context; it is about being able to search the log
     * itself without opening an SSH session. The volume is bounded by the same
     * thing that bounds `laravel.log`: production runs `LOG_LEVEL=warning`, so
     * only warnings and above are written at all.
     *
     * TWO CHANNELS DELIBERATELY DO NOT REACH IT. `ai-routing` and `evidence`
     * are their own channels precisely because they are read as files, one by
     * grepping a provider out of a latency series and the other during an
     * incident review a quarter later. They record at `info`, below the global
     * level, and nothing here changes that: they keep their own retention (30
     * and 365 days) and stay on disk. See `config/logging.php`.
     *
     * @see https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#enable_logs
     */
    'enable_logs' => env('SENTRY_ENABLE_LOGS', true),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#enable_metrics
    'enable_metrics' => env('SENTRY_ENABLE_METRICS', true),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#log_flush_threshold
    'log_flush_threshold' => env('SENTRY_LOG_FLUSH_THRESHOLD') === null ? null : (int) env('SENTRY_LOG_FLUSH_THRESHOLD'),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#send_default_pii
    'send_default_pii' => env('SENTRY_SEND_DEFAULT_PII', false),

    /*
     * The last gate before an event leaves this server.
     *
     * {@see SentryScrubber} masks the field names this codebase invented, which
     * Sentry's own server-side scrubbing has never heard of and would only see
     * after the event had already crossed the network. `auth_config` (a
     * customer's decrypted credential) is the one that forced it.
     *
     * An ARRAY CALLABLE rather than a closure, and that is a hard requirement
     * rather than a style choice: `php artisan config:cache` writes this file
     * out through `var_export()`, which cannot represent a closure, and the
     * deploy runs `config:cache`. A closure here fails at deploy time, in the
     * one environment where the scrubber is the only thing standing between a
     * credential and a third party.
     */
    'before_send' => [SentryScrubber::class, 'beforeSend'],

    /*
     * The same gate for the structured-log pipeline, which is a SEPARATE
     * transport with a separate hook.
     *
     * `enable_logs` above ships each line's context as log attributes without
     * ever passing it through `before_send`, so turning logs on without this
     * would open a second and wider road for exactly the values the scrubber
     * exists to keep in. 28 files in `app/` log with a context array.
     */
    'before_send_log' => [SentryScrubber::class, 'beforeSendLog'],

    /*
     * The level that reaches Sentry's log product, pinned rather than inherited.
     *
     * The SDK's own default chains down to `LOG_LEVEL`, whose value in
     * `.env.example` is `debug` and which no deploy step sets explicitly. A
     * machine that ever ran with a verbose level would quietly start shipping
     * debug lines into a 5GB monthly allowance, and the plan has no overage
     * budget to absorb it. `warning` is what production writes to disk today,
     * so this keeps the two surfaces telling the same story.
     */
    'logs_channel_level' => env('SENTRY_LOGS_LEVEL', 'warning'),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#ignore_exceptions
    // 'ignore_exceptions' => [],

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#ignore_transactions
    /*
     * Transactions that are pure noise, dropped before they cost a span.
     *
     * The sampler above would already thin these, but a rate still lets a
     * fraction through, and none of these three has ever been worth looking at:
     * `/up` answers an uptime prober many times a minute, and the two
     * dashboards are operator tooling whose own performance is not this
     * application's behaviour. Sentry's dynamic sampling deprioritises health
     * checks on its side too, but that affects RETENTION, not billing, which is
     * metered on what it receives.
     */
    'ignore_transactions' => [
        // Ignore Laravel's default health URL
        '/up',
        '/horizon*',
        '/telescope*',
    ],

    // Breadcrumb specific configuration
    'breadcrumbs' => [
        // Capture Laravel logs as breadcrumbs
        'logs' => env('SENTRY_BREADCRUMBS_LOGS_ENABLED', true),

        // Capture Laravel cache events (hits, writes etc.) as breadcrumbs
        'cache' => env('SENTRY_BREADCRUMBS_CACHE_ENABLED', true),

        // Capture Livewire components like routes as breadcrumbs
        'livewire' => env('SENTRY_BREADCRUMBS_LIVEWIRE_ENABLED', true),

        // Capture SQL queries as breadcrumbs
        'sql_queries' => env('SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED', true),

        // Capture SQL query bindings (parameters) in SQL query breadcrumbs
        'sql_bindings' => env('SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED', false),

        // Capture queue job information as breadcrumbs
        'queue_info' => env('SENTRY_BREADCRUMBS_QUEUE_INFO_ENABLED', true),

        // Capture command information as breadcrumbs
        'command_info' => env('SENTRY_BREADCRUMBS_COMMAND_JOBS_ENABLED', true),

        // Capture HTTP client request information as breadcrumbs
        'http_client_requests' => env('SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED', true),

        // Capture send notifications as breadcrumbs
        'notifications' => env('SENTRY_BREADCRUMBS_NOTIFICATIONS_ENABLED', true),
    ],

    // Performance monitoring specific configuration
    'tracing' => [
        // Trace queue jobs as their own transactions (this enables tracing for queue jobs)
        'queue_job_transactions' => env('SENTRY_TRACE_QUEUE_ENABLED', true),

        // Capture queue jobs as spans when executed on the sync driver
        'queue_jobs' => env('SENTRY_TRACE_QUEUE_JOBS_ENABLED', true),

        // Capture SQL queries as spans
        'sql_queries' => env('SENTRY_TRACE_SQL_QUERIES_ENABLED', true),

        // Capture SQL query bindings (parameters) in SQL query spans
        'sql_bindings' => env('SENTRY_TRACE_SQL_BINDINGS_ENABLED', false),

        // Capture where the SQL query originated from on the SQL query spans
        'sql_origin' => env('SENTRY_TRACE_SQL_ORIGIN_ENABLED', true),

        // Define a threshold in milliseconds for SQL queries to resolve their origin
        'sql_origin_threshold_ms' => env('SENTRY_TRACE_SQL_ORIGIN_THRESHOLD_MS', 100),

        // Capture views rendered as spans
        'views' => env('SENTRY_TRACE_VIEWS_ENABLED', true),

        // Capture Livewire components as spans
        'livewire' => env('SENTRY_TRACE_LIVEWIRE_ENABLED', true),

        // Capture HTTP client requests as spans
        'http_client_requests' => env('SENTRY_TRACE_HTTP_CLIENT_REQUESTS_ENABLED', true),

        // Capture Laravel cache events (hits, writes etc.) as spans
        'cache' => env('SENTRY_TRACE_CACHE_ENABLED', true),

        // Capture Redis operations as spans (this enables Redis events in Laravel)
        'redis_commands' => env('SENTRY_TRACE_REDIS_COMMANDS', false),

        // Capture where the Redis command originated from on the Redis command spans
        'redis_origin' => env('SENTRY_TRACE_REDIS_ORIGIN_ENABLED', true),

        // Capture send notifications as spans
        'notifications' => env('SENTRY_TRACE_NOTIFICATIONS_ENABLED', true),

        // Enable tracing for requests without a matching route (404's)
        'missing_routes' => env('SENTRY_TRACE_MISSING_ROUTES_ENABLED', false),

        // Configures if the performance trace should continue after the response has been sent to the user until the application terminates
        // This is required to capture any spans that are created after the response has been sent like queue jobs dispatched using `dispatch(...)->afterResponse()` for example
        'continue_after_response' => env('SENTRY_TRACE_CONTINUE_AFTER_RESPONSE', true),

        // Capture AI agent interactions as spans (requires laravel/ai)
        'gen_ai' => env('SENTRY_TRACE_GEN_AI_ENABLED', true),

        // Capture AI invoke_agent spans
        'gen_ai_invoke_agent' => env('SENTRY_TRACE_GEN_AI_INVOKE_AGENT_ENABLED', true),

        // Capture AI chat spans
        'gen_ai_chat' => env('SENTRY_TRACE_GEN_AI_CHAT_ENABLED', true),

        // Capture AI execute_tool spans
        'gen_ai_execute_tool' => env('SENTRY_TRACE_GEN_AI_EXECUTE_TOOL_ENABLED', true),

        // Capture AI embeddings spans
        'gen_ai_embeddings' => env('SENTRY_TRACE_GEN_AI_EMBEDDINGS_ENABLED', true),

        // Enable the tracing integrations supplied by Sentry (recommended)
        'default_integrations' => env('SENTRY_TRACE_DEFAULT_INTEGRATIONS_ENABLED', true),
    ],

];
