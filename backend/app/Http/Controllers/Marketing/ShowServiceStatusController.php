<?php

namespace App\Http\Controllers\Marketing;

use App\Enums\MonitorRegion;
use App\Models\Monitor;
use App\Models\Service;
use App\Services\Services\FeedFetcher;
use App\Services\Services\ServicePageAssembler;
use App\Support\Marketing\ChromeData;
use App\Support\Marketing\LegalDocument;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One published catalog service's public page, in whichever language its URL
 * asked for.
 *
 * The page joins two separately-labelled blocks that
 * {@see ServicePageAssembler} builds: uptizm's OWN measurement of a named
 * endpoint, and the provider's own published status. This controller adds the
 * chrome, the localized prose and the derived numbers that prose quotes.
 *
 * ## Publication is enforced HERE, not in the route file
 *
 * Three separate conditions answer 404, and each is its own `abort_if` so a test
 * can break exactly one and see exactly one failure:
 *
 *   1. no such slug,
 *   2. the service is not published,
 *   3. its `terms_reviewed_at` is null, so no human has signed off on
 *      republishing this provider's status.
 *
 * None of them belongs in `routes/marketing.php`. A route file runs on EVERY
 * console boot, so a `whereIn` built from the published slugs would query
 * `services` before the table exists during a fresh `php artisan migrate`, and
 * `php artisan optimize` (a documented deploy step) would freeze the slug list
 * into the route cache so a newly published service 404s until the next deploy.
 * The route constrains the slug's SHAPE with a static pattern and nothing more,
 * exactly as `/s/{slug}` leaves its own gate to
 * `app/Http/Controllers/StatusPage/ShowStatusPageController.php`.
 *
 * ## Why the cache key is not this controller's own invention
 *
 * {@see FeedFetcher::PAGE_CACHE_KEY_PREFIX} is a contract with the ingester,
 * which forgets `<prefix><slug>` and `<prefix><slug>:<locale>` the moment a
 * feed's normalized hash changes. A page that keyed its cache on anything else
 * would serve a stale provider status until the TTL expired and nothing would
 * fail.
 *
 * `tests/Feature/Marketing/ServiceStatusPageTest.php` pins the head contract,
 * every 404 branch in isolation, and the honesty rules the view renders under.
 */
class ShowServiceStatusController
{
    /**
     * The one view every state of this page renders through.
     */
    public const string VIEW = 'marketing.service-status';

    /**
     * The Markdown filename under `resources/legal/`, shared by every service:
     * one template per locale, with the per-service facts interpolated. A file
     * per service would be eight copies of one document to keep in step.
     */
    public const string DOCUMENT = 'service-status';

    /**
     * The route's own path prefix, which is also what `ChromeData` composes this
     * page's canonical and hreflang set from. Held here so the route, the
     * canonical and the sitemap cannot drift apart.
     */
    public const string PATH = 'status';

    /**
     * A shape guard for the slug, and deliberately NOT a membership test.
     *
     * Lower-case words joined by single hyphens, which is what `Str::slug()`
     * produces and what every catalog row carries. It keeps a path traversal or a
     * cache-key oddity out of the lookup while leaving "does this service exist
     * and may it be published" to the controller, where it can be answered
     * against the database at request time.
     */
    public const string SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /**
     * Seconds the assembled read model is cached for, matching the public status
     * page's own window (`app/Http/Controllers/StatusPage/ShowStatusPageController.php`).
     */
    public const int CACHE_SECONDS = 60;

    /**
     * The named rate limiter the hub and every service page are throttled by.
     *
     * The EXISTING `resource-not-found` limiter, not a new one, and that is a
     * decision rather than laziness. These routes have the identical shape it was
     * written for: a public, unauthenticated, enumerable path where an unknown or
     * unpublished slug is answered 404, sharing one Octane instance with the API
     * and with paying customers' status pages. Registering a limiter of its own
     * would mean editing `bootstrap/app.php`, where every limiter in this app is
     * registered on `booted()`, and registering one from a ROUTE FILE instead
     * would be a production-only outage: `php artisan optimize` (a documented
     * deploy step) caches the routes, the file then never executes, and
     * `ThrottleRequests` falls back to reading the unknown limiter NAME as a
     * numeric attempt count, which casts to 0 and answers every request 429.
     *
     * The consequence to know: these pages share one per-IP bucket with
     * `/s/{slug}`. That is 30 requests a minute per address across the whole
     * public surface, which is far above a crawler's rate and far below a scraper's.
     */
    public const string LIMITER = 'resource-not-found';

