<?php

namespace Database\Seeders;

use App\Enums\AiMode;
use App\Enums\HttpMethod;
use App\Enums\MonitorType;
use App\Enums\ServiceStatusSource;
use App\Http\Controllers\Marketing\ShowBotController;
use App\Http\Controllers\Marketing\ShowServiceStatusController;
use App\Models\Monitor;
use App\Models\Service;
use App\Models\Team;
use App\Services\Proxy\ProxyPool;
use App\Services\Services\ServicePageAssembler;
use App\Support\Services\SystemTeam;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Seeds the eight v1 catalog services, each with ONE system-team monitor
 * attached through the `service_monitor` pivot.
 *
 * Runs in EVERY environment, for the same reason {@see SystemTeamSeeder} does:
 * the catalog is reference data the public pages cannot exist without, and it
 * carries no credential to leak. It runs AFTER that seeder because every monitor
 * here belongs to the team it provisions.
 *
 * ## Every row starts unpublished, with its terms unreviewed
 *
 * `is_published` is false and `terms_reviewed_at` is null on purpose, and no
 * seeder may ever set either. Publishing is a HUMAN decision taken in the staff
 * panel after the terms register is filled in, which is the plan's Must Have that
 * a page cannot go live on an unreviewed provider, and
 * `app/Services/Services/FeedFetcher.php` refuses to fetch a service whose terms
 * are unreviewed, so a fresh install ingests nothing until somebody decides.
 *
 * `terms_note` carries what was VERIFIED on 2026-08-03 rather than a guess, so
 * the operator doing that review sees it in the panel instead of rediscovering
 * it. The load-bearing finding: `www.githubstatus.com`, `status.claude.com`,
 * `www.cloudflarestatus.com` and `www.vercel-status.com` all serve a robots.txt
 * containing `Disallow: /api/`, which is exactly the path their `summary.json`
 * lives on. Recording the candidate URL is not a decision to fetch it.
 *
 * ## The monitor is what makes a service publishable at all
 *
 * {@see Service::canPublish()} requires at least one attached monitor, because a
 * page whose only substance is a re-rendered provider feed must never publish. So
 * every service gets its own-measurement source the moment it exists. Each
 * monitor is created with `ai_mode` explicitly `off` (`app/Jobs/SweepAiSuggestions.php`
 * selects the `suggest`/`auto` fleet with NO team filter and then spends the
 * OWNING team's AI budget, which for these would be uptizm's own) and
 * `alert_on_down` false (a third party's outage is not uptizm's page to be woken
 * up for; the system team also has no members to page, see {@see SystemTeam}).
 *
 * Idempotent by slug: a second run creates nothing and, deliberately, EDITS
 * nothing, so an operator's own terms note or status-source choice survives a
 * re-seed. Pinned by `tests/Feature/Services/ServiceCatalogSeederTest.php`.
 */
