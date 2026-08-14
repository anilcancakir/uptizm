<?php

namespace Tests\Unit;

use App\Support\Sentry\SentryScrubber;
use App\Support\Sentry\SentryTraceSampler;
use Tests\TestCase;

/**
 * Locks the two things about `config/sentry.php` that fail silently.
 *
 * Neither of these throws when broken. A DSN that leaks into local development
 * just quietly files developer noise against the production project, and this
 * org's plan carries `onDemandMaxSpend = 0`, so that noise DROPS real
 * production events once the quota is gone. A `before_send` written as a
 * closure works perfectly on every developer machine and fails only under
 * `php artisan config:cache`, which runs on the server, at deploy, in the one
 * environment where the scrubber is the only thing between a customer's
 * credential and a third party.
 *
 * Both are cheap to assert and impossible to notice by hand, which is exactly
 * the shape of thing that belongs in a test rather than in a comment.
 */
class SentryConfigTest extends TestCase
{
    /**
     * The gate, from the outside: the suite runs as `testing`, so there must be
     * no DSN and therefore no transport.
     */
    public function test_no_dsn_is_configured_outside_production(): void
    {
        $this->assertNull(
            config('sentry.dsn'),
            'A DSN outside production means local runs and CI file events against the production project.',
        );
    }

    /**
     * The same gate from the other side, which is the half that would otherwise
     * go untested: a gate that is always closed is indistinguishable from a
     * gate that is closed because it is broken.
     *
     * The config file is re-evaluated by hand with the environment swapped.
     * `$_SERVER` and `$_ENV` are both written because Laravel's `Env` reads
     * those adapters BEFORE `getenv()`, which is why the tempting `putenv()`
     * version of this test would pass without proving anything.
     */
    public function test_the_dsn_is_configured_when_the_environment_is_production(): void
    {
        $originalEnv = $_SERVER['APP_ENV'] ?? null;
        $originalDsn = $_SERVER['SENTRY_LARAVEL_DSN'] ?? null;

        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'production';
        $_SERVER['SENTRY_LARAVEL_DSN'] = $_ENV['SENTRY_LARAVEL_DSN'] = 'https://key@example.ingest.de.sentry.io/1';

        try {
            $config = require config_path('sentry.php');

            $this->assertSame(
                'https://key@example.ingest.de.sentry.io/1',
                $config['dsn'],
                'Production must resolve a DSN, or the whole integration is decoration.',
            );
        } finally {
            $this->restoreEnv('APP_ENV', $originalEnv);
            $this->restoreEnv('SENTRY_LARAVEL_DSN', $originalDsn);
        }
    }

    /**
     * `config:cache` serialises this file with `var_export()`, which cannot
     * represent a closure. An array callable survives it.
     */
    public function test_before_send_is_an_array_callable_so_config_cache_survives(): void
    {
        $beforeSend = config('sentry.before_send');

        $this->assertSame([SentryScrubber::class, 'beforeSend'], $beforeSend);
        $this->assertIsCallable($beforeSend);
        $this->assertNotInstanceOf(
            \Closure::class,
            $beforeSend,
            'A closure here passes locally and fails at deploy, under config:cache.',
        );
    }

    /**
     * The sampler has the same `config:cache` constraint as `before_send`, and
     * one extra: `traces_sampler` silently OVERRIDES `traces_sample_rate`
     * whenever both are set, so a number left in the rate key would read as the
     * configured value while doing nothing at all.
     */
    public function test_the_trace_sampler_is_wired_and_not_shadowed_by_a_flat_rate(): void
    {
        $sampler = config('sentry.traces_sampler');

        $this->assertSame([SentryTraceSampler::class, 'sample'], $sampler);
        $this->assertIsCallable($sampler);
        $this->assertNull(
            config('sentry.traces_sample_rate'),
            'A flat rate alongside a sampler is ignored, so leaving one reads as a lie.',
        );
    }

    /**
     * Structured logs follow the same environment gate as the DSN, and for the
     * same reason: log volume is quota, and this org's spare quota is zero.
     *
     * The channel is registered by Sentry's own service provider rather than
     * declared in `config/logging.php`, so the only thing this application
     * controls is whether the stack references it.
     */
    public function test_the_sentry_log_channel_joins_the_stack_only_in_production(): void
    {
        $this->assertNotContains(
            'sentry_logs',
            config('logging.channels.stack.channels'),
            'A developer machine must not ship its log lines to the production project.',
        );

        $originalEnv = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'production';

        try {
            $config = require config_path('logging.php');

            $this->assertContains(
                'sentry_logs',
                $config['channels']['stack']['channels'],
                'Production must append the channel, or enable_logs is a setting with no writer.',
            );
            $this->assertContains(
                'single',
                $config['channels']['stack']['channels'],
                'Appending must not displace the file channel the server still writes.',
            );
        } finally {
            $this->restoreEnv('APP_ENV', $originalEnv);
        }
    }

    /**
     * Structured logs travel on their own transport with their own hook, so
     * `before_send` does not cover them. Enabling logs without this wires a
     * second and wider path for the values the scrubber exists to stop.
     */
    public function test_the_log_pipeline_has_its_own_scrubber(): void
    {
        $this->assertSame(
            [SentryScrubber::class, 'beforeSendLog'],
            config('sentry.before_send_log'),
        );

        $this->assertSame(
            'warning',
            config('sentry.logs_channel_level'),
            'Inheriting LOG_LEVEL would ship debug lines into a 5GB allowance with no overage budget.',
        );
    }

    /**
     * Profiling defaults to OFF, because it cannot work on this deploy.
     *
     * Two PHP builds run this application (frankenphp's embedded ZTS build for
     * HTTP, the system NTS CLI for the queue), so an extension built for one is
     * invisible to the other, and the box carries PHP 8.4 headers only, so
     * `pecl install excimer` silently produces an extension 8.5 never loads.
     * A non-zero default would read as a working feature.
     */
    public function test_profiling_is_off_by_default(): void
    {
        $this->assertSame(
            0.0,
            config('sentry.profiles_sample_rate'),
            'excimer cannot be loaded on this deploy; a non-zero rate would claim otherwise.',
        );
    }

    /**
     * PII stays off. The scrubber masks known key names; `send_default_pii`
     * would start attaching request bodies and user addresses wholesale, which
     * is a category the scrubber cannot audit.
     */
    public function test_pii_is_not_sent_by_default(): void
    {
        $this->assertFalse(config('sentry.send_default_pii'));
    }

    /**
     * Put an environment value back, removing the key entirely when it was not
     * set before rather than leaving an empty string behind.
     */
    private function restoreEnv(string $key, ?string $original): void
    {
        if ($original === null) {
            unset($_SERVER[$key], $_ENV[$key]);

            return;
        }

        $_SERVER[$key] = $_ENV[$key] = $original;
    }
}
