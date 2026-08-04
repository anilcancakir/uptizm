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