class ServiceCatalogSeeder extends Seeder
{
    /**
     * The eight v1 services.
     *
     * `brand_color` is the header tile's accent, and it is NULL for OpenAI and Slack
     * on purpose: the CC0 `simple-icons` dataset every other colour here is cited
     * from carries neither brand, because that project removes a brand when its owner
     * asks. Typing their colour from memory would be an unsourced claim about somebody
     * else's mark on a page that refuses unsourced claims about their availability, so
     * those two keep the product's own brand pair and their monogram. Same rule as the
     * logos: six of the eight ship one, two do not, and the two that do not are
     * precisely the two whose owners objected.
     *
     * `status_source` is the CANDIDATE feed for the terms review, not a licence
     * to poll. Slack and Stripe are `None` on evidence rather than omission:
     * `status.slack.com` 301s cross-host to `slack-status.com` and publishes a
     * bespoke `api/v2.0.0/current` document, and `status.stripe.com` is a custom
     * application whose only machine-readable surface is an Atom feed. Neither is
     * a Statuspage v2 `summary.json`, this catalog has no adapter for either, and
     * pointing one at them would parse to an empty reading forever while looking
     * configured. `None` is the honest state: those two publish on uptizm's own
     * measurement alone.
     *
     * @var list<array{slug: string, brand_color: string|null, name: string, category: string, status_source: ServiceStatusSource, status_source_url: string|null, probe_url: string, probe_label: string, terms_note: string}>
     */
    private const array SERVICES = [
        [
            'slug' => 'github',
            'brand_color' => '#181717',
            'name' => 'GitHub',
            'category' => 'developer-tools',
            'status_source' => ServiceStatusSource::StatuspageV2,
            'status_source_url' => 'https://www.githubstatus.com/api/v2/summary.json',
            'probe_url' => 'https://github.com',
            'probe_label' => 'github.com',
            'terms_note' => 'Terms review pending. Verified 2026-08-03: www.githubstatus.com/robots.txt '
                .'contains "Disallow: /api/", which covers this feed path.',
        ],
        [
            'slug' => 'claude',
            'brand_color' => '#D97757',
            'name' => 'Claude',
            'category' => 'ai',
            'status_source' => ServiceStatusSource::StatuspageV2,
            'status_source_url' => 'https://status.claude.com/api/v2/summary.json',
            'probe_url' => 'https://claude.ai',
            'probe_label' => 'claude.ai',
            'terms_note' => 'Terms review pending. Verified 2026-08-03: status.claude.com/robots.txt '
                .'contains "Disallow: /api/". The older status.anthropic.com host now redirects here, '
                .'so the URL above is the post-redirect one on purpose.',
        ],
        [
            'slug' => 'openai',
            'brand_color' => null,
            'name' => 'OpenAI',
            'category' => 'ai',
            'status_source' => ServiceStatusSource::StatuspageV2,
            'status_source_url' => 'https://status.openai.com/api/v2/summary.json',
            'probe_url' => 'https://openai.com',
            'probe_label' => 'openai.com',
            'terms_note' => 'Terms review pending. Verified 2026-08-03: status.openai.com serves no '
                .'robots.txt (404) and answers this feed path with application/json.',
        ],
        [
            'slug' => 'cloudflare',
            'brand_color' => '#F38020',
            'name' => 'Cloudflare',
            'category' => 'cloud',
            'status_source' => ServiceStatusSource::StatuspageV2,
            'status_source_url' => 'https://www.cloudflarestatus.com/api/v2/summary.json',
            'probe_url' => 'https://www.cloudflare.com',
            'probe_label' => 'cloudflare.com',
            'terms_note' => 'Terms review pending. Verified 2026-08-03: www.cloudflarestatus.com/robots.txt '
                .'contains "Disallow: /api/". This host is also the reference payload the Statuspage '
                .'adapter was written against.',
        ],
        [
            'slug' => 'google-cloud',
            'brand_color' => '#4285F4',
            'name' => 'Google Cloud',
            'category' => 'cloud',
            'status_source' => ServiceStatusSource::GoogleCloud,
            'status_source_url' => 'https://status.cloud.google.com/incidents.json',
            'probe_url' => 'https://cloud.google.com',
            'probe_label' => 'cloud.google.com',
            'terms_note' => 'Terms review pending. Verified 2026-08-03: status.cloud.google.com/robots.txt '
                .'is permissive ("User-agent: *" with an empty Disallow).',
        ],
        [
            'slug' => 'slack',
            'brand_color' => null,
            'name' => 'Slack',
            'category' => 'communication',
            'status_source' => ServiceStatusSource::None,
            'status_source_url' => null,
            'probe_url' => 'https://slack.com',
            'probe_label' => 'slack.com',
            'terms_note' => 'No feed. Verified 2026-08-03: status.slack.com 301s cross-host to '
                .'slack-status.com and publishes a bespoke api/v2.0.0/current document, not a '
                .'Statuspage v2 summary.json. Publishes on uptizm measurement alone.',
        ],
        [
            'slug' => 'stripe',
            'brand_color' => '#635BFF',
            'name' => 'Stripe',
            'category' => 'payments',
            'status_source' => ServiceStatusSource::None,
            'status_source_url' => null,
            'probe_url' => 'https://stripe.com',
            'probe_label' => 'stripe.com',
            'terms_note' => 'No feed. Verified 2026-08-03: status.stripe.com is a custom application '
                .'whose only machine-readable surface is an Atom feed, and this catalog ships no '
                .'feed parser. Publishes on uptizm measurement alone.',
        ],
        [
            'slug' => 'vercel',
            'brand_color' => '#000000',
            'name' => 'Vercel',
            'category' => 'developer-tools',
            'status_source' => ServiceStatusSource::StatuspageV2,
            'status_source_url' => 'https://www.vercel-status.com/api/v2/summary.json',
            'probe_url' => 'https://vercel.com',
            'probe_label' => 'vercel.com',
            'terms_note' => 'Terms review pending. Verified 2026-08-03: www.vercel-status.com/robots.txt '
                .'contains "Disallow: /api/", which covers this feed path.',
        ],
    ];

