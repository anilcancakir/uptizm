<?php

namespace App\Http\Controllers\Marketing;

use App\Enums\MonitorRegion;
use App\Enums\NotificationChannelType;
use App\Models\Monitor;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Locale;

/**
 * The landing page on the apex host.
 *
 * One rule governs this controller: A CLAIM IS DERIVED, NEVER TYPED. Anything the
 * page asserts about the product comes from the enum or config that actually
 * governs the behaviour, so the page cannot drift into advertising a region we do
 * not probe from or a limit we do not honour.
 *
 * It grows a method per section as the page is rebuilt.
 */
class ShowLandingController
{
    public function __invoke(): View
    {
        return view('landing', [
            'regions' => $this->regions(),
            'freeTier' => $this->freeTier(),
            'signInUrl' => $this->clientUrl('/login'),
            'signUpUrl' => $this->clientUrl('/register'),
            'aiEnabled' => $this->aiEnabled(),
            'channels' => $this->channels(),
            'platformClaim' => $this->platformClaim(),
            'stageLabels' => $this->stageLabels(),
            'heroBeats' => $this->heroBeats(),
            'summary' => $this->summary(),
            'localeLinks' => $this->localeLinks(),
            'homePath' => $this->pathFor(app()->getLocale()),
            'canonicalUrl' => $this->urlFor(app()->getLocale()),
            'sections' => $this->sections(),
            'decisionRules' => $this->decisionRules(),
        ]);
    }

    /**
     * The in-page sections the header and footer may link to.
     *
     * Both render their nav from this list, so a nav entry cannot outrun the section
     * it points at. Add an entry in the same change that adds the section, never
     * before: `ChromeTest` walks every in-page anchor on the rendered page and fails
     * the build on one with no matching id.
     *
     * @return list<array{id: string, label: string}>
     */
    protected function sections(): array
    {
        return [
            ['id' => 'how-it-decides', 'label' => __('How it decides')],
        ];
    }

    /**
     * The rules that turn probe results into a verdict.
     *
     * These are PUBLISHED, which is the whole point of the section. The category's
     * leader headlines "no false positives" and leaves it at an assertion; the thing
     * we can actually do differently is show the rule that produces the verdict.
     *
     * So every rule here is read out of the code that enforces it, and the mechanism
     * line is the real mechanism rather than a paraphrase:
     *
     *   1  ScheduleMonitorChecks dispatches one job per region per tick
     *   2  CheckPersistenceService resets `consecutive_fails` to 0 on any non-down
     *      result, which is what stops a single failing region from paging anybody
     *   3  ThresholdEvaluator opens nothing until the streak crosses
     *      `incident_threshold`, whose default is this constant
     *   4  recordProbeRefusal() writes only the two error columns and deliberately
     *      leaves `last_status` and the streak alone, because our own edge refusing a
     *      probe is not evidence about the customer's endpoint either way
     *
     * Deliberately NOT claimed here: a quorum. There is no "two regions must agree"
     * rule in the code. `last_status` is whatever the most recent check said, and the
     * protection against one bad region is rule 2, not a vote. Advertising a quorum
     * would have been the easy sentence to write and it would have been false.
     *
     * @return list<array{title: string, body: string, mechanism: string}>
     */
    protected function decisionRules(): array
    {
        $regions = count($this->regions());

        return [
            [
                'title' => __('One tick, every region'),
                'body' => __('All :count regions run in the same tick rather than taking turns, so a slow region is a fact about that region and not about where it sat in a queue.', ['count' => $regions]),
                'mechanism' => '1 job × '.$regions.' regions / tick',
            ],
            [
                'title' => __('One healthy region clears the streak'),
                'body' => __('A single success from anywhere resets the failure count to zero. Which means one region having a bad minute cannot page you on its own.'),
                'mechanism' => 'any non-down result → consecutive_fails = 0',
            ],
            [
                'title' => __('Not the first failure, the :count in a row', ['count' => Monitor::DEFAULT_INCIDENT_THRESHOLD]),
                'body' => __('An incident opens only once the failure streak crosses the threshold. The default is :count, so a transient flake is absorbed and a sustained outage still opens on the next tick. Adjustable per monitor.', ['count' => Monitor::DEFAULT_INCIDENT_THRESHOLD]),
                'mechanism' => 'incident_threshold = '.Monitor::DEFAULT_INCIDENT_THRESHOLD,
            ],
            [
                'title' => __('Our failure is not your outage'),
                'body' => __('When our own edge refuses a probe, it is recorded as a configuration problem and shown to you. It does not touch the status, it does not touch the streak, and it pages nobody.'),
                'mechanism' => 'refusal → last_status untouched',
            ],
        ];
    }

