<?php

namespace App\Jobs;

use App\Models\ProxySource;
use App\Services\Proxy\ProxySourceRefresher;
use App\Support\Proxy\ProxyRegions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Refreshes every region's proxy pool on the cadence configured in
 * `config('proxy.refresh_minutes')`.
 *
 * `config('proxy.sources')` is the declared truth: one region-value key per
 * region that HAS a source. This job is the only place that turns that
 * declaration into a {@see ProxySource} row, upserting on `region` (the
 * migration's unique key) so an operator rotating a provider's URL or
 * download token in `.env` is picked up on the very next tick without a
 * manual database edit. A region whose configured `location` is empty is
 * skipped entirely: `config/proxy.php`'s own docblock states that an empty
 * location leaves the region declared but unusable, and fetching an empty
 * URL/path would only manufacture a spurious `last_error` for a region
 * nobody has wired up yet.
 *
 * One region's fetch failure must never block the rest: each source is
 * refreshed inside its own try/catch, mirroring `FeedFetcher`'s
 * catch-and-record convention (recorded as data via `last_error`, logged,
 * never rethrown, never silently swallowed) rather than letting an
 * unreachable provider's exception abort the loop and leave every later
 * region stale until the next scheduled tick.
 *
 * Constructible with no arguments, because `Schedule::job(new
 * RefreshProxySources)` in `routes/console.php` instantiates it at
 * file-load time; the constructor only sets the queue and touches neither
 * the container nor the database. Runs on the `feeds` queue, which is
 * already registered in both places a queue needs registering:
 * `config/horizon.php` supervisor-1 (server) and composer's local
 * `queue:listen` list (dev), so this job needs no third registration.
 */
class RefreshProxySources implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The interval at which the hour field stops being the right place to say it.
     *
     * A step is enumerated, not counted, in the hour field exactly as in the minute
     * field, so 24 hours cannot be written as an hour step: measured through the
     * vendored `Cron\CronExpression`, a step of 23 fires at 23:00 and then 00:00,
     * one hour apart, before waiting 23 hours. Anything from a day upward therefore
     * becomes the daily expression, which fires once and at a predictable time.
     *
     * An earlier revision clamped to a step of 23 instead and its docblock claimed
     * that WAS daily. It was not, and the test asserted the same wrong string, so
     * the pair certified the behaviour rather than catching it. This is the same
     * mistake the minute-field clamp made, one field to the left.
     */
    protected const int HOURS_PER_DAY = 24;

    /**
     * This job's cron expression, derived from `config('proxy.refresh_minutes')`.
     *
     * Schedule's fluent helpers have no "every N configurable minutes" method, so
     * the cadence is a raw cron field, and a raw cron field built from env input
     * has to be built defensively for two separate reasons.
     *
     * It cannot be interpolated unvalidated. `routes/console.php` loads on EVERY
     * artisan invocation, so `UPTIZM_PROXY_REFRESH_MINUTES=0` (or any non-numeric
     * value, which `(int)` makes 0) builds a zero step, throws `Invalid CRON field
     * value` and takes down `schedule:list`, `migrate`, `config:clear`, everything,
     * including the commands you would reach for to fix it. Measured: exit 1 on
     * `schedule:list`.
     *
     * And it cannot simply be clamped into the minute field either, which an
     * earlier revision did with `min(59, ...)`. A minute step of 59 is not "every
     * 59 minutes": a cron step ENUMERATES matching values, so it fires at minute 0
     * and minute 59, one minute apart, then waits 58. That silently mangled the
     * SHIPPED default: `refresh_minutes` is 60, documented as hourly, and the clamp
     * turned it into two adjacent refreshes an hour. Anything an operator set above
     * 60 to ease load on a rate-limited provider was flattened the same way.
     *
     * So an interval of an hour or more is expressed in the HOUR field instead,
     * where it means what it says: 60 becomes an every-hour expression and 120 an
     * hour step of 2. A day or more becomes the daily expression, because the hour
     * field cannot spell a 24-hour step either ({@see self::HOURS_PER_DAY}).
     *
     * Between those, BOTH fields keep cron's own step semantics, including the
     * uneven wrap a non-divisor produces: a minute step of 7 fires at 0, 7, ..., 56
     * and then 4 minutes later, and an hour step of 7 fires at 0, 7, 14, 21 and then
     * 3 hours later. That is cron behaving as documented rather than a value this
     * method mangled, and an interval that is not a divisor of its field is the
     * caller asking for something cron cannot express exactly. Values are rounded
     * DOWN throughout (90 minutes becomes hourly), so the refresh is never rarer
     * than asked.
     *
     * Nothing in this docblock may contain a literal asterisk-slash cron step.
     * Writing one closes the comment: the first draft of this block spelled the
     * zero-step case out and PHP read the rest of the class as code, so every
     * artisan command died with a ParseError, which is precisely the failure the
     * paragraph above describes.
     */
    public static function cronExpression(): string
    {
        $minutes = max(1, (int) config('proxy.refresh_minutes'));

        if ($minutes < 60) {
            return '*/'.$minutes.' * * * *';
        }

        $hours = intdiv($minutes, 60);

        return match (true) {
            $hours === 1 => '0 * * * *',
            $hours >= self::HOURS_PER_DAY => '0 0 * * *',
            default => '0 */'.$hours.' * * *',
        };
    }

    /**
     * One attempt per tick. A retry would re-fetch the same source before the
     * next scheduled refresh does so anyway, more politely and without
     * hammering a possibly-throttling provider a second time in the same
     * minute; mirrors `IngestServiceFeed`'s reasoning for the same choice.
     *
     * @var int
     */
    public $tries = 1;

    public function __construct()
    {
        $this->onQueue('feeds');
    }

    /**
     * @param  ProxySourceRefresher  $refresher  Owns the fetch/parse/guard/upsert/sweep
     *                                           order for a single region's source.
     */
    public function handle(ProxySourceRefresher $refresher): void
    {
        // Which regions carry a source is {@see ProxyRegions}' answer, not a fifth
        // copy of the predicate. The copy this replaced asked `location === ''`
        // while every other site asked `filled()`, and `blank()` TRIMS: a location
        // of a single space was unsourced for the seeder, the migration and `/bot`,
        // and still fetched here, manufacturing exactly the spurious `last_error`
        // for an unwired region the guard exists to prevent.
        foreach (ProxyRegions::sourced() as $region) {
            $spec = (array) config('proxy.sources.'.$region);

            // Keep the DB row in sync with config on every tick (not only at first
            // creation), so a rotated `kind`/`location` env value takes effect on the
            // very next refresh instead of requiring a manual database edit.
            $source = ProxySource::query()->updateOrCreate(
                ['region' => $region],
                [
                    'kind' => $spec['kind'],
                    'location' => $spec['location'],
                ],
            );

            try {
                $counts = $refresher->refresh($source);

                // The refresher's own docblock argues the sweep predicate exists so a
                // swept count "stays meaningful as a decay signal". It was computed here
                // and thrown away, so a pool losing 200 exits in one tick emitted
                // nothing: no log, no metric, no column. The drop count was already
                // logged one layer down, which is what made the asymmetry visible.
                Log::info('Proxy source refreshed.', ['region' => $source->region] + $counts);
            } catch (ConnectionException|RequestException|RuntimeException $exception) {
                // Recorded as data, not rethrown: a provider being unreachable is not
                // this job failing, and rethrowing here would abort the loop and leave
                // every region after this one un-refreshed until the next scheduled tick.
                Log::warning('Proxy source refresh failed to fetch its list.', [
                    'region' => $region,
                    'error' => $exception->getMessage(),
                ]);

                $source->update([
                    'last_error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