    public function __construct(
        protected ServicePageAssembler $assembler,
        protected LegalDocument $document,
    ) {}

    /**
     * @throws NotFoundHttpException
     */
    public function __invoke(Request $request): View
    {
        // The slug is read off the route rather than taken positionally: the
        // localized form carries `{locale}` FIRST, so a positional signature
        // would silently receive the language on `/tr/status/github`.
        $slug = (string) $request->route('slug');

        // The shape guard already bounded the slug; this reads the row so each
        // refusal below can name its own reason. The publication predicate itself
        // lives in `Service::scopePubliclyVisible()` and is asserted immediately
        // after, so this controller, the hub and the sitemap cannot drift.
        $service = Service::query()
            ->where('slug', $slug)
            ->with([
                'monitors',
                // NOT eager-loaded. An eager-loaded `hasOne()->latest()` applies no
                // per-parent limit, so Eloquent fetches EVERY snapshot row for every
                // service in this result and discards all but one each. The lazy read
                // inside the assembler uses `first()` and is bounded. A fixture with
                // three rows can never show the difference; a provider that returns no
                // ETag gets a fresh row every couple of minutes forever.
            ])
            ->first();

        abort_if($service === null, 404);
        abort_if(! $service->is_published, 404);
        // A page for a service whose terms nobody reviewed is the one thing this
        // catalog promised never to publish, and `is_published` is mass-assignable
        // (`Service` uses `$guarded = []`), so this is not implied by the check
        // above.
        abort_if($service->terms_reviewed_at === null, 404);

        /*
         * 4. It has no monitor of its own left, so the only thing this page could
         *    show is the provider's own feed re-rendered.
         *
         *    `Service::canPublish()` guards the publish TRANSITION and nothing
         *    re-checks it afterwards, so a service published legitimately and then
         *    stripped of its last monitor (an operator detaching it, or a monitor
         *    delete cascading the `service_monitor` row) keeps `is_published = true`.
         *    Reproduced: the page answered 200 and rendered "we have no endpoint of
         *    our own attached to this service yet" as its entire substance. That is
         *    the one page this catalog promised not to publish.
         *
         *    Re-derived on read rather than repaired on write, because the write
         *    paths that can reach the state are several and the read path is one.
         *    `Service::scopePubliclyVisible()` is the same predicate the hub and the
         *    sitemap use, so the three cannot disagree about what exists.
         */
        abort_if($service->monitors->isEmpty(), 404);

        /*
         * And the belt to those braces: the four refusals above are written out one
         * per reason so a test can break exactly one, but the AUTHORITATIVE predicate
         * is the scope the hub and the sitemap list under. Re-asserting it here means
         * a future widening applied to the scope cannot leave this page serving 200
         * for a row the other two surfaces have already dropped, which is the mirror
         * image of the bug the scope was extracted to fix.
         */
        abort_if(
            ! Service::query()->publiclyVisible()->whereKey($service->getKey())->exists(),
            404,
        );

        $locale = app()->getLocale();

        $data = Cache::remember(
            FeedFetcher::PAGE_CACHE_KEY_PREFIX.$service->slug.':'.$locale,
            self::CACHE_SECONDS,
            fn (): array => $this->assembler->build($service),
        );

        return view(self::VIEW, [
            ...(new ChromeData(
                path: self::PATH.'/'.$service->slug,
                summary: $this->summary($service, $data),
            ))->toArray(),
            'title' => $this->title($service),
            'service' => $data['service'],
            'own' => $data['own'],
            'feed' => $data['feed'],
            'divergence' => $data['divergence'],
            'generatedAt' => $data['generatedAt'],
            'hubPath' => $this->hubPath(),
            // The two evidence bounds as view data rather than as fully qualified
            // constants inside the template, the same way the contact page takes
            // its field names: Blade has no `use` statement, and the sentences
            // these appear in are already dense enough.
            'staleAfterSeconds' => ServicePageAssembler::STALE_AFTER_SECONDS,
            'agreeingRegions' => ServicePageAssembler::MIN_AGREEING_REGIONS,
            // The middle verdict, so the view can withhold the affirmative claim
            // without restating the ladder value as a literal.
            'mixedVerdict' => ServicePageAssembler::VERDICT_MIXED,
            'document' => $this->document->render(self::DOCUMENT, $locale, $this->replacements($service, $data)),
        ]);
    }