    /**
     * The stage's act names, handed to the Alpine sequence to label itself with.
     *
     * They belong here rather than in heroSequence.js because a string literal in a
     * JS module never reaches Laravel's translator: the server HTML would come out
     * translated and then the running animation would relabel itself in English a
     * second later.
     *
     * Keys are strings because the transition act is `1.5`, and PHP truncates a
     * float array key to an integer.
     *
     * @return array<string, string>
     */
    protected function stageLabels(): array
    {
        return [
            '1' => __('New monitor'),
            '1.5' => __('Dispatching'),
            '2' => __('Checks'),
            '3' => __('Triage'),
            '4' => __('Escalation'),
        ];
    }

    /**
     * Every language the product speaks, with this page's address in each.
     *
     * The list is `magic-starter.supported_locales`, the same array the API
     * negotiates Accept-Language against and the same pair the Flutter client
     * registers, so the three surfaces cannot drift apart.
     *
     * @return list<array{code: string, label: string, path: string, url: string, current: bool}>
     */
    protected function localeLinks(): array
    {
        $current = app()->getLocale();

        return array_map(
            fn (string $code): array => [
                'code' => $code,
                'label' => $this->languageName($code),
                'path' => $this->pathFor($code),
                'url' => $this->urlFor($code),
                'current' => $code === $current,
            ],
            array_values((array) config('magic-starter.supported_locales', [])),
        );
    }

    /**
     * A language's name in its own language.
     *
     * Read from ICU rather than a typed label map, so a locale added to config names
     * itself instead of showing a two-letter code. This matches the app's own
     * switcher (`English`, `Türkçe`): somebody hunting for Turkish scans for
     * "Türkçe", not for "Turkish".
     */
    protected function languageName(string $code): string
    {
        return Str::ucfirst(Locale::getDisplayLanguage($code, $code));
    }

    /**
     * The path a language is served on. The default language lives on the apex.
     */
    protected function pathFor(string $locale): string
    {
        return $locale === config('app.default_locale') ? '/' : '/'.$locale;
    }

    /**
     * The absolute URL for a language, for canonical and hreflang.
     *
     * From `route()`, which is configuration-derived, rather than from the request:
     * the URL a crawler indexes must be the canonical host even when the request
     * arrived on some other name.
     */
    protected function urlFor(string $locale): string
    {
        return $locale === config('app.default_locale')
            ? route('landing')
            : route('landing.localized', ['locale' => $locale]);
    }

    /**
     * The probe regions a monitor can actually be pinned to.
     *
     * Sourced from the enum the write requests validate against, NOT from
     * `config/relay.php`'s `regions` key, which the dispatch path never reads and
     * which understates the real list.
     *
     * @return list<array{value: string, label: string}>
     */
    protected function regions(): array
    {
        return array_map(
            fn (MonitorRegion $region): array => [
                'value' => $region->value,
                'label' => $region->label(),
            ],
            MonitorRegion::cases(),
        );
    }

    /**
     * The team-scoped alert destinations that exist.
     *
     * An exhaustive match with no default arm, so adding a channel type is a failure
     * here rather than a hero that quietly omits it. Notably absent because they are
     * absent from the enum: email and SMS as TEAM channels.
     *
     * @return list<string>
     */
    protected function channels(): array
    {
        return array_map(
            fn (NotificationChannelType $type): string => match ($type) {
                NotificationChannelType::Slack => 'Slack',
                NotificationChannelType::Webhook => 'Webhook',
                NotificationChannelType::PagerDuty => 'PagerDuty',
                NotificationChannelType::Teams => 'Microsoft Teams',
            },
            NotificationChannelType::cases(),
        );
    }

    /**
     * What we may honestly say about where the client runs.
     *
     * A plain list of the platforms the client is built for, read from
     * `app.client_platforms` so adding or dropping one moves the pill with it. The
     * names are proper nouns and are not translated.
     *
     * It used to carry a per-platform qualification ("On the web today, iOS and
     * Android next") because the mobile builds are in neither store: they come from
     * the same Flutter source, but nobody can install them yet. That wording was
     * rejected as clumsy and the flat list is a deliberate product decision, so the
     * honesty guard moved rather than disappeared. What the page must still never
     * claim is a listing that does not exist: no App Store or Google Play badge, no
     * download link. `HeroTest` pins that absence. Shipping the two store builds is
     * tracked separately.
     */
    protected function platformClaim(): string
    {
        return Arr::join(
            array_map(
                fn (string $key): string => $key === 'ios' ? 'iOS' : ucfirst($key),
                array_keys((array) config('app.client_platforms', [])),
            ),
            ', ',
        );
    }