    /**
     * Create any missing catalog service together with its own-measurement
     * monitor.
     */
    public function run(): void
    {
        // 1. The regions a fresh catalog monitor is allowed to claim, resolved
        //    and validated BEFORE any row is written: a deployment with too few
        //    configured proxy regions must never seed one that only claims a
        //    fraction of them by accident.
        $regions = $this->catalogRegions();

        // 2. The team every catalog monitor belongs to, provisioned if absent.
        $team = SystemTeam::resolve();

        foreach (self::SERVICES as $definition) {
            // 3. Idempotent by slug, and create-only for everything an OPERATOR
            //    owns: a re-seed must not overwrite a terms review or a source
            //    choice. The one exception is the outbound identity below, which
            //    is ours and not theirs.
            $existing = Service::query()->where('slug', $definition['slug'])->first();

            if ($existing !== null) {
                $this->identifyProbes($team, $existing);

                continue;
            }

            $service = Service::query()->create([
                'slug' => $definition['slug'],
                'name' => $definition['name'],
                'category' => $definition['category'],
                'status_source' => $definition['status_source'],
                'status_source_url' => $definition['status_source_url'],
                'terms_note' => $definition['terms_note'],
                'brand_color' => $definition['brand_color'],
                // Both false/null by schema default; stated because they are the
                // publication gate rather than an oversight.
                'is_published' => false,
                'terms_reviewed_at' => null,
                'display_order' => 0,
            ]);

            // 4. Attach the own-measurement, with the probed host as the public
            //    label so the page can name WHAT was measured rather than
            //    implying coverage of the whole product.
            $service->monitors()->attach(
                $this->createMonitor($team, $regions, $definition)->getKey(),
                [
                    'label' => $definition['probe_label'],
                ],
            );
        }
    }

    /**
     * The regions a catalog monitor is seeded with: exactly the region keys
     * under `config('proxy.sources')` that carry a non-empty `location`, not
     * every {@see MonitorRegion} case and not merely every DECLARED key.
     *
     * Reading the full enum (as this used to) let a catalog monitor claim a
     * region with no configured exit at all.
     * {@see ShowServiceStatusController::replacements()}
     * derives the published `[[service.region_count]]` from
     * `max($monitor->regions)` per endpoint, so a monitor seeded with all
     * five regions published a probe-region count the deployment could never
     * back, and `ScheduleMonitorChecks` fanned a check out to a region
     * {@see ProxyPool::hasRegion()} was always going to
     * refuse.
     *
     * Filtering on `location` rather than trusting key membership alone
     * matters because `config/proxy.php` DECLARES all three regions
     * statically; only the env-driven `location` value says whether a given
     * deployment actually sourced one. Its own docblock calls an empty
     * location "DECLARED BUT UNUSABLE", and `ProxyPool::hasRegion()` treats
     * key membership as necessary but not sufficient for exactly that reason.
     * A seeder trusting the key alone would keep seeding every region even
     * after an operator blanked one out to disable it.
     *
     * `config('proxy.sources')` and NOT a database query: {@see ShowBotController}
     * reads the exact same expression, filtered the exact same way, for its
     * own region count and daily-request figure, and it cannot query the
     * database to cross-check it (those pages are served with no connection
     * available). Config is the one signal both places can read identically;
     * a seeder-only filter would let the two publish two different counts
     * again, which is the defect this step exists to close.
     *
     * Throws below {@see ServicePageAssembler::MIN_AGREEING_REGIONS} (2): that
     * is the mathematical floor the consensus check itself enforces, below
     * which an outage verdict can never be reached regardless of what the
     * regions measure. Deliberately looser than `config('proxy.minimum_regions')`
     * (3, the operational floor that survives one dead region, see
     * `config/proxy.php`): a seeder that only refused below 3 would still
     * ship a catalog whose outage claim is structurally unreachable at
     * exactly 2.
     *
     * @return list<string>
     */
    /**
     * Whether a catalog can be seeded at all, asked BEFORE calling this seeder.
     *
     * {@see DatabaseSeeder} needs this because the region precondition below throws, and
     * `migrate:fresh --seed` is the documented way to reset a dev database: a developer
     * not working on the catalog must still get a database. Wrapping the call in a
     * `catch` would have answered that too, and worse, because it would have degraded
     * EVERY RuntimeException from anywhere inside this seeder into a console warning in
     * every environment. Asking first swallows nothing.
     */
    public static function canSeed(): bool
    {
        return count(array_filter(
            (array) config('proxy.sources', []),
            static fn (array $source): bool => filled($source['location'] ?? null),
        )) >= ServicePageAssembler::MIN_AGREEING_REGIONS;
    }

