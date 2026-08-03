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
 *   - a reading older than {@see ServicePageAssembler::STALE_AFTER_SECONDS} shown as
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
            ageSeconds: ServicePageAssembler::STALE_AFTER_SECONDS + 60,
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
        // The other half: two regions agree, but the monitor's own streak has not
        // crossed its threshold, so a public page still says nothing.
        $service = $this->publish(monitorAttributes: [
            'incident_threshold' => 3,
            'consecutive_fails' => 1,
        ]);

        $this->fail_($service, MonitorRegion::USEast);
        $this->fail_($service, MonitorRegion::EUWest);

        $this->get($this->pagePath('en'))
            ->assertOk()
            ->assertDontSee('We could not reach '.self::ENDPOINT.'.');
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
            ageSeconds: ServicePageAssembler::STALE_AFTER_SECONDS + 60,
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
}
