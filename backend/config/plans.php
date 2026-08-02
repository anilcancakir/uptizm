<?php

use App\Enums\MonitorRegion;

/*
|--------------------------------------------------------------------------
| Plan Catalog
|--------------------------------------------------------------------------
|
| The single source of truth for the billing screen: every tier's display
| copy plus the hard in-product limits it enforces. This is display + gating
| data only; it carries NO Stripe price ids (those live in config/cashier.php
| for checkout mapping). The array is already in wire shape, so the
| /billing/plans endpoint serves it verbatim under a `data` envelope.
|
| Order is load-bearing: `tiers` runs cheapest to most expensive so the client
| can walk it front-to-back for the upgrade/downgrade CTA. A `null` price means
| "contact us" (Enterprise); a `null` limit means "unlimited". The `ai` field
| is one of inbox|analysis|auto|custom, ascending AI capability.
|
| RULE FOR THE `features` AND `ai_line` COPY: a bullet may only name something
| that works today. This array is served verbatim to the billing screen, so a
| bullet here is a promise made to someone holding a credit card.
|
| Removed in the 2026-07-31 audit, each because nothing implements it. Restore
| the bullet in the same change that ships the feature, not before:
|
|   - custom status-page domains (DomainMode::Custom is accepted on the wire,
|     but no route answers on a customer hostname)
|   - team-level SMS and voice alerting (NotificationChannelType has only
|     slack/webhook/pagerduty/teams; per-user SMS rides OneSignal and is opt-in)
|   - white-label / removing Uptizm branding (PlanGate::allowsWhiteLabel() has
|     no consumer and there is no branding column or toggle)
|   - SSO, SAML, SCIM, audit log, custom roles (no implementation anywhere)
|   - similar-incident matching (designed in ai-design.md, not built)
|   - dedicated relays and audience-specific status pages (not built)
|   - per-tier history windows (retention is a single global 90-day Timescale
|     policy on both hypertables; it is not plan-gated, so "3-day" understated
|     Free and "1-year" overstated Business)
|
*/

// The Free tier's region allowance, read once here so the marketing bullet below and the
// `limits.regions` gate PlanGate/Step 5 enforce can never say two different numbers.
$freeRegionAllowance = 1;

return [

    'tiers' => [

        [
            'id' => 'free',
            'name' => 'Free',
            'tagline' => 'Kick the tires, solo projects.',
            'monthly' => 0,
            'annual' => 0,
            'currency' => 'usd',
            'ai_line' => 'AI anomaly inbox, plus 3 free AI monitor setups.',
            'features' => [
                sprintf('1 monitor · 3-minute checks · %d region', $freeRegionAllowance),
                '1 status page · 100 subscribers',
                '1 responder · Slack, Teams, PagerDuty & webhook alerts',
                'TLS expiry alerts · response-metric bounds',
                '3 AI monitor setups, then Pro',
            ],
            'responder_add_on' => null,
            'recommended' => false,
            'limits' => [
                'monitors' => 1,
                'check_interval_sec' => 180,
                'status_pages' => 1,
                'subscribers' => 100,
                'responders' => 1,
                'regions' => $freeRegionAllowance,
                'ai' => 'inbox',
                // AI monitor analysis is open on Free, but metered: this many
                // successful setups, then the plan wall. Paid tiers entitle it
                // through `ai` and are never metered (see PlanGate).
                'ai_analysis_trials' => 3,
                'white_label' => false,
                'private_pages' => false,
                'sso' => false,
            ],
        ],

        [
            'id' => 'pro',
            'name' => 'Pro',
            'tagline' => 'Startups and small teams that page.',
            'monthly' => 34,
            'annual' => 29,
            'currency' => 'usd',
            'ai_line' => 'Full AI incident analysis: evidence, confidence, citations, drafted updates.',
            'features' => [
                '50 monitors · 30-second checks',
                '3 status pages · 1,000 subscribers',
                '3 responders · on-call schedules & escalation policies',
                'SLO targets & error budgets',
            ],
            'responder_add_on' => '+$9/mo per extra responder',
            'recommended' => true,
            'limits' => [
                'monitors' => 50,
                'check_interval_sec' => 30,
                'status_pages' => 3,
                'subscribers' => 1000,
                'responders' => 3,
                'regions' => count(MonitorRegion::cases()),
                'ai' => 'analysis',
                'white_label' => false,
                'private_pages' => false,
                'sso' => false,
            ],
        ],

        [
            'id' => 'business',
            'name' => 'Business',
            // No SLA in this line, and none anywhere else. Nothing measures
            // availability against a promise, nothing credits a breach, and the Terms,
            // the FAQ and the landing page all say plainly that no percentage is
            // promised. A tagline on a card-purchasable tier is the last place to
            // contradict them.
            'tagline' => 'Scaling teams, on the fastest checks we run.',
            'monthly' => 119,
            'annual' => 99,
            'currency' => 'usd',
            'ai_line' => 'AI Auto mode plus the weekly written digest.',
            'features' => [
                '200 monitors · 10-second checks',
                '10 status pages · 10,000 subscribers · private pages',
                '10 responders',
                'AI Auto mode & weekly digest',
            ],
            'responder_add_on' => '+$9/mo per extra responder',
            'recommended' => false,
            'limits' => [
                'monitors' => 200,
                'check_interval_sec' => 10,
                'status_pages' => 10,
                'subscribers' => 10000,
                'responders' => 10,
                'regions' => count(MonitorRegion::cases()),
                'ai' => 'auto',
                'white_label' => true,
                'private_pages' => true,
                'sso' => true,
            ],
        ],

        [
            'id' => 'enterprise',
            'name' => 'Enterprise',
            'tagline' => 'Custom scale, security and support.',
            'monthly' => null,
            'annual' => null,
            'currency' => 'usd',
            'ai_line' => 'AI with custom guardrails & dedicated capacity.',
            'features' => [
                'Unlimited monitors · 5-second checks',
                'Unlimited status pages & subscribers',
                'Unlimited responders',
                // Invoicing stays because it is real. The SLA that stood beside it does
                // not: it was the only place in the product still promising an
                // availability figure, and every page states the opposite.
                'Invoicing and custom terms',
            ],
            'responder_add_on' => null,
            'recommended' => false,
            'limits' => [
                'monitors' => null,
                'check_interval_sec' => 5,
                'status_pages' => null,
                'subscribers' => null,
                'responders' => null,
                'regions' => count(MonitorRegion::cases()),
                'ai' => 'custom',
                'white_label' => true,
                'private_pages' => true,
                'sso' => true,
            ],
        ],

    ],

];
