<?php

namespace Database\Seeders;

use App\Enums\AiMode;
use App\Enums\HttpMethod;
use App\Enums\MonitorRegion;
use App\Enums\MonitorType;
use App\Enums\ServiceStatusSource;
use App\Models\Monitor;
use App\Models\Service;
use App\Models\Team;
use App\Support\Services\SystemTeam;
use Illuminate\Database\Seeder;

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
     * Check cadence for a catalog monitor, in seconds.
     *
     * One minute: these monitors back a public page that says when uptizm last
     * measured the endpoint, and a coarser cadence would date the claim. The
     * system team is exempt from the plan interval floor
     * (`PlanGate::limits()`), so this is a product decision rather than a
     * plan-tier one.
     */
    private const int CHECK_INTERVAL_SECONDS = 60;

    /**
     * The eight v1 services.
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
     * @var list<array{slug: string, name: string, category: string, status_source: ServiceStatusSource, status_source_url: string|null, probe_url: string, probe_label: string, terms_note: string}>
     */
    private const array SERVICES = [
        [
            'slug' => 'github',
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
        // 1. The team every catalog monitor belongs to, provisioned if absent.
        $team = SystemTeam::resolve();

        foreach (self::SERVICES as $definition) {
            // 2. Idempotent by slug, and create-only: a re-seed must not
            //    overwrite an operator's terms review or source choice.
            if (Service::query()->where('slug', $definition['slug'])->exists()) {
                continue;
            }

            $service = Service::query()->create([
                'slug' => $definition['slug'],
                'name' => $definition['name'],
                'category' => $definition['category'],
                'status_source' => $definition['status_source'],
                'status_source_url' => $definition['status_source_url'],
                'terms_note' => $definition['terms_note'],
                // Both false/null by schema default; stated because they are the
                // publication gate rather than an oversight.
                'is_published' => false,
                'terms_reviewed_at' => null,
                'display_order' => 0,
            ]);

            // 3. Attach the own-measurement, with the probed host as the public
            //    label so the page can name WHAT was measured rather than
            //    implying coverage of the whole product.
            $service->monitors()->attach(
                $this->createMonitor($team, $definition)->getKey(),
                [
                    'label' => $definition['probe_label'],
                ],
            );
        }
    }

    /**
     * Create the system-team monitor probing one service's public endpoint.
     *
     * `next_check_at` is set to now because `Monitor::scopeDue()` compares
     * `next_check_at <= now()` and a null never matches it, so a monitor seeded
     * without it would sit outside the scheduler forever and the page would have
     * no own-measurement to show.
     *
     * @param  array{name: string, probe_url: string, probe_label: string}  $definition
     */
    private function createMonitor(Team $team, array $definition): Monitor
    {
        return Monitor::query()->create([
            'team_id' => $team->getKey(),
            'name' => $definition['name'].' ('.$definition['probe_label'].')',
            'type' => MonitorType::Http,
            'method' => HttpMethod::Get,
            'url' => $definition['probe_url'],
            'check_interval_sec' => self::CHECK_INTERVAL_SECONDS,
            // Every region the relay supports: a public claim about a global
            // service is only honest if it was measured from more than one place.
            'regions' => MonitorRegion::values(),
            'ai_mode' => AiMode::Off,
            'alert_on_down' => false,
            'next_check_at' => now(),
        ]);
    }
}
