<?php

namespace App\Jobs;

use App\Models\ProxySource;
use App\Services\Proxy\ProxySourceRefresher;
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
     * The largest hour step a cron field accepts, so a very long interval lands
     * on a daily expression rather than an invalid one.
     */
    protected const int MAX_HOUR_STEP = 23;

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
     * hour step of 2. Sub-hour values keep cron's own step semantics, including the
     * uneven wrap a non-divisor produces (a step of 7 fires at 0, 7, ..., 56, then
     * 4 minutes later); that is cron behaving as documented rather than a value
     * this method mangled.
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

        $hours = min(self::MAX_HOUR_STEP, intdiv($minutes, 60));

        return $hours === 1
            ? '0 * * * *'
            : '0 */'.$hours.' * * *';
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
        foreach (config('proxy.sources') as $region => $spec) {
            // An unconfigured region is declared but unusable; there is nothing to fetch.
            if (($spec['location'] ?? '') === '') {
                continue;
            }

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