    /**
     * The page's name, in the browser tab and as its `<h1>`.
     *
     * "Independently" is load-bearing rather than decorative: this page carries a
     * third party's trademark in its title, and Google's structured-data policy
     * forbids misrepresenting affiliation. A title reading "GitHub Status" would
     * read as the provider's own page. The visible non-affiliation line in the
     * view is the other half of the pair.
     */
    protected function title(Service $service): string
    {
        return __(':service status, independently measured', ['service' => $service->name]);
    }

    /**
     * This page's own meta description.
     *
     * Per SERVICE, not per template: the service's name and the endpoint uptizm
     * probes are both in it, so no two service pages, and not the hub and not the
     * landing page, share a sentence. Two pages with one description tell a
     * crawler they are one document, which `LegalPagesTest` and this step's own
     * suite both fail the build on.
     *
     * @param  array<string, mixed>  $data
     */
    protected function summary(Service $service, array $data): string
    {
        $endpoints = $this->endpointLabels($data);

        return $endpoints === []
            ? __('What Uptizm measures about :service, and what :service publishes itself, each labelled with where it came from.', [
                'service' => $service->name,
            ])
            : __('What Uptizm measured reaching :endpoint, and what :service publishes on its own status feed, side by side with both sources labelled.', [
                'endpoint' => Arr::join($endpoints, ', '),
                'service' => $service->name,
            ]);
    }

    /**
     * The hub's path in the language being read, for the "back to every service"
     * link.
     *
     * Composed through `ChromeData` like every other address on this surface, so
     * a Turkish reader is not dropped onto the English hub.
     */
    protected function hubPath(): string
    {
        $links = (new ChromeData(path: self::PATH, summary: ''))->toArray()['localeLinks'];

        $current = Arr::first($links, static fn (array $link): bool => $link['current']);

        return is_array($current) ? $current['path'] : '/'.self::PATH;
    }

    /**
     * Every number and name the localized prose quotes, each read from the thing
     * that governs it.
     *
     * Nothing about capability is typed into the Markdown, the same rule
     * {@see ShowFaqController::replacements()} follows: the endpoint list and the
     * cadence come from the monitor rows behind this service, the region count
     * from those rows too (and only from {@see MonitorRegion} when a service has
     * no monitor to read, which `Service::canPublish()` makes unreachable for a
     * published row), the strip length from the strip actually rendered, and the
     * two evidence bars from {@see ServicePageAssembler}'s own constants.
     *
     * An unmapped placeholder survives into the page verbatim rather than
     * vanishing, so a forgotten entry here is visible as `[[service.x]]` instead
     * of a sentence with a hole in it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    protected function replacements(Service $service, array $data): array
    {
        /** @var list<array<string, mixed>> $endpoints */
        $endpoints = $data['own']['endpoints'];

        $intervals = array_values(array_filter(
            array_column($endpoints, 'checkIntervalSeconds'),
            static fn (?int $seconds): bool => $seconds !== null,
        ));

        $regions = array_values(array_filter(array_column($endpoints, 'regionsConfigured')));
        $strips = array_values(array_filter(array_column($endpoints, 'strip')));

        return [
            '[[service.name]]' => $service->name,
            '[[service.endpoints]]' => Arr::join($this->endpointLabels($data), ', '),
            '[[service.region_count]]' => (string) ($regions === [] ? count(MonitorRegion::cases()) : max($regions)),
            // The FASTEST cadence any of this service's endpoints runs on, so the
            // sentence "we check every N seconds" is true of the page as a whole
            // rather than of one row of it.
            '[[service.check_interval_seconds]]' => (string) ($intervals === [] ? 0 : min($intervals)),
            '[[service.incident_threshold]]' => (string) ($endpoints === []
                ? Monitor::DEFAULT_INCIDENT_THRESHOLD
                : max(array_column($endpoints, 'incidentThreshold'))),
            '[[service.agreeing_regions]]' => (string) ServicePageAssembler::MIN_AGREEING_REGIONS,
            '[[service.stale_after_seconds]]' => (string) ServicePageAssembler::STALE_AFTER_SECONDS,
            '[[service.strip_days]]' => (string) ($strips === [] ? 0 : count($strips[0])),
        ];
    }

    /**
     * The endpoint labels this service's page makes claims about.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    protected function endpointLabels(array $data): array
    {
        return array_values(array_column($data['own']['endpoints'], 'label'));
    }
}