    private function catalogRegions(): array
    {
        $regions = array_keys(array_filter(
            (array) config('proxy.sources', []),
            static fn (array $source): bool => filled($source['location'] ?? null),
        ));

        if (count($regions) < ServicePageAssembler::MIN_AGREEING_REGIONS) {
            throw new RuntimeException(
                'ServiceCatalogSeeder requires at least '.ServicePageAssembler::MIN_AGREEING_REGIONS
                .' proxy regions with a configured source in config(\'proxy.sources\'); only '.count($regions)
                .' ('.implode(', ', $regions).') carry one. A catalog monitor seeded with fewer can never '
                .'reach outage consensus.',
            );
        }

        return $regions;
    }

    /**
     * Create the system-team monitor probing one service's public endpoint.
     *
     * `next_check_at` is set to now because `Monitor::scopeDue()` compares
     * `next_check_at <= now()` and a null never matches it, so a monitor seeded
     * without it would sit outside the scheduler forever and the page would have
     * no own-measurement to show.
     *
     * @param  list<string>  $regions
     * @param  array{name: string, probe_url: string, probe_label: string}  $definition
     */
    private function createMonitor(Team $team, array $regions, array $definition): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->getKey(),
            'name' => $definition['name'].' ('.$definition['probe_label'].')',
            'type' => MonitorType::Http,
            'method' => HttpMethod::Get,
            'url' => $definition['probe_url'],
            'check_interval_sec' => (int) config('uptizm.catalog_probe_interval_sec'),
            // Exactly the regions {@see self::catalogRegions()} validated: a
            // public claim about a global service is only honest if every region
            // it names actually has a configured exit to measure from.
            'regions' => $regions,
            /*
             * IDENTIFY THIS TRAFFIC. This is the larger of the two channels this
             * catalog opens on a provider, by a wide margin: every region, every
             * catalog probe interval, against the provider's own homepage, versus one
             * feed request every two minutes with a 60-second floor.
             *
             * It went out anonymous. The edge worker sends
             * `probe.request_headers ?? {}` (`regional-probe.ts:327`), so with no
             * headers on the row the probe carried no User-Agent and no way for the
             * operator on the other end to find out who we were or ask us to stop.
             * The whole basis on which this catalog is defensible is being
             * identifiable and contactable, and `/bot` cannot serve that purpose for
             * traffic that never names it.
             *
             * Same string the feed ingester sends, so one page answers for both
             * channels and an operator correlating their logs sees one product.
             */
            'request_headers' => [
                'User-Agent' => (string) config('uptizm.bot_user_agent'),
            ],
            'ai_mode' => AiMode::Off,
            'alert_on_down' => false,
            'next_check_at' => now(),
        ]);
    }

    /**
     * Make sure every monitor already attached to this service identifies itself.
     *
     * A BACKFILL, and it exists because the first version of this fix only set
     * `request_headers` on the CREATE path. Every environment seeded before that
     * change, including the dev database used for this plan's live QA, kept a null
     * value, and null is not a harmless default here: `RelayClient` forwards
     * `$monitor->request_headers` and the edge worker sends
     * `probe.request_headers ?? {}` (`regional-probe.ts:327`), with no User-Agent
     * injected anywhere else on that path. So the larger of the two channels this
     * catalog opens on a provider stayed anonymous while `/bot`, the page addressed
     * to exactly those providers' operators, had begun stating that both channels
     * identify themselves. A page that claims a courtesy the traffic does not
     * extend is worse than no page.
     *
     * Converges rather than overwrites: an operator who added their own header
     * keeps it, and only the `User-Agent` key is asserted. Runs on every re-seed,
     * which is what makes it a backfill rather than a migration.
     *
     * `tests/Feature/Services/ServiceCatalogSeederTest.php` covers the
     * already-exists branch, which nothing covered before this, and covers the
     * refusal to touch a customer's monitor.
     */
    private function identifyProbes(Team $team, Service $service): void
    {
        $agent = (string) config('uptizm.bot_user_agent');

        foreach ($service->monitors as $monitor) {
            /*
             * SYSTEM-TEAM MONITORS ONLY. `ServiceForm`'s monitor select is
             * deliberately cross-team (Step 9's staff resource has no team scope), so
             * an operator can attach a CUSTOMER's monitor to a catalog service. This
             * seeder runs in every environment on every `db:seed`, and rewriting a
             * paying customer's outbound probe headers because their monitor happens
             * to be attached here would be a seeder writing rows it does not own.
             */
            if ($monitor->team_id !== $team->getKey()) {
                continue;
            }

            $headers = (array) ($monitor->request_headers ?? []);

            if (($headers['User-Agent'] ?? null) === $agent) {
                continue;
            }

            $headers['User-Agent'] = $agent;

            $monitor->forceFill(['request_headers' => $headers])->save();
        }
    }
}
