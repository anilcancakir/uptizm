<?php

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
*/

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
                '1 monitor · 3-minute checks',
                '1 status page · 100 subscribers',
                '1 responder · email & Slack alerts',
                '3-day history',
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
            'ai_line' => 'Full AI incident analysis — evidence, confidence, citations, drafted updates.',
            'features' => [
                '50 monitors · 30-second checks · all regions',
                '3 status pages · 1,000 subscribers · custom domain',
                '3 responders · on-call, escalation & SMS/voice',
                'SLO error budgets · 30-day history',
            ],
            'responder_add_on' => '+$9/mo per extra responder',
            'recommended' => true,
            'limits' => [
                'monitors' => 50,
                'check_interval_sec' => 30,
                'status_pages' => 3,
                'subscribers' => 1000,
                'responders' => 3,
                'ai' => 'analysis',
                'white_label' => false,
                'private_pages' => false,
                'sso' => false,
            ],
        ],

        [
            'id' => 'business',
            'name' => 'Business',
            'tagline' => 'Scaling teams with real SLAs.',
            'monthly' => 119,
            'annual' => 99,
            'currency' => 'usd',
            'ai_line' => 'AI Auto mode, weekly digest & similar-incident matching.',
            'features' => [
                '200 monitors · 10-second checks',
                '10 status pages · 10,000 subscribers · white-label & private pages',
                '10 responders · SSO · audit log',
                '1-year history',
            ],
            'responder_add_on' => '+$9/mo per extra responder',
            'recommended' => false,
            'limits' => [
                'monitors' => 200,
                'check_interval_sec' => 10,
                'status_pages' => 10,
                'subscribers' => 10000,
                'responders' => 10,
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
                'Unlimited monitors · 5-second checks · dedicated relays',
                'Unlimited status pages & subscribers · audience-specific pages',
                'Unlimited responders · SAML & SCIM · custom roles',
                'Custom retention · SLA · invoicing',
            ],
            'responder_add_on' => null,
            'recommended' => false,
            'limits' => [
                'monitors' => null,
                'check_interval_sec' => 5,
                'status_pages' => null,
                'subscribers' => null,
                'responders' => null,
                'ai' => 'custom',
                'white_label' => true,
                'private_pages' => true,
                'sso' => true,
            ],
        ],

    ],

];
