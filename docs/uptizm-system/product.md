# Product

What Uptizm does, feature by feature. The feature surface below is the settled v2
vision as realized in the React design-lab and ported to the current Flutter mock.
Screen names reference the current repo's views under `lib/resources/views/`.

## Feature domains

### 1. Monitors (the source of truth)

A monitor is a check target plus its metric queries. Everything else hangs off it.

- **Types:** HTTP (GET/POST/HEAD/PUT), TCP; roadmap for ping / keyword / SSL.
- **Check config:** interval (plan-gated floor: 3 min free, down to 5 s enterprise),
  timeout, expected status code, request headers/body, auth (none/basic/bearer/api-key).
- **Regions:** multi-region check distribution (us-east, us-west, eu-west, eu-central, ap).
- **Assertions:** status code, body-contains, response-time, JSONPath conditions.
- **Custom metrics:** extract numeric/status/string values from the response
  (JSONPath/XPath/Regex/header) with warn/critical bounds. This is Uptizm's signature
  feature: the one honest window into the customer's own system (see AI boundary below).
- **State:** up / down / degraded / paused, plus 24h/7d/30d uptime %, response
  distribution, `consecutive_fails`, `next_check_at`.
- **SLO:** per-monitor target with 7-day and 30-day error budgets.
- **Grouping:** parent/child monitors (`is_group`, `parent_id`).
- **Status-page visibility:** `show_on_status_page`, `only_show_if_degraded`.
- **Per-monitor AI mode** override (defaults to the team AI mode).
- Screens: `monitors_list_view`, `monitor_create_view`, `monitor_edit_view`,
  `monitor_detail_view` (Overview / Metrics / Checks / Incidents tabs), plus the shared
  `monitor_form`, `monitor_metrics_tab`, `monitor_metric_detail`.

### 2. Incidents (unified signal handling)

There is no separate "alert" concept anymore (v1 had `AlertRule`; v2 dropped it).
Every actionable signal becomes an incident.

- **Signal source:** `userThreshold` (metric bound or consecutive-fail), `aiAnomaly`,
  `aiAnalyzer`, or `manual`.
- **Lifecycle (PagerDuty-shaped):** detected -> investigating -> identified ->
  monitoring -> resolved (plus acknowledged). UI tabs: Triggered / Acknowledged /
  Resolved / All.
- **Severity:** critical / warn / info. **Impact:** none / minor / major / critical.
- **Kind:** realtime vs maintenance.
- **Timeline:** actor-aware events (human / ai / system) + operator-authored public
  updates (markdown) that notify status-page subscribers.
- **AI ownership:** `ai_owned` flag; AI-owned incidents can auto-resolve on a healthy
  streak. AI writes `aiAnalysis` (root cause + impact forecast + confidence).
- **Postmortem:** body + published-at.
- **Similar incidents:** historical match (pgvector embeddings, Business+).
- Screens: `incidents_list_view`, `incident_create_view`, `incident_detail_view`,
  `weekly_digest_view`.

### 3. Status pages (public communication)

Panel CRUD (list + editor) plus a separate standalone public render at `/s/:slug`
(outside the app layout). See [architecture](architecture.md) for the public-render
concern.

- **Branding:** name, slug, brand color, logo, description.
- **Domain modes:** subdomain (`$slug.uptizm.com`) or path (`uptizm.com/status/$slug`);
  roadmap for custom domains (Cloudflare for SaaS / on-demand TLS).
- **Components:** assigned monitors become public components carrying their incidents.
- **Metrics:** publish the monitor's custom metrics (value + unit + band dot).
- **Subscribers:** email list with confirm/unsubscribe, subscriber export (CSV).
- **AI-assisted draft:** group the team's own monitors into components + draft copy.
- Screens: `status_pages_list_view`, `status_page_editor_view`,
  `status_page_preview_view`, `status_page_subscribers_view`.

### 4. On-call and escalation

- **Escalation policies:** ordered rungs (`afterMinutes` + targets), repeat-last-rung,
  a default policy; monitors route to a policy.
- **On-call schedule:** rotation across team members, current + upcoming shifts,
  handoff cadence, override.
- Screens: `escalation_policies_view`, `escalation_policy_editor_view`,
  `on_call_schedule_view`.

### 5. Notifications (three layers)

- **Team channels** (`notification_channels_view`): email, SMS/voice, Slack, Microsoft
  Teams, webhook. Per-channel enable + minimum-severity filter.
- **Per-monitor toggles** (in the monitor form): which monitors alert (down / recovers).
- **User-level** (`notifications_settings_view`): in-app bell, web push (this browser),
  email digest.
- **In-app notification center:** bell + unread badge + recent-alerts dropdown
  (`notification_center` component), kinds down/up/degraded/incident/resolved/ai.
- Rule of thumb: per-monitor toggles decide WHICH monitors alert; team channels decide
  WHERE; user settings decide personal/device delivery.
- Delivery backend: `magic_notifications` (database polling + OneSignal push + mail) on
  the client; server-side channels dispatched by the API.

### 6. AI layer (the differentiator)

