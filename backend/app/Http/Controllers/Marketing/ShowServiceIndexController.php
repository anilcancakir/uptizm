<?php

namespace App\Http\Controllers\Marketing;

use App\Enums\MonitorRegion;
use App\Models\Service;
use App\Services\Services\FeedFetcher;
use App\Services\Services\ServicePageAssembler;
use App\Services\Services\SitemapBuilder;
use App\Support\Marketing\ChromeData;
use App\Support\Marketing\LegalDocument;
use App\Support\Monitoring\ReadingFreshness;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * The catalog hub at `/status`: every published service, one row each, in
 * whichever language its URL asked for.
 *
 * Each row carries the SAME two labelled provenances the service's own page
 * does, summarized by {@see ServicePageAssembler::summarize()} (the identical
 * rules, minus the 90-day strip, which belongs on the page rather than in a
 * list). A row therefore cannot say something the page it links to would
 * contradict, and neither can invent a blended verdict.
 *
 * The listing predicate is `Service::scopePubliclyVisible()`, shared with the
 * service page's own 404 gate and with `app/Services/Services/SitemapBuilder.php`.
 * All three must agree: a page the hub links to and the sitemap omits, or the
 * reverse, is a crawl error either way. It became a scope once there were three
 * callers, and the third one arrived for a reason worth recording, namely that
 * `is_published` alone was not sufficient. A service that lost its last monitor
 * kept the flag and its page published nothing but a re-rendered provider feed,
 * so publication is now re-derived on read rather than trusted from the column.
 *
 * `tests/Feature/Marketing/ServiceStatusPageTest.php` and
 * `tests/Feature/Marketing/SitemapTest.php` pin both halves.
 */
class ShowServiceIndexController
{
    /**
     * The one view this page renders through.
     */
    public const string VIEW = 'marketing.service-index';

    /**
     * The Markdown filename under `resources/legal/` carrying the hub's prose.
     */
    public const string DOCUMENT = 'service-index';

    /**
     * Cache key for the assembled hub, per locale.
     *
     * Built on {@see FeedFetcher::PAGE_CACHE_KEY_PREFIX} so it sits in the same
     * key space as the per-service pages, with a `hub::` infix no slug can
     * produce (slugs are lower-case words joined by single hyphens, see
     * {@see ShowServiceStatusController::SLUG_PATTERN}), so this key can never
     * collide with a service's own. The ingester's targeted `Cache::forget` does
     * not reach it, which is what the short TTL is for: the hub self-heals within
     * one window, and a service's own page, which is the document a visitor and a
     * crawler read, is busted the moment its feed content changes.
     */
    public const string CACHE_KEY_INFIX = 'hub::';

    public function __construct(
        protected ServicePageAssembler $assembler,
        protected LegalDocument $document,
    ) {}

    public function __invoke(): View
    {
        $locale = app()->getLocale();

        $services = Cache::remember(
            FeedFetcher::PAGE_CACHE_KEY_PREFIX.self::CACHE_KEY_INFIX.$locale,
            ShowServiceStatusController::CACHE_SECONDS,
            fn (): array => $this->rows(),
        );

        return view(self::VIEW, [
            ...(new ChromeData(
                path: ShowServiceStatusController::PATH,
                summary: $this->summary(),
            ))->toArray(),
            'title' => __('Third-party service status, measured independently'),
            'services' => $services,
            // As view data rather than a fully qualified constant in the template,
            // matching the service page and `marketing/contact.blade.php`.
            'staleAfterSeconds' => ReadingFreshness::STALE_AFTER_SECONDS,
            // The middle verdict, so a hub row can withhold the affirmative claim
            // exactly the way the detail page does.
            'mixedVerdict' => ServicePageAssembler::VERDICT_MIXED,
            'document' => $this->document->render(self::DOCUMENT, $locale, $this->replacements($services)),
        ]);
    }

    /**
     * One row per published service: its summarized read model plus its own
     * localized path.
     *
     * The path is composed through `ChromeData`, the same object the pages build
     * their canonical and hreflang from and the same one
     * {@see SitemapBuilder} composes its entries from, so a hub link, a page
     * canonical and a sitemap `loc` cannot disagree about where a service lives.
     *
     * @return list<array<string, mixed>>
     */
    protected function rows(): array
    {
        return Service::query()
            // The one predicate the page and the sitemap also use, so the hub cannot
            // list a service whose own page 404s. It includes "still has a monitor",
            // which the published flag alone does not guarantee once an operator has
            // detached one; see `Service::scopePubliclyVisible()`.
            ->publiclyVisible()
            ->orderBy('display_order')
            ->orderBy('name')
            ->with([
                'monitors',
                // NOT eager-loaded. An eager-loaded `hasOne()->latest()` applies no
                // per-parent limit, so Eloquent fetches EVERY snapshot row for every
                // service in this result and discards all but one each. The lazy read
                // inside the assembler uses `first()` and is bounded. A fixture with
                // three rows can never show the difference; a provider that returns no
                // ETag gets a fresh row every couple of minutes forever.
            ])
            ->get()
            ->map(fn (Service $service): array => [
                ...$this->assembler->summarize($service),
                'path' => $this->pathFor($service),
            ])
            ->all();
    }

    /**
     * A service's page path in the language being read.
     */
    protected function pathFor(Service $service): string
    {
        $links = (new ChromeData(
            path: ShowServiceStatusController::PATH.'/'.$service->slug,
            summary: '',
        ))->toArray()['localeLinks'];

        $current = Arr::first($links, static fn (array $link): bool => $link['current']);

        return is_array($current)
            ? $current['path']
            : '/'.ShowServiceStatusController::PATH.'/'.$service->slug;
    }

    /**
     * Every number the hub's prose quotes, from the thing that governs it: how
     * many services are actually published, and how many regions the platform
     * probes from.
     *
     * @param  list<array<string, mixed>>  $services
     * @return array<string, string>
     */
    protected function replacements(array $services): array
    {
        return [
            '[[index.service_count]]' => (string) count($services),
            '[[index.region_count]]' => (string) count(MonitorRegion::cases()),
            '[[index.stale_after_seconds]]' => (string) ReadingFreshness::STALE_AFTER_SECONDS,
        ];
    }

    /**
     * The hub's own meta description, distinct from the landing page's and from
     * every service page's: it describes a list, and each of those describes one
     * service.
     */
    protected function summary(): string
    {
        return __('An independent, source-labelled view of whether popular developer services are reachable, measured by Uptizm from several regions.');
    }
}
