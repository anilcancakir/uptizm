<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        /*
         * `sentry_logs` is appended in production and nowhere else.
         *
         * The channel itself is registered by the Sentry service provider, not
         * declared below, so it is absent from this file's `channels` list by
         * design. Appending it HERE rather than by widening `LOG_STACK` in the
         * server's `.env` is what makes it impossible to forget on a future
         * deploy, and impossible to enable by accident on a laptop: the same
         * `APP_ENV` gate that governs the DSN in `config/sentry.php` governs it.
         *
         * It carries what the default channel carries, which in production is
         * `warning` and above. `ai-routing` and `evidence` are separate channels
         * and are deliberately untouched: they record at `info` for their own
         * reasons and are read as files, one as a latency series and one during
         * an incident review months later.
         */
        'stack' => [
            'driver' => 'stack',
            'channels' => array_values(array_filter([
                ...explode(',', (string) env('LOG_STACK', 'single')),
                env('APP_ENV') === 'production' ? 'sentry_logs' : null,
            ])),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        /*
         * The AI routing instrument, and the only channel here that does not read
         * LOG_LEVEL.
         *
         * `App\Services\Ai\OpenRouterUpstreamRecorder` writes one line per
         * OpenRouter call naming the upstream that served it and how long it took.
         * That line is an operational record rather than a warning, and production
         * runs LOG_LEVEL=warning, so on any channel above it would be dropped and
         * the latency routing it exists to measure would be unfalsifiable again.
         * Promoting the line to `warning` instead was the other option and is
         * worse: a successful AI call filed as a warning teaches an operator to
         * ignore warnings.
         *
         * Hence AI_ROUTING_LOG_LEVEL rather than LOG_LEVEL, and hence `info` as
         * its default: an unset variable has to leave the instrument WORKING,
         * because a knob whose default is silence is the same bug under a new
         * name. Nothing here reads the global level, deliberately.
         *
         * `daily` over `single`: the volume is a handful of lines a day (one per
         * AI call, and roughly one AI call per analyze), so a day's file is
         * kilobytes, but nothing rotates or prunes a `single` file and this one is
         * meant to still be readable months out. 30 days is sized off what it is
         * FOR, comparing latency before and after a routing change and noticing
         * the slow tail return, against a provider roster that changes
         * continuously; a month of it costs less than one response body.
         *
         * Its own file rather than the application stack, because it is read by
         * grepping a provider name out of a time series, and `laravel.log` is
         * where exceptions live.
         */
        'ai-routing' => [
            'driver' => 'daily',
            'path' => storage_path('logs/ai-routing.log'),
            'level' => env('AI_ROUTING_LOG_LEVEL', 'info'),
            'days' => env('AI_ROUTING_LOG_DAILY_DAYS', 30),
            'replace_placeholders' => true,
        ],

        /*
         * Evidence: what the system deliberately did NOT do, and what a tenant
         * did with a credential. The roster is closed and lives in
         * `App\Support\Logging\EvidenceLog`.
         *
         * Same reasoning as `ai-routing` above, and the same measurement behind
         * it: production runs `LOG_LEVEL=warning`, so the three `Log::info()`
         * lines this channel now carries had never once been written there. Hence
         * EVIDENCE_LOG_LEVEL rather than LOG_LEVEL, and hence `info` as its
         * default, because a knob whose default is silence is the same bug under a
         * new name. Nothing here reads the global level, deliberately.
         *
         * A SECOND channel rather than a second use of `ai-routing`: that one is
         * a latency time series, grepped for a provider name and compared before
         * and after a routing change, and it is sized (30 days) for exactly that.
         * Mixing a page that was withheld into it would make both harder to read
         * and would force one retention onto two questions.
         *
         * `daily` over `single`: nothing rotates or prunes a `single` file, and
         * this one has to still be readable long after it is written. The volume
         * makes that cheap, a few lines a day (a suppressed page happens during
         * planned work, a credentialled analyze maybe ten times a day), so a day's
         * file is bytes.
         *
         * 365 days, an order of magnitude above the application log's 14 and above
         * `ai-routing`'s 30, is sized off WHEN it is read. "Why did nobody get
         * paged" arrives at an incident review, days to weeks out; a question
         * about who sent a credential where can arrive a quarter or a year later,
         * and by then the answer either exists or the control was decoration. A
         * year of this volume costs less than one archived response body. The
         * credential half also has a queryable system of record in
         * `credential_probe_audits`; this file is the copy a human greps, and the
         * copy that survives the table being pruned.
         */
        'evidence' => [
            'driver' => 'daily',
            'path' => storage_path('logs/evidence.log'),
            'level' => env('EVIDENCE_LOG_LEVEL', 'info'),
            'days' => env('EVIDENCE_LOG_DAILY_DAYS', 365),
            'replace_placeholders' => true,
        ],

    ],

];
