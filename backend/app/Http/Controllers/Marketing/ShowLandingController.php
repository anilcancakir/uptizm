<?php

namespace App\Http\Controllers\Marketing;

use App\Enums\AiMode;
use App\Enums\MetricSource;
use App\Enums\MonitorRegion;
use App\Enums\NotificationChannelType;
use App\Models\Monitor;
use App\Support\Marketing\ChromeData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;

/**
 * The landing page on the apex host.
 *
 * One rule governs this controller: A CLAIM IS DERIVED, NEVER TYPED. Anything the
 * page asserts about the product comes from the enum or config that actually
 * governs the behaviour, so the page cannot drift into advertising a region we do
 * not probe from or a limit we do not honour.
 *
 * It grows a method per section as the page is rebuilt.
 *
 * The shell's own data (canonical, hreflang, the language links, the calls to
 * action, the footer's region count and platform line) is NOT here any more: it is
 * `ChromeData`, shared with every other marketing page. This controller supplies
 * only what the six sections say, plus the three per-page facts the shell cannot
 * derive on its own: the page's path, its summary sentence, and its anchor list.
 */
class ShowLandingController
{
    public function __invoke(): View
    {
        return view('landing', [
            // The site root, so ChromeData composes `/` and `/tr` for it.
            ...(new ChromeData(
                path: '',
                summary: $this->summary(),
                sections: $this->sections(),
            ))->toArray(),
            'freeTier' => $this->freeTier(),
            'aiEnabled' => $this->aiEnabled(),
            'channels' => $this->channels(),
            'stageLabels' => $this->stageLabels(),
            'heroBeats' => $this->heroBeats(),
            'decisionRules' => $this->decisionRules(),
            'inspections' => $this->inspections(),
            'statusPageFeatures' => $this->statusPageFeatures(),
            'aiBoundary' => $this->aiBoundary(),
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
        $sections = [
            ['id' => 'how-it-decides', 'label' => __('How it decides')],
            ['id' => 'beyond-status-codes', 'label' => __('What it inspects')],
            ['id' => 'status-pages', 'label' => __('Status pages')],
        ];

        // The AI section is withheld entirely on a deployment with no provider key, so
        // its nav entry has to be withheld with it. Otherwise the nav points at a
        // section that is not on the page, which is the exact defect the anchor guard
        // in ChromeTest exists to catch.
        if ($this->aiEnabled()) {
            $sections[] = ['id' => 'ai-boundary', 'label' => __('The AI boundary')];
        }

        return $sections;
    }

    /**
     * What a single check actually looks at, beyond whether the server answered.
     *
     * The section this feeds is the depth argument. Uptizm covers fewer protocols than
     * the tools that list eighteen check types, so breadth is not the story; what one
     * check inspects is.
     *
     * @return list<array{title: string, body: string, mechanism: string}>
     */
    protected function inspections(): array
    {
        return [
            [
                'title' => __('A number out of the response body'),
                'body' => __('Point a selector at a value your endpoint already returns, give it a bound, and it becomes a first-class signal: charted, thresholded, and able to open an incident on its own. :count kinds of selector, and you can test one against a live response before you save it.', [
                    'count' => count(MetricSource::cases()),
                ]),
                // The enum's own backing values. A source added there appears here.
                'mechanism' => Arr::join(array_column(MetricSource::cases(), 'value'), ' · '),
            ],
            [
                'title' => __('The certificate, before it bites'),
                'body' => __('Every tracked monitor has its TLS certificate read on a schedule. Inside the warning window an incident opens once, not once per run, and the expiry date is on the monitor where you can see it coming.'),
                'mechanism' => 'ssl_alert_threshold_days = '.Monitor::make()->ssl_alert_threshold_days,
            ],
            [
                'title' => __('An error budget, from real checks'),
                'body' => __('Set a target per monitor and the remaining budget is computed from the checks that actually ran. When there is not enough history to answer, it says so instead of showing you a confident zero.'),
                'mechanism' => 'slo_target, per monitor',
            ],
        ];
    }

    /**
     * What a published status page carries.
     *
     * Typed rather than derived from `DomainMode`, and that is deliberate: the enum has
     * three cases and the third, a customer's own domain, is accepted by the API but has
     * no route answering on it. Deriving the list would advertise it.
     *
     * @return list<string>
     */
    protected function statusPageFeatures(): array
    {
        return [
            __('Components, and groups of components'),
            __('90 days of uptime per component'),
            __('Incident history with published postmortems'),
            __('Email subscribers, double opt-in'),
            __('Your brand colour and logo'),
            __('On a path or on its own subdomain'),
        ];
    }

    /**
     * The AI modes, and the line the AI does not cross.
     *
     * The modes come from the enum the write requests validate against. The boundary is
     * the more important half: uptizm has no integration into the customer's product, so
     * its AI can only reason from what uptizm itself measured. Saying that plainly is
     * the differentiator, because the failure mode of every AI feature in this category
     * is a confident sentence about a deploy it cannot see.
     *
     * @return array{modes: list<array{name: string, body: string}>, cannot: list<string>}
     */
    protected function aiBoundary(): array
    {
        return [
            'modes' => array_map(
                fn (AiMode $mode): array => match ($mode) {
                    AiMode::Off => [
                        'name' => __('Off'),
                        'body' => __('Thresholds and people open incidents. The AI is not in the loop at all.'),
                    ],
                    AiMode::Suggest => [
                        'name' => __('Suggest'),
                        'body' => __('It writes an analysis on incidents and raises what it thinks is an anomaly. You decide.'),
                    ],
                    AiMode::Auto => [
                        'name' => __('Auto'),
                        'body' => __('It opens anomaly incidents itself, keeps them updated, and resolves them when the signal clears.'),
                    ],
                },
                AiMode::cases(),
            ),
            'cannot' => [
                __('your deploys, your commits, your CI'),
                __('your logs, your traces, your APM'),
                __('your CDN, or anybody else\'s status page'),
            ],
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
        // Straight off the enum the write requests validate against, the same count the
        // footer and the hero derive. The region LIST is the chrome's business now, but
        // the number in this sentence still has to come from the same place.
        $regions = count(MonitorRegion::cases());

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
}
