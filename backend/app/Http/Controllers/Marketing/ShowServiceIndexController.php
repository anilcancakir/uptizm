<?php

namespace App\Http\Controllers\Marketing;

use App\Enums\MonitorRegion;
use App\Models\Service;
use App\Services\Services\FeedFetcher;
use App\Services\Services\ServicePageAssembler;
use App\Services\Services\SitemapBuilder;
use App\Support\Marketing\ChromeData;
use App\Support\Marketing\LegalDocument;
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
 * The listing predicate here (published AND terms reviewed) is the same one
 * `app/Services/Services/SitemapBuilder.php` lists services under, and the two
 * must stay in step: a page the hub links to and the sitemap omits, or the
 * reverse, is a crawl error either way. Not extracted into a scope, because
 * `app/Models/Service.php` already carries `scopeDueForFeedIngest()` for a
 * different five-predicate question and this plan's own rule is that a shared
 * helper waits for a third caller.
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
            'staleAfterSeconds' => ServicePageAssembler::STALE_AFTER_SECONDS,
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
            ->where('is_published', true)
            ->whereNotNull('terms_reviewed_at')
            ->orderBy('display_order')
            ->orderBy('name')
            ->with([
                'monitors',
                'latestFeedSnapshot',
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
            '[[index.stale_after_seconds]]' => (string) ServicePageAssembler::STALE_AFTER_SECONDS,
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