    /**
     * The copy beside the stage, one beat per act: a headline and the sentence under it.
     *
     * The headline is split into `lead` plus `accent` because the accent is the part
     * that carries the brand green, and a single string would need markup inside a
     * translation.
     *
     * Nothing MOVES when a beat changes: the text of three elements is replaced. That
     * is information arriving rather than motion, which is why it still works under
     * reduced motion, and why both slots are height-clamped so a longer beat in another
     * language cannot push the buttons below them around mid-sequence.
     *
     * Act 1 is the beat the server renders, so its headline is the page's real claim
     * rather than a note about act 1: a crawler and a visitor with no JavaScript only
     * ever see this one, and the strongest signal on the page should not be the one
     * about setup.
     *
     * Every beat is checked against the code rather than written to sound good:
     *
     *   1  no agent, because probes run in the edge worker; nothing installs anywhere
     *   2  one signed spec fans out to every region in a single tick
     *      (ScheduleMonitorChecks dispatches one job per region per tick)
     *   3  the worker returns a bounded response-body preview and
     *      CheckPersistenceService runs MetricExtractor over it, so the body is read
     *      and a bound really can be set on a value inside it
     *   4  the ladder climbs on its own delays and stops on RESOLUTION, which is what
     *      EscalationDispatcher::pageStep() actually guards on
     *
     * Act 1.5 shares act 2's beat on purpose: the transition lasts 1.4s, nowhere near
     * long enough to read a sentence, so the beat arrives with the dispatch and stays
     * through the results.
     *
     * @return array<string, array{lead: string, accent: string, line: string}>
     */
    protected function heroBeats(): array
    {
        $checks = [
            'lead' => __('Every region,'),
            'accent' => __('at the same moment'),
            'line' => __('One signed spec fans out to all :count regions in a single tick. A slow region is then a fact about that region, not about when we got round to it.', [
                'count' => count(MonitorRegion::cases()),
            ]),
        ];

        return [
            '1' => [
                'lead' => __('Uptime monitoring that'),
                'accent' => __('refuses to guess'),
                'line' => __('Point it at a URL, pick the regions, and it starts checking. No agent, no sidecar, nothing of ours running in your infrastructure.'),
            ],
            '1.5' => $checks,
            '2' => $checks,
            '3' => [
                'lead' => __('A status code is not'),
                'accent' => __('the whole answer'),
                'line' => __('Pull a number out of the response body with a JSON path and give it a bound. Crossing it opens an incident with the evidence already attached.'),
            ],
            '4' => [
                'lead' => __('It keeps paging until somebody'),
                'accent' => __('resolves it'),
                'line' => __('A ladder of your own steps and delays, through :channels. Resolve it and every page still pending is cancelled.', [
                    'channels' => Arr::join($this->channels(), ', '),
                ]),
            ],
        ];
    }

    /**
     * The one-sentence description of the product.
     *
     * It used to be the hero's paragraph. The hero shows a short per-act line now, so
     * this sentence moved to `meta description`, which is where a summary belongs: it
     * keeps the substance in the markup for a crawler and for a link preview instead
     * of losing it to a six-word slogan.
     */
    protected function summary(): string
    {
        return __('Every region is checked at the same moment, an incident opens on repeated failure rather than the first blip, and the numbers you are shown are the ones that were measured.');
    }

    /**
     * The free tier's enforced limits, read from the plan catalog PlanGate uses.
     *
     * Returns null when the catalog has no free tier, which drops the sentence
     * rather than inventing one.
     *
     * @return array{monitors: int|null, interval: string, status_pages: int|null}|null
     */
    protected function freeTier(): ?array
    {
        $tier = Arr::first(
            config('plans.tiers', []),
            fn (array $tier): bool => ($tier['id'] ?? null) === 'free',
        );

        if ($tier === null) {
            return null;
        }

        $seconds = Arr::get($tier, 'limits.check_interval_sec');

        return [
            'monitors' => Arr::get($tier, 'limits.monitors'),
            // "3-minute" reads better than "180-second" on a whole number of
            // minutes, and the copy should not have to know which it is.
            'interval' => is_int($seconds) && $seconds >= 60 && $seconds % 60 === 0
                ? __(':count-minute', ['count' => intdiv($seconds, 60)])
                : __(':count-second', ['count' => $seconds]),
            'status_pages' => Arr::get($tier, 'limits.status_pages'),
        ];
    }

    /**
     * Whether this deployment can actually run an AI feature.
     *
     * Without a provider key every AI path in the product degrades to its
     * deterministic baseline. That degrade is a feature, but a page advertising
     * "AI-assisted triage" on top of it would be selling the fallback, so the claim
     * is withheld instead.
     */
    protected function aiEnabled(): bool
    {
        $provider = config('ai.default');

        if (! is_string($provider) || $provider === '') {
            return false;
        }

        $key = config("ai.providers.{$provider}.key");

        return is_string($key) && $key !== '';
    }

    /**
     * Absolute URL into the Flutter client.
     *
     * The client mounts its auth screens under a prefix, so these are NOT `/login`
     * and `/register` on the app host; getting it wrong sends every visitor who
     * clicks the primary call to action to a route the client does not serve.
     */
    protected function clientUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            .rtrim((string) config('app.frontend_auth_prefix'), '/')
            .$path;
    }
}
