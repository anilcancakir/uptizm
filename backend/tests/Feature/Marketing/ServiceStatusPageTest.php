<?php

namespace Tests\Feature\Marketing;

use App\Enums\AiMode;
use App\Enums\ComponentStatus;
use App\Enums\HttpMethod;
use App\Enums\MonitorRegion;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\ServiceStatusSource;
use App\Enums\StatusProvenance;
use App\Http\Controllers\Marketing\ShowServiceIndexController;
use App\Http\Controllers\Marketing\ShowServiceStatusController;
use App\Http\Controllers\StatusPage\ShowStatusPageController;
use App\Http\Middleware\SetMarketingLocale;
use App\Models\Monitor;
use App\Models\Service;
use App\Models\ServiceFeedSnapshot;
use App\Services\Services\FeedFetcher;
use App\Services\Services\ServicePageAssembler;
use App\Services\StatusPages\StatusPageAssembler;
use App\Support\Monitoring\ReadingFreshness;
use App\Support\Services\SystemTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The public service catalog: the hub at `/status` and one page per published
 * service.
 *
 * Two families of invariant are pinned here, and the second is the reason this
 * page needed its own suite rather than an extension of `LegalPagesTest`.
 *
 * The ROUTING and HEAD contract, mirroring the existing marketing suites: one URL
 * per language per page, the default language's prefixed form a 301, a canonical
 * that names this page in this language, every language as an alternate plus
 * x-default, a per-page meta description, a language we do not speak answered 404,
 * `/up` still reachable, no session or CSRF middleware anywhere near it, and no
 * placeholder surviving into the HTML.
 *
 * The HONESTY rules, which are what this surface publishes under:
 *
 *   - no uptime percentage, availability figure or SLA number attributed to the
 *     third party, ever, and the 90-day strip labelled as uptizm's reachability of
 *     the NAMED endpoint;
 *   - a reading older than {@see ReadingFreshness::STALE_AFTER_SECONDS} shown as
 *     unknown rather than frozen at its last value;
 *   - a public "we could not reach it" only after the monitor's consecutive-failure
 *     threshold AND more than one region agreeing;
 *   - both provenances rendered with their labels when they disagree, plus the
 *     divergence sentence, and NO divergence sentence when they agree (otherwise
 *     the sentence is always there and asserts nothing);
 *   - no `0%` and no `100%` for a service nobody has measured yet;
 *   - no provider artwork and no structured data other than `WebPage`.
 *
 * Every 404 branch is exercised in ISOLATION with the other conditions satisfied.
 * Three separate things answer 404 (unknown slug, unpublished, terms unreviewed) and
 * a fixture that trips two of them would prove neither: the suite would stay green
 * after deleting one guard because the other refused instead.
 */
class ServiceStatusPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Middleware that reads or writes a session, ships a cookie, or gates on a CSRF
     * token. Matched as a SUBSTRING against the resolved class name, exactly as
     * `CookieTest` and `LegalPagesTest` do it: on Laravel 13 the CSRF middleware is
     * `PreventRequestForgery` while `VerifyCsrfToken` is an empty compat subclass,
     * so either name may appear and both must be caught, and a substring also
     * catches a project subclass.
     */
    protected const SESSION_COUPLED = [
        'StartSession',
        'EncryptCookies',
        'AddQueuedCookiesToResponse',
        'ShareErrorsFromSession',
        'PreventRequestForgery',
        'VerifyCsrfToken',
    ];

    /**
     * The fixture service's slug, name, and the endpoint label its monitor is
     * attached under. The name is deliberately a distinctive string: the
     * no-percentage walk greps for a figure ADJACENT to it, so it has to be
     * findable in the rendered text.
     */
    private const string SLUG = 'example-provider';

    private const string NAME = 'Example Provider';

    private const string ENDPOINT = 'api.example.test';

    public function test_a_published_service_and_the_hub_answer_in_every_supported_language(): void
    {
        $this->publish();

        foreach ($this->supported() as $locale) {
            $this->get($this->hubPath($locale))
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'"', escape: false);

            $this->get($this->pagePath($locale))
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'"', escape: false)
                ->assertSee(self::NAME);
        }
    }

    public function test_an_unpublished_service_is_not_a_page(): void
    {
        // Terms reviewed AND a monitor attached: the ONLY thing wrong with this row
        // is `is_published`, so this test can only pass because that guard fired.
        $this->publish(['is_published' => false]);

        foreach ($this->supported() as $locale) {
            $this->get($this->pagePath($locale))->assertNotFound();
        }
    }

    public function test_a_service_whose_terms_were_never_reviewed_is_not_a_page(): void
    {
        /*
         * Published, with a monitor attached, and `is_published` true: the only
         * failing condition is the terms review. This branch is not implied by the
         * one above, because `Service` uses `$guarded = []`, so `is_published` is
         * mass-assignable and can be true on a row nobody reviewed.
         */
        $this->publish(['terms_reviewed_at' => null]);

        foreach ($this->supported() as $locale) {
            $this->get($this->pagePath($locale))->assertNotFound();
        }
    }

    public function test_an_unknown_slug_is_not_a_page(): void
    {
        $this->publish();

        $this->get('/status/no-such-service')->assertNotFound();
        $this->get('/tr/status/no-such-service')->assertNotFound();
    }

    public function test_a_language_we_do_not_speak_is_not_a_service_page(): void
    {
        $this->publish();

        foreach (['de', 'fr', 'es'] as $locale) {
            $this->get('/'.$locale.'/status')->assertNotFound();
            $this->get('/'.$locale.'/status/'.self::SLUG)->assertNotFound();
        }
    }

    public function test_the_health_check_is_not_mistaken_for_a_language(): void
    {
        // `/up` is two letters and so is the locale segment these routes add. The
        // `whereIn` constraint listing the real locales is the only thing keeping the
        // endpoint nginx and the deploy script poll reachable.
        $this->get('/up')->assertOk();
    }

    public function test_the_default_language_has_exactly_one_url_per_page(): void
    {
        $this->publish();

        $default = (string) config('app.default_locale');

        // A plain `Route::redirect`, answered by the framework's RedirectController
        // without touching a session store, so the status and the Location header are
        // the whole of the fact. The slug is carried THROUGH the redirect rather than
        // dropping the visitor on the hub.
        $this->get('/'.$default.'/status')
            ->assertStatus(301)
            ->assertRedirect('/status');

        $this->get('/'.$default.'/status/'.self::SLUG)
            ->assertStatus(301)
            ->assertRedirect('/status/'.self::SLUG);
    }

    public function test_each_page_is_canonical_for_itself_and_lists_every_language(): void
    {
        $this->publish();

        foreach ([$this->hubPath(...), $this->pagePath(...)] as $pathFor) {
            foreach ($this->supported() as $locale) {
                $response = $this->get($pathFor($locale));

                $response->assertSee(
                    'rel="canonical" href="'.url($pathFor($locale)).'"',
                    escape: false,
                );

                foreach ($this->supported() as $alternate) {
                    $response->assertSee(
                        'hreflang="'.$alternate.'" href="'.url($pathFor($alternate)).'"',
                        escape: false,
                    );
                }

                $response->assertSee(
                    'hreflang="x-default" href="'.url($pathFor((string) config('app.default_locale'))).'"',
                    escape: false,
                );
            }
        }
    }

    public function test_the_language_switcher_stays_on_the_same_service(): void
    {
        // A switcher that drops a reader back on the front page has lost their place,
        // and on this surface it would also lose the service they came to read about.
        $this->publish();

        $this->get($this->pagePath('en'))->assertSee('href="/tr/status/'.self::SLUG.'"', escape: false);
        $this->get($this->pagePath('tr'))->assertSee('href="/status/'.self::SLUG.'"', escape: false);
    }

    public function test_the_hub_and_the_service_page_describe_themselves_and_not_the_home_page(): void
    {
        /*
         * The meta description is what a crawler and a link preview show, so pages
         * sharing one sentence claim to be one document. Asserted structurally rather
         * than by wording: present, non-empty, and distinct from the landing page's,
         * from each other's and from a SECOND service's page.
         */
        $this->publish();
        $this->publish([
            'slug' => 'second-provider',
            'name' => 'Second Provider',
        ], endpoint: 'api.second.test');

        $landing = $this->descriptionOf($this->get('/'));
        $this->assertNotSame('', $landing, 'The landing page emitted no description, so this comparison checks nothing.');

        $seen = [$landing];

        foreach (['/status', '/status/'.self::SLUG, '/status/second-provider'] as $path) {
            $description = $this->descriptionOf($this->get($path));

            $this->assertNotSame('', $description, "GET {$path} emitted no meta description.");
            $this->assertNotContains(
                $description,
                $seen,
                "GET {$path} describes itself with another page's sentence.",
            );

            $seen[] = $description;
        }
    }

    public function test_no_catalog_route_carries_session_or_csrf_middleware(): void
    {
        foreach ($this->catalogPaths() as $path) {
            foreach ($this->middlewareFor($path) as $entry) {
                foreach (self::SESSION_COUPLED as $coupled) {
                    $this->assertStringNotContainsString(
                        $coupled,
                        $entry,
                        "GET {$path} resolves {$entry}, which puts a cookie on a page that needs none. "
                        .'These routes belong in routes/marketing.php, outside the `web` group.',
                    );
                }
            }
        }
    }

    public function test_no_catalog_route_sits_in_the_web_group(): void
    {
        // The group NAME, before resolution: the test above would also pass against a
        // hand-listed copy of `web` minus its session pieces.
        foreach ($this->catalogPaths() as $path) {
            $this->assertNotContains(
                'web',
                $this->routeFor($path)->gatherMiddleware(),
                "GET {$path} is in the `web` group, so it inherits StartSession whatever else is done to it.",
            );
        }
    }

    public function test_the_catalog_routes_bind_parameters_set_the_locale_and_are_throttled(): void
    {
        /*
         * The positive control for the two tests above, which both pass just as
         * happily against a route that lost its middleware entirely, plus the throttle
         * requirement: these pages share one Octane instance with the API and with
         * paying customers' status pages.
         */
        foreach ($this->catalogPaths() as $path) {
            $middleware = $this->middlewareFor($path);

            $this->assertContains(SubstituteBindings::class, $middleware, "GET {$path} no longer binds route parameters.");
            $this->assertContains(SetMarketingLocale::class, $middleware, "GET {$path} no longer applies the language its URL asks for.");
        }

        /*
         * The throttle is asserted on the PAGES and not on the default locale's 301s,
         * which are `Route::redirect` registrations answered by the framework from the
         * route definition alone: no controller, no query, no cache. The documents
         * beside them leave their own redirects unthrottled for the same reason.
         */
        foreach ($this->catalogPagePaths() as $path) {
            $throttled = array_filter(
                $this->middlewareFor($path),
                static fn (string $entry): bool => str_contains($entry, 'ThrottleRequests'),
            );

            $this->assertNotSame([], $throttled, "GET {$path} is not throttled.");
        }
    }

    public function test_the_catalog_owns_its_own_route_names_and_leaves_the_customer_page_alone(): void
    {
        /*
         * `status.show` belongs to the customer status page and is consumed by
         * `StatusPagePreviewRenderer` and `status/partials/footer.blade.php`.
         * Re-registering it here would silently retarget a named route somebody else
         * reads, and the preview would break with nothing failing on this surface.
         */
        $routes = app('router')->getRoutes();

        $this->assertSame(
            ShowServiceIndexController::class,
            $routes->getByName('services.index')?->getActionName(),
        );
        $this->assertSame(
            ShowServiceStatusController::class,
            $routes->getByName('services.show')?->getActionName(),
        );
        $this->assertStringContainsString(
            ShowStatusPageController::class,
            (string) $routes->getByName('status.show')?->getActionName(),
        );
    }

    public function test_no_unreplaced_placeholder_reaches_the_page(): void
    {
        $this->publish();

        foreach ($this->supported() as $locale) {
            foreach ([$this->hubPath($locale), $this->pagePath($locale)] as $path) {
                $response = $this->get($path)->assertOk();

                // `LegalDocument` leaves an unmapped `[[key]]` in its output verbatim
                // rather than stripping it, so a bracket on the page means a controller
                // forgot a replacement.
                $response->assertDontSee('[[')->assertDontSee(']]');

                // And the translator's own placeholders, which render literally when the
                // replacement array is forgotten: silent, no exception, just a sentence
                // with `:count` in it.
                foreach ([':count', ':service', ':endpoint', ':ms', ':total', ':threshold', ':quorum', ':days', ':indicator', ':time'] as $placeholder) {
                    $response->assertDontSee($placeholder);
                }
            }
        }
    }

    public function test_both_provenances_are_labelled_and_the_divergence_is_stated_when_they_disagree(): void
    {
        // We reached the endpoint from two regions; they publish a major outage on a
        // component. Both are honest, and the page must show both rather than pick.
        $service = $this->publish();
        $this->reach($service, MonitorRegion::USEast);
        $this->reach($service, MonitorRegion::EUWest);
        $this->snapshot($service, indicator: 'major', components: [
            ['label' => 'API', 'status' => ComponentStatus::MajorOutage->value],
        ]);

        $response = $this->get($this->pagePath('en'))->assertOk();

        $response->assertSee('Measured by Uptizm')
            ->assertSee('Published by '.self::NAME)
            ->assertSee('We reached '.self::ENDPOINT.' normally.')
            ->assertSee('They report: major')
            ->assertSee('These two do not agree right now');
    }

    public function test_agreement_does_not_state_a_divergence(): void
    {
        /*
         * The inverse control, and it is what makes the test above mean something: if
         * the divergence sentence were unconditional it would assert nothing at all.
         */
        $service = $this->publish();
        $this->reach($service, MonitorRegion::USEast);
        $this->reach($service, MonitorRegion::EUWest);
        $this->snapshot($service, indicator: 'none', components: [
            ['label' => 'API', 'status' => ComponentStatus::Operational->value],
        ]);

        $this->get($this->pagePath('en'))
            ->assertOk()
            ->assertSee('Measured by Uptizm')
            ->assertSee('Published by '.self::NAME)
            ->assertDontSee('These two do not agree right now');
    }

    public function test_a_stale_provider_quote_does_not_produce_a_divergence(): void
    {
        // Our probe is fine, their last publication says outage but is older than the
        // freshness bound: a disagreement with a stale quote is not a disagreement, and
        // the page says the quote is old rather than presenting it as current.
        $service = $this->publish();
        $this->reach($service, MonitorRegion::USEast);
        $this->reach($service, MonitorRegion::EUWest);
        $this->snapshot(
            $service,
            indicator: 'critical',
            components: [],
            ageSeconds: ReadingFreshness::STALE_AFTER_SECONDS + 60,
        );

        $this->get($this->pagePath('en'))
            ->assertOk()
            ->assertDontSee('These two do not agree right now')
            ->assertSee('not necessarily what they publish now');
    }

    public function test_a_provider_incident_is_quoted_with_a_safe_link_only(): void
    {
        /*
         * Their open incidents, rendered as their claim with a link back to their own
         * page, and NOT as an incident of ours.
         *
         * This case exists because the live QA walk found a fatal here that the whole
         * suite passed over: the incident mapper was a `static` closure calling an
         * instance method, and no fixture had ever put an incident in a snapshot, so the
         * loop was never entered. It also pins the scheme guard, because these values
         * come from a remote document and land in an `href`, where Blade escapes the
         * characters but not the meaning.
         */
        $service = $this->publish();
        $this->reach($service, MonitorRegion::USEast);
        $this->snapshot($service, indicator: 'minor', components: [], incidents: [
            [
                'title' => 'Degraded performance for Actions',
                'impact' => 'minor',
                'started_at' => now()->subMinutes(20)->toIso8601String(),
                'url' => 'https://status.example.test/incidents/abc123',
            ],
            [
                'title' => 'Hostile link',
                'impact' => null,
                'started_at' => null,
                'url' => 'javascript:alert(1)',
            ],
        ]);

        $html = $this->get($this->pagePath('en'))->assertOk()->getContent();

        $this->assertStringContainsString('Degraded performance for Actions', $html);
        $this->assertStringContainsString('href="https://status.example.test/incidents/abc123"', $html);
        // Quoted as theirs, and the unsafe scheme dropped rather than escaped.
        $this->assertStringContainsString('Hostile link', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_a_service_with_no_checks_renders_the_no_data_treatment(): void
    {
        $this->publish();

        $html = $this->get($this->pagePath('en'))->assertOk()->getContent();

        $this->assertStringContainsString('We have no recent reading for '.self::ENDPOINT, $html);

        // Not `0%`, not `100%`, and not any other figure: a monitor whose first probe
        // has not run has nothing to report, and printing a number there is a claim
        // about days nobody measured.
        foreach (['0%', '100%', '0.00%', '100.00%'] as $fabricated) {
            $this->assertStringNotContainsString($fabricated, $html);
        }

        // The unmeasured days are the NEUTRAL family, never `bg-up`.
        $this->assertStringContainsString('bg-paused', $html);
        $this->assertStringNotContainsString('bg-up', $html);
    }

    public function test_no_percentage_is_published_anywhere_near_the_provider(): void
    {
        /*
         * The rule this surface exists under: uptizm probes ONE endpoint of one
         * product, so a percentage would imply a coverage it does not have, and this is
         * the same defect class as the fabricated SLO this repo already removed once.
         *
         * Asserted against the RENDERED TEXT and in both directions, because omitting a
         * number from a template is not the same as it being absent from the response.
         * Verified by mutation: printing `99.9%` beside the service's name in
         * `service-status.blade.php` reddens both assertions below.
         */
        $service = $this->publish();
        $this->reach($service, MonitorRegion::USEast);
        $this->reach($service, MonitorRegion::EUWest);
        $this->snapshot($service, indicator: 'none', components: []);

        $text = $this->textOf($this->get($this->pagePath('en'))->assertOk());
        $name = preg_quote(self::NAME, '/');

        $this->assertDoesNotMatchRegularExpression(
            '/'.$name.'.{0,200}?\d+(?:[.,]\d+)?\s?%/u',
            $text,
            'A percentage is published next to the provider name, which reads as their uptime.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\d+(?:[.,]\d+)?\s?%.{0,200}?'.$name.'/u',
            $text,
            'A percentage is published just before the provider name, which reads as their uptime.',
        );

        // And the strip is labelled as what it is: OUR reachability of the named
        // endpoint, with the endpoint visible.
        $this->assertStringContainsString('Uptizm reachability of '.self::ENDPOINT, $text);
    }

    public function test_the_headline_holds_below_the_stricter_public_bar(): void
    {
        /*
         * Three fixtures, one condition apart, because the rule is a conjunction: the
         * monitor's own consecutive-failure threshold AND more than one region
         * agreeing. A single fixture would leave either half unpinned.
         */
        $service = $this->publish(monitorAttributes: [
            'incident_threshold' => 2,
            'consecutive_fails' => 5,
        ]);

        // 1. The streak is well past the threshold, but only ONE region failed.
        $this->fail_($service, MonitorRegion::USEast);
        $this->reach($service, MonitorRegion::EUWest);

        $this->get($this->pagePath('en'))
            ->assertOk()
            ->assertSee('We reached '.self::ENDPOINT.' normally.')
            ->assertDontSee('We could not reach '.self::ENDPOINT.'.')
            // The dissent is stated rather than hidden.
            ->assertSee('One or more regions disagreed');
    }

    public function test_the_headline_holds_when_two_regions_fail_below_the_threshold(): void
    {
        /*
         * Two regions agree the endpoint is down, but the monitor's own streak has
         * not crossed its threshold, so this page does NOT call it an outage.
         *
         * WHAT THIS TEST USED TO ASSERT, AND WHY THAT WAS THE BUG
         *
         * It asserted only `assertDontSee('We could not reach ...')`, i.e. that the
         * page withheld the NEGATIVE claim, and never what the page actually said.
         * What it said was "We reached github.com normally" with a green dot, while
         * both fresh regions were reporting down. An absence-only assertion cannot
         * tell withholding a claim from asserting its opposite, which is exactly how
         * that shipped.
         *
         * That state is not a corner case either. `consecutive_fails` resets to 0 on
         * ANY non-down result from ANY region
         * (`CheckPersistenceService.php:305-315`), so while even one region still
         * succeeds the streak cannot climb and a partial outage stays here for its
         * whole duration.
         */
        $service = $this->publish(monitorAttributes: [
            'incident_threshold' => 3,
            'consecutive_fails' => 1,
        ]);

        $this->fail_($service, MonitorRegion::USEast);
        $this->fail_($service, MonitorRegion::EUWest);

        $this->get($this->pagePath('en'))
            ->assertOk()
            // Still no outage claim.
            ->assertDontSee('We could not reach '.self::ENDPOINT.'.')
            // And, the half that was missing: no claim of normality either.
            ->assertDontSee('We reached '.self::ENDPOINT.' normally.')
            // What it DOES say.
            // Both fresh readings say down, so "some regions and not others" would be
            // false: NO region reached it. The rung withholds the outage claim without
            // asserting a reachability that did not happen.
            ->assertSee('No region reached '.self::ENDPOINT.' on our last check, and we do not call that an outage yet.')
            ->assertSee('One or more regions disagreed');
    }

    public function test_a_published_service_that_lost_its_last_monitor_disappears_from_every_surface(): void
    {
        /*
         * `Service::canPublish()` guards the publish TRANSITION only, and nothing
         * re-checked it afterwards. So a service published legitimately and then
         * stripped of its last monitor kept `is_published = true`, and its page
         * answered 200 with the provider's re-rendered feed as its entire substance:
         * exactly the page this catalog promised never to publish, and the exact
         * thin-content exposure its scaled-content risk was accepted against.
         *
         * Reproduced against the dev database before the fix (page 200, body
         * containing "no endpoint of our own"), which is why the predicate is now
         * re-derived on read in all three places rather than trusted from the column.
         * All three are asserted here, because fixing one surface and not the others
         * produces a sitemap advertising a URL that 404s.
         */
        $service = $this->publish();

        // Control: it IS on every surface while it still has its monitor.
        $this->get($this->pagePath('en'))->assertOk();
        $this->get($this->hubPath('en'))->assertOk()->assertSee($service->name);
        $this->get('/sitemap-services.xml')->assertOk()->assertSee($this->pagePath('en'), escape: false);

        $service->monitors()->detach();

        /*
         * Both public reads sit behind a deliberate 60-second `Cache::remember`, and
         * the control requests above warmed it. Flushed here so this test measures the
         * predicate rather than the cache window. The window itself is intended: a
         * withdrawal takes up to a minute to leave the hub, which is bounded staleness
         * rather than a wrong page, and the ingester's targeted forget covers the
         * feed-driven case.
         */
        Cache::flush();

        // Still flagged published: the column is deliberately untouched, since the
        // point is that the READ path no longer trusts it.
        $this->assertTrue($service->fresh()->is_published);
        $this->assertFalse($service->fresh()->canPublish());

        $this->get($this->pagePath('en'))->assertNotFound();
        $this->get($this->pagePath('tr'))->assertNotFound();
        $this->get($this->hubPath('en'))->assertOk()->assertDontSee($service->name);
        $this->get('/sitemap-services.xml')->assertOk()->assertDontSee($this->pagePath('en'), escape: false);
    }

    public function test_the_hub_is_never_more_confident_than_the_page_it_links_to(): void
    {
        /*
         * The hub rendered only two verdicts while the detail page rendered three,
         * so a row on `/status` asserted "we reached it normally" over an endpoint
         * whose own page was already qualifying that claim. A summary may be shorter
         * than the page; it may not be more confident.
         */
        $service = $this->publish(monitorAttributes: [
            'incident_threshold' => 3,
            'consecutive_fails' => 1,
        ]);

        $this->fail_($service, MonitorRegion::USEast);
        $this->fail_($service, MonitorRegion::EUWest);

        $this->get($this->hubPath('en'))
            ->assertOk()
            ->assertDontSee('We reached '.self::ENDPOINT.' normally.')
            ->assertSee('No region reached '.self::ENDPOINT.' on our last check, and we do not call that an outage yet.');
    }

    public function test_a_single_region_failure_withholds_the_claim_without_inventing_one(): void
    {
        /*
         * One configured region, reporting down, below the threshold. `downRegions` is
         * 1 so the outage quorum is not met, and the affirmative rung is refused
         * because no region succeeded.
         *
         * This fixture did not exist when the `upRegions === 0` arm was added, so
         * deleting that arm left the whole suite green. The arm changes what a public
         * page SAYS, and this plan's own conventions require a test in the same change.
         */
        $service = $this->publish(monitorAttributes: [
            'incident_threshold' => 3,
            'consecutive_fails' => 1,
            'regions' => [MonitorRegion::USEast->value],
        ]);

        $this->fail_($service, MonitorRegion::USEast);

        $this->get($this->pagePath('en'))
            ->assertOk()
            ->assertDontSee('We reached '.self::ENDPOINT.' normally.')
            ->assertDontSee('We could not reach '.self::ENDPOINT.'.')
            // And specifically NOT the "some and not others" wording, which would be
            // false of a single region that failed.
            ->assertDontSee('We are reaching '.self::ENDPOINT.' from some regions and not others.')
            ->assertSee('No region reached '.self::ENDPOINT.' on our last check, and we do not call that an outage yet.');
    }

    public function test_a_single_fresh_reading_is_not_enough_to_claim_the_endpoint_is_reached(): void
    {
        /*
         * One configured region, ONE fresh reading, and it says up: `downRegions` is
         * 0, the quorum for an outage claim is unmet either way, and `upRegions` is 1
         * so the `upRegions === 0` rung does not fire either. Before this floor
         * existed, that combination fell all the way through to `default` and printed
         * "We reached api.example.test normally." on a single observation.
         *
         * That state used to be theoretical, because Cloudflare's region-pinned colos
         * essentially do not die. This plan moves these monitors onto proxy exits that
         * die routinely, which turns "one region answered, the rest never got a
         * chance to" from a rare race into the normal shape of a probe cycle. The
         * headline has to stop being confident exactly when the evidence behind it
         * shrinks to one region's word.
         */
        $service = $this->publish(monitorAttributes: [
            'regions' => [MonitorRegion::USEast->value],
        ]);

        $this->reach($service, MonitorRegion::USEast);

        $this->get($this->pagePath('en'))
            ->assertOk()
            // The claim this floor exists to withhold.
            ->assertDontSee('We reached '.self::ENDPOINT.' normally.')
            // The floor gets its OWN wording rather than reusing the empty-readings
            // one. The unknown state now covers two different facts, and the sentence
            // written for the empty case ("Nothing has been measured in the last N
            // seconds") is FALSE here: one region did measure, it is simply not enough
            // to speak for the endpoint. Publishing a false sentence on the page whose
            // whole subject is what we actually measured is worse than the confident
            // claim this floor was added to withhold.
            ->assertSee('Not enough regions answered for us to speak for '.self::ENDPOINT)
            ->assertSee('Only 1 region answered our last check')
            ->assertDontSee('Nothing has been measured in the last');

        // The status value itself, not only the sentence built from it: the sentence
        // is what a template author could get right by accident, the constant is what
        // proves the assembler actually reclassified the endpoint.
        $data = app(ServicePageAssembler::class)->build($service->fresh());

        $this->assertSame(StatusPageAssembler::STATUS_UNKNOWN, $data['own']['endpoints'][0]['status']);
    }

    public function test_two_fresh_readings_clear_the_quorum_floor_for_the_reached_verdict(): void
    {
        /*
         * The other side of the same floor: two distinct regions both fresh and both
         * up is enough evidence, and the affirmative verdict still fires. Pinned
         * separately from the prose assertions above (`test_the_headline_holds_below_the_stricter_public_bar`
         * and its siblings already cover the rendered sentence for two agreeing
         * regions) so the floor's THRESHOLD, not just its existence, is proven: one
         * region withholds the claim, two regions do not.
         */
        $service = $this->publish();

        $this->reach($service, MonitorRegion::USEast);
        $this->reach($service, MonitorRegion::EUWest);

        $data = app(ServicePageAssembler::class)->build($service->fresh());

        $this->assertSame(ServicePageAssembler::VERDICT_REACHED, $data['own']['endpoints'][0]['status']);
    }

    public function test_every_region_answering_degraded_says_so_rather_than_claiming_normality(): void
    {
        /*
         * The other state the rung reaches, and the one where the branch ORDER matters.
         * Every region answered, none is down, so `upRegions` is 0 AND `downRegions` is
         * 0. Testing `upRegions === 0` first would print "no region reached it" over
         * readings where every region did.
         */
        $service = $this->publish(monitorAttributes: [
            'incident_threshold' => 3,
            'consecutive_fails' => 0,
        ]);

        $this->check($service, MonitorRegion::USEast, MonitorStatus::Degraded, 40, 210);
        $this->check($service, MonitorRegion::EUWest, MonitorStatus::Degraded, 40, 240);

        $this->get($this->pagePath('en'))
            ->assertOk()
            ->assertDontSee('We reached '.self::ENDPOINT.' normally.')
            ->assertDontSee('No region reached '.self::ENDPOINT.' on our last check, and we do not call that an outage yet.')
            ->assertSee('Every region reached '.self::ENDPOINT.', but not all of them normally.');
    }

    public function test_the_mixed_state_is_translated_and_suppresses_the_divergence_sentence(): void
    {
        /*
         * Two assertions that belong together because they are the two ways the new
         * rung could be half-finished.
         *
         * TRANSLATION: the earlier localisation pass translated the strings that
         * existed then, and `__()` falls back to its English source in silence, so a
         * NEW string publishes English under `hreflang="tr"`. That is the same defect
         * recurring, which is why this asserts the Turkish page rather than trusting
         * the pass that came before it.
         *
         * DIVERGENCE: the rung's only reach past the words on the page is
         * `healthyFrom()` mapping it to null, so a mixed own-block holds no opinion
         * and the divergence sentence stays away. That is correct (an amber own-block
         * and a provider reporting trouble do not disagree) and it is exactly what a
         * future contributor mapping the rung to `false` would break.
         */
        $service = $this->publish(monitorAttributes: [
            'incident_threshold' => 3,
            'consecutive_fails' => 1,
        ]);

        $this->fail_($service, MonitorRegion::USEast);
        $this->fail_($service, MonitorRegion::EUWest);

        $this->get($this->pagePath('tr'))
            ->assertOk()
            ->assertSee('hiçbir bölge', escape: false)
            ->assertDontSee('No region reached '.self::ENDPOINT.' on our last check, and we do not call that an outage yet.')
            // The divergence sentence must be absent: the own block has no opinion.
            ->assertDontSee('Bizim ölçümümüz ile onların bildirdiği şu anda uyuşmuyor.', escape: false);
    }

    public function test_the_headline_reports_a_problem_once_both_conditions_hold(): void
    {
        $service = $this->publish(monitorAttributes: [
            'incident_threshold' => 2,
            'consecutive_fails' => 2,
        ]);

        $this->fail_($service, MonitorRegion::USEast);
        $this->fail_($service, MonitorRegion::EUWest);

        $this->get($this->pagePath('en'))
            ->assertOk()
            ->assertSee('We could not reach '.self::ENDPOINT.'.')
            ->assertSee('Measured by Uptizm');
    }

    public function test_a_stale_reading_is_unknown_and_never_its_last_value(): void
    {
        // A reading past the bound is not shown as current and is not frozen at the
        // value we happened to hold: the checks older than the window are never read.
        $service = $this->publish();
        $this->reach(
            $service,
            MonitorRegion::USEast,
            ageSeconds: ReadingFreshness::STALE_AFTER_SECONDS + 60,
            responseMs: 4242,
        );

        $html = $this->get($this->pagePath('en'))->assertOk()->getContent();

        $this->assertStringContainsString('We have no recent reading for '.self::ENDPOINT, $html);
        $this->assertStringNotContainsString('4242', $html);
        // The neutral family, which is what StatusPresentation resolves
        // `StatusPageAssembler::STATUS_UNKNOWN` to.
        $this->assertStringContainsString('bg-paused', $html);

        /*
         * And the read model itself carries the EXISTING unknown token rather than a
         * second one invented for this surface, asserted through the constant by name so
         * a renamed vocabulary fails here instead of on a page.
         */
        $data = app(ServicePageAssembler::class)->build($service->fresh());

        $this->assertSame(StatusPageAssembler::STATUS_UNKNOWN, $data['own']['status']);
        $this->assertSame(StatusPageAssembler::STATUS_UNKNOWN, $data['own']['endpoints'][0]['status']);
        $this->assertTrue($data['own']['endpoints'][0]['stale']);
        $this->assertNull($data['own']['healthy']);
        $this->assertNull($data['own']['endpoints'][0]['responseMs']);
    }

    public function test_the_page_carries_no_provider_artwork_and_no_foreign_structured_data(): void
    {
        $service = $this->publish();
        $this->reach($service, MonitorRegion::USEast);

        $html = $this->get($this->pagePath('en'))->assertOk()->getContent();

        $this->assertStringNotContainsString('og:image', $html);
        $this->assertStringNotContainsString('<img', $html);

        preg_match_all('/"@type"\s*:\s*"([^"]+)"/', $html, $matches);

        $this->assertNotSame([], $matches[1], 'The page emitted no structured data at all, so this walk checked nothing.');
        $this->assertSame(['WebPage'], array_values(array_unique($matches[1])));
    }

    public function test_the_read_model_is_cached_under_the_key_the_ingester_forgets(): void
    {
        /*
         * `FeedFetcher::PAGE_CACHE_KEY_PREFIX` is a contract: the ingester forgets
         * `<prefix><slug>:<locale>` when a feed's normalized hash changes. A page that
         * invented its own key would serve a stale provider status and nothing would
         * fail.
         */
        $this->publish();

        Cache::flush();
        $this->get($this->pagePath('en'))->assertOk();

        $this->assertTrue(Cache::has(FeedFetcher::PAGE_CACHE_KEY_PREFIX.self::SLUG.':en'));
    }

    public function test_the_hub_lists_only_publishable_services(): void
    {
        $this->publish();
        $this->publish([
            'slug' => 'draft-provider',
            'name' => 'Draft Provider',
            'is_published' => false,
        ], endpoint: 'api.draft.test');
        $this->publish([
            'slug' => 'unreviewed-provider',
            'name' => 'Unreviewed Provider',
            'terms_reviewed_at' => null,
        ], endpoint: 'api.unreviewed.test');

        $this->get('/status')
            ->assertOk()
            ->assertSee(self::NAME)
            ->assertSee('href="/status/'.self::SLUG.'"', escape: false)
            ->assertDontSee('Draft Provider')
            ->assertDontSee('Unreviewed Provider');
    }

    public function test_the_provenance_labels_are_the_enums_own_two_cases(): void
    {
        // A guard on the partial's `match`: if a third provenance were added, the row
        // it renders would throw rather than appear unlabelled, and this names the two
        // that exist today so the assertion above cannot pass on a typo.
        $this->assertSame(
            ['own_probe', 'provider_feed'],
            array_column(StatusProvenance::cases(), 'value'),
        );
    }

    /**
     * A publishable catalog service with one attached system-team monitor.
     *
     * Both `canPublish()` conditions are satisfied by default and each test breaks
     * exactly ONE of them, which is what keeps every refusal branch isolated.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $monitorAttributes
     */
    private function publish(
        array $attributes = [],
        array $monitorAttributes = [],
        string $endpoint = self::ENDPOINT,
    ): Service {
        $team = SystemTeam::resolve();

        $service = Service::factory()->create([
            'slug' => self::SLUG,
            'name' => self::NAME,
            'category' => 'cloud',
            'status_source' => ServiceStatusSource::None,
            'terms_reviewed_at' => now()->subMonth(),
            'is_published' => true,
            ...$attributes,
        ]);

        $monitor = Monitor::query()->create([
            'team_id' => $team->getKey(),
            'name' => $service->name.' ('.$endpoint.')',
            'type' => MonitorType::Http,
            'method' => HttpMethod::Get,
            'url' => 'https://'.$endpoint,
            'check_interval_sec' => 60,
            'regions' => MonitorRegion::values(),
            'ai_mode' => AiMode::Off,
            'alert_on_down' => false,
            ...$monitorAttributes,
        ]);

        $service->monitors()->attach($monitor->getKey(), ['label' => $endpoint]);

        return $service;
    }

    /**
     * Record a successful check for one region.
     */
    private function reach(
        Service $service,
        MonitorRegion $region,
        int $ageSeconds = 40,
        ?int $responseMs = 210,
    ): void {
        $this->check($service, $region, MonitorStatus::Up, $ageSeconds, $responseMs);
    }

    /**
     * Record a failed check for one region. Named with a trailing underscore
     * because `fail()` is PHPUnit's own.
     */
    private function fail_(Service $service, MonitorRegion $region, int $ageSeconds = 40): void
    {
        $this->check($service, $region, MonitorStatus::Down, $ageSeconds, null);
    }

    private function check(
        Service $service,
        MonitorRegion $region,
        MonitorStatus $status,
        int $ageSeconds,
        ?int $responseMs,
    ): void {
        $monitor = $service->monitors()->firstOrFail();

        $monitor->checks()->create([
            'id' => (string) Str::orderedUuid(),
            'team_id' => $monitor->team_id,
            'region' => $region->value,
            'status' => $status,
            'response_ms' => $responseMs,
            'checked_at' => now()->subSeconds($ageSeconds),
        ]);
    }

    /**
     * Record what the provider published.
     *
     * @param  list<array{label: string, status: string|null}>  $components
     * @param  list<array<string, mixed>>  $incidents
     */
    private function snapshot(
        Service $service,
        ?string $indicator,
        array $components,
        array $incidents = [],
        int $ageSeconds = 30,
    ): ServiceFeedSnapshot {
        return ServiceFeedSnapshot::query()->create([
            'service_id' => $service->getKey(),
            'fetched_at' => now()->subSeconds($ageSeconds),
            'http_status' => 200,
            'indicator' => $indicator,
            'components' => $components,
            'incidents' => $incidents,
            'content_hash_normalized' => hash('sha256', (string) json_encode([$indicator, $components, $incidents])),
        ]);
    }

    /**
     * The languages the whole product speaks, from the config the routes read.
     *
     * @return list<string>
     */
    private function supported(): array
    {
        return array_values((array) config('magic-starter.supported_locales', []));
    }

    private function hubPath(string $locale): string
    {
        return $locale === config('app.default_locale') ? '/status' : '/'.$locale.'/status';
    }

    private function pagePath(string $locale, string $slug = self::SLUG): string
    {
        return $locale === config('app.default_locale')
            ? '/status/'.$slug
            : '/'.$locale.'/status/'.$slug;
    }

    /**
     * Every path this step registers for the catalog, the default locale's 301 forms
     * included: a redirect response carries headers like any other.
     *
     * @return list<string>
     */
    private function catalogPaths(): array
    {
        $default = (string) config('app.default_locale');

        $paths = [
            '/status',
            '/'.$default.'/status',
            '/status/'.self::SLUG,
            '/'.$default.'/status/'.self::SLUG,
        ];

        foreach (array_diff($this->supported(), [$default]) as $locale) {
            $paths[] = '/'.$locale.'/status';
            $paths[] = '/'.$locale.'/status/'.self::SLUG;
        }

        return $paths;
    }

    /**
     * The catalog paths that RENDER a page, so without the default locale's 301
     * forms.
     *
     * @return list<string>
     */
    private function catalogPagePaths(): array
    {
        $default = (string) config('app.default_locale');

        return array_values(array_filter(
            $this->catalogPaths(),
            static fn (string $path): bool => ! str_starts_with($path, '/'.$default.'/'),
        ));
    }

    /**
     * The `meta name="description"` content of a response, or an empty string.
     */
    private function descriptionOf(TestResponse $response): string
    {
        preg_match('/<meta name="description" content="([^"]*)">/', $response->getContent(), $matches);

        return $matches[1] ?? '';
    }

    /**
     * A response's visible text, tags stripped and whitespace collapsed.
     *
     * The percentage walk runs against THIS rather than the raw HTML: markup and
     * class names sit between two words that are adjacent on screen, so an adjacency
     * regex over raw HTML would measure the distance between attributes instead of
     * between sentences.
     */
    private function textOf(TestResponse $response): string
    {
        return (string) preg_replace('/\s+/u', ' ', strip_tags($response->getContent()));
    }

    /**
     * The resolved middleware class list for the route that answers a path.
     *
     * @return list<string>
     */
    private function middlewareFor(string $path): array
    {
        $middleware = app('router')->gatherRouteMiddleware($this->routeFor($path));

        foreach ($middleware as $entry) {
            // A closure middleware would sail through every substring assertion in this
            // file without being readable at all, so fail on it here instead.
            $this->assertIsString($entry, "GET {$path} carries a middleware that is not a class name.");
        }

        return array_values($middleware);
    }

    /**
     * The route that actually answers a GET of a path.
     *
     * Matched from a request rather than looked up by name: the default locale's 301
     * has no name, and matching is what a visitor's request does, so a route that
     * exists but is shadowed by another cannot pass.
     */
    private function routeFor(string $path): Route
    {
        return app('router')->getRoutes()->match(Request::create($path, 'GET'));
    }

    public function test_the_header_tile_carries_the_mark_self_hosted_and_never_from_a_third_party(): void
    {
        /*
         * The catalog ships six of its eight marks under `resources/svg/brands/` and
         * inlines them. The self-hosting is the load-bearing half, not the logo:
         * `resources/legal/privacy.en.md` publishes that this read-only surface reaches
         * NO third-party host, so pulling a mark from a CDN, a favicon service or the
         * provider's own domain would make that party a recipient of every visitor's IP
         * and falsify a published statement. That is the regression this test exists to
         * catch, because "just use an <img src>" is the obvious thing a future
         * contributor would reach for.
         */
        $service = $this->publish(['slug' => 'github', 'name' => 'GitHub']);
        $service->forceFill(['brand_color' => '#181717'])->save();

        // The read model is cached for a minute and `publish()` may already have warmed
        // it, so this measures the render rather than the cache.
        Cache::flush();

        // A mark this catalog actually ships, so the branch under test is reachable.
        $logo = resource_path('svg/brands/github.svg');
        $this->assertFileExists($logo, 'The bundled marks are gone, so this asserts nothing.');

        $html = $this->get($this->pagePath('en', 'github'))->assertOk()->getContent();

        // Asserted piecewise rather than with one regex spanning the whole tag: Blade's
        // `@class` and the conditional `style` do not promise an attribute order, and a
        // test that encodes one breaks on a reformat rather than on a regression.
        $this->assertStringContainsString('background-color: #181717', $html);
        $this->assertStringContainsString('text-white', $html);
        // The mark itself, inlined from the repository.
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('<title>GitHub</title>', $html);

        // NOTHING is fetched. No image element and no remote reference of any kind.
        $this->assertSame(0, substr_count($html, '<img'));
        $this->assertStringNotContainsString('og:image', $html);
        foreach (['clearbit', 'googleusercontent', 'gstatic', 'favicon', 'cdn.'] as $thirdParty) {
            $this->assertStringNotContainsStringIgnoringCase(
                $thirdParty,
                $html,
                "The header tile is reaching a third-party host ({$thirdParty}), which the privacy notice says this surface never does.",
            );
        }
    }

    public function test_a_service_with_no_mark_or_colour_falls_back_to_its_monogram(): void
    {
        /*
         * OpenAI and Slack have neither, and the reason is why this branch must keep
         * working rather than be treated as a corner case: the CC0 dataset the other
         * six marks came from removes a brand when its owner asks, so those two
         * absences ARE the two objections. The fallback is the product's own brand pair
         * and the service's initials.
         *
         * Two words in the fixture name, so both initials: EP.
         */
        $service = $this->publish();
        $service->forceFill(['brand_color' => null])->save();

        Cache::flush();

        $html = $this->get($this->pagePath('en'))->assertOk()->getContent();

        $this->assertStringContainsString('bg-primary text-on-primary', $html);
        $this->assertMatchesRegularExpression('/>\s*EP\s*</', $html);
        $this->assertStringNotContainsString('background-color', $html);

        // And never a monitoring-status colour behind the name: an amber or red tile on
        // a status page reads as a warning about the service it labels. A deterministic
        // per-service accent was tried and reverted for exactly that.
        foreach (['bg-degraded', 'bg-down', 'bg-up', 'bg-paused', 'bg-info', 'bg-ai'] as $statusFamily) {
            $this->assertStringNotContainsString('rounded-lg '.$statusFamily, $html);
        }
    }
}