- **AiMode:** `off` (silent; only threshold/manual incidents), `suggest` (AI writes
  analysis on every incident, raises suggestions, humans act), `auto` (AI opens anomaly
  incidents, drives them, auto-resolves when healthy). Team default + per-monitor override.
- **AI inbox:** dashboard widget of pending anomaly suggestions awaiting operator
  judgment; "Open incident" carries the anomaly into a pre-filled incident create.
- **AI analysis card:** trigger, confidence (high/medium/low), tl;dr, evidence FOR and
  AGAINST, suggested actions (advisory, never one-click), similar incidents.
- **Weekly digest:** detected / auto-resolved / dismissed counts + confidence + a
  dismissed-anomalies feedback loop.
- **AI activity audit log:** every agent run (agent, input, output, tokens, cost,
  duration) for transparency and cost tracking.
- Screens (current mock): AI surfaces embedded in dashboard + incident detail;
  components `ai_analysis_card`, `ai_confidence_badge`, `ai_inbox_item`, `ai_insight`,
  `assistant` (global floating "Ask Uptizm" FAB).

#### AI graduated trust + honest data boundary (load-bearing)

Uptizm has NO integration into the customer's product: no deploys, git, CI/CD, logs,
APM, CDN, third-party status. It is a synthetic external observer. AI reasons ONLY from
signals Uptizm itself collects: check results (status code, response time), regional
probe breakdown, the customer's own exposed custom metrics, failure cadence
(sustained vs flapping, 503 vs 504 vs timeout), cross-monitor correlation, and
baseline/anomaly deviation. It characterizes WHERE and WHEN ("all regions 503 + low
latency -> origin-side fault, not network"), cites Uptizm-owned data only, and its
suggested steps are ADVISORY ("check your origin capacity"), never a one-click "Apply".
Never claim "errors started 2m after deploy a1b2c3" or suggest "roll back the deploy":
Uptizm cannot see or do those. This honesty is the trust moat.

### 7. Teams (tenant boundary)

- Team owns all domain data; members have roles Owner / Admin / Member; email invites
  with role + token accept; team avatar color + optional logo.
- Team things are separate pages reached from the team switcher (not settings tabs).
- Screens: `team_create_view`, `team_settings_view`, `team_members_view`,
  `invite_accept_view`. Auth/teams/profile provided by `magic_starter`.

### 8. Billing and plans

Flat tiers + per-responder add-on; **AI bundled into tiers, never metered per
investigation** (strategic: metered AI causes bill-shock during outages, exactly when
Uptizm's AI fires most, which destroys trust). Two upgrade levers: check interval (the
industry-standard paid lever) and AI depth (Uptizm-specific).

| | Free $0 | Pro $29/mo* | Business $99/mo* | Enterprise |
|---|---|---|---|---|
| Monitors | 10 | 50 | 200 | unlimited |
| Check interval | 3 min | 30 sec | 10 sec | 5 sec |
| Status pages / subscribers | 1 / 100 | 3 / 1,000 | 10 / 10,000 | unlimited |
| Responders | 1 | 3 (+$9 each) | 10 (+$9 each) | unlimited |
| AI | anomaly inbox (passive) | full analysis | + auto mode, digest, similar-incidents | + custom guardrails |
| History | 3 days | 30 days | 1 year | custom |

*annual; monthly $34 / $119. AI levels: `inbox` / `analysis` / `auto` / `custom`.
Paywall wiring (design-lab): `PlanLimits` on each plan gates check-interval floor,
AI mode, responder count, white-label, private pages, SSO; a reusable `UpgradeNudge`
links to `/teams/billing`. Screen: `plan_billing_view`. Note: the v2 API has NO billing
tables yet (see [open-decisions](open-decisions.md)).

### 9. Settings and account

iOS-style settings hub (page-per-section, not tabs): Profile, Appearance (dark/light),
Notifications (user-level), Language (selector; Flutter is actually translated via
`trans()`), Timezone (IANA list), Security (2FA / password / sessions), plus Help /
Changelog / Privacy / Terms. AI mode and metrics-library are team-level surfaces.
Screens: `settings_hub_view` + the `settings/*` views.

## Screen inventory (current mock)

Dashboard (KPI row + active incidents + monitor list + AI inbox) is the home. Then the
domains above: Monitors (list/create/edit/detail), Incidents (list/create/detail/
digest), Status pages (list/editor/preview/subscribers), Teams (create/settings/members/
notification-channels/escalation/on-call/billing/invite-accept), Settings (hub + 12
sub-pages), Auth (welcome + magic_starter login/register/etc). The design-lab also
defines `/settings/ai`, `/settings/metrics-library`, and `/preview` (component catalog).

## Design language

Modern-web aesthetic (Linear/Vercel/Stripe): disciplined subtraction, one brand color
(green), exhaustive state coverage (loading skeletons, empty, filtered-empty, error,
confirm, toast, paused/no-data). Monitoring status vocabulary is a frozen 6-family set
(up/down/degraded/paused/info/ai) that maps 1:1 across React tokens and Wind className
tokens, so the design ports mechanically to Flutter. `down` deliberately equals
`destructive`; brand green (hue 168) is kept distinct from operational `up` green.
