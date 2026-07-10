# Database + backend design (real product)

The concrete DB + backend design for the real Uptizm product, built on the
`fluttersdk/uptizm` mockup (v1/v2 are MVP tests, reference only). Tech stack: Laravel 13
+ Horizon + PostgreSQL/TimescaleDB + Cloudflare Worker (checker) + Flutter frontend.

Status: IN PROGRESS. Decisions are locked round by round via interview; the schema
sections fill in as decisions land. This is the source of truth for the build.

## Decision log

### Round 1: foundations (LOCKED)

| # | Decision | Choice | Why |
|---|---|---|---|
| F1 | Time-series storage (checks + metric samples) | **TimescaleDB hypertables** | Auto-chunk + columnar compression + continuous aggregates; strongest for the check/metric write volume. Requires TimescaleDB-capable hosting (Timescale Cloud / Crunchy Bridge / Aiven, NOT AWS RDS) confirmed in Round 3. |
| F2 | Primary keys | **UUIDv7 everywhere** | Time-ordered: appends to the B-tree tail like bigint (no v4 page-split/index bloat, ~10x faster bulk load) and gives the Flutter/magic client its string-UUID keys. Native `uuidv7()` needs PG18+, else an app-side generator. |
| F3 | Multi-tenancy | **team_id + Laravel global scope + Postgres RLS (`FORCE ROW LEVEL SECURITY`)** | Shared schema with `team_id` on every domain table; a `TenantScope` global scope auto-filters/stamps at the app layer; RLS FORCE is the defense-in-depth backstop against a raw-query bypass. |
| F4 | Uptime/stats rollups | **Continuous aggregates + daily rollup table** | TimescaleDB continuous aggregates for live uptime %/response buckets (incremental, ~100x faster than scanning raw); a per-component-per-day `*_daily_uptime` table for the public 90-day status bars (Statuspage pattern: precomputed, never live). |

Sources: TimescaleDB hypertable/compression/continuous-aggregate docs; PostgreSQL
partitioning + RLS docs; UUIDv7 (RFC 9562, PG18 `uuidv7()`) benchmarks; Atlassian
Statuspage historical-uptime model. Full citations in the research pass.

### Round 2: domain schema (LOCKED)

| # | Decision | Choice | Why |
|---|---|---|---|
| D1 | Billing/plan layer | **Stripe-integrated, delivered as a MAGIC PLUGIN** (Flutter plugin + Laravel package), cross-platform web/iOS/Android | New reusable magic-ecosystem plugin (like magic_notifications). Stripe is the source of truth for subscriptions/invoices/payment methods (laravel/cashier); local DB stores plan + PlanLimits + cached subscription status + usage counters for paywall enforcement. Store-policy note: iOS/Android may require IAP for digital subscriptions (decided in Round 3). |
| D2 | System metrics (response time etc.) | **Implicit from the check row** | `monitor_checks` already stores `response_ms` + timing; `system` metrics are computed from checks, not stored as `monitor_metrics` rows. Only user-defined CUSTOM metrics get a `monitor_metrics` definition + `monitor_metric_values` samples. Avoids duplicating check data. |
| D3 | Incident timeline | **Single `incident_updates` table with `is_public`** | One table: `actor` (human/ai/system), `is_public`, `autonomous`, `message`, `status`. Matches the mockup's `TimelineEntry` exactly; the public status page filters `is_public = true`. |
| D4 | Raw check retention | **Plan-gated raw retention + long-kept rollups** | Raw checks compressed after the active window, then dropped per the plan's history limit (3d/30d/1y/custom) via a Timescale retention policy; daily rollups kept long (cheap) so uptime history outlives raw retention. Matches the pricing history tiers. |

Derived (no separate decision, translated mechanically from the mockup + Round 1):
escalation policy `steps` as a jsonb ordered list (read/written as a unit with the
policy); regions as config/enum (Cloudflare location codes), `monitor.regions` a jsonb
array; one `notification_channels` row per type per team (`config` jsonb + `severity` +
`enabled`); incident to monitor as a many-to-many `incident_monitors` pivot carrying
`status_at_start`/`status_current` (Statuspage affected-component shape) plus a primary
monitor hint; incident similarity via a pgvector `embedding` column with an HNSW index
(fallback `float4[]` + app-side cosine when the extension is absent).

### Round 3: pipeline + contracts (LOCKED)

| # | Decision | Choice | Why |
|---|---|---|---|
| P1 | Checker deployment | **Cloudflare Worker, multi-region from day 1** | HMAC-signed relay to region-pinned Durable Objects (5-11 regions), egress from target geography. Regional breakdown is a core feature + a key AI signal, so it is not deferred. Proven in v2. Needs worker deploy + secret management (Round: infra). |
| P2 | Realtime updates | **Reverb WebSocket + polling fallback** | Reverb broadcasts incident/check/status changes to the dashboard + public page; magic_notifications polling is the graceful fallback. Wires the Reverb config v2 left disconnected. |
| P3 | Billing mobile payment | **IAP on mobile (StoreKit/Play Billing) + Stripe on web** | Fully store-compliant. The billing plugin must reconcile THREE payment sources (Stripe web, Apple, Google) into ONE server-side entitlement: Stripe webhooks + Apple/Google server-side receipt validation all feed a single `subscriptions` state. 15-30% store cut accepted for compliance. |
| P4 | Public status page render | **Laravel-rendered HTML (Blade)** | `/s/:slug` + custom domains are server-rendered HTML: crawlable, fast, no CanvasKit. The Flutter app renders the authenticated panel only; the public page is a separate Laravel-served surface reading the precomputed daily-uptime rollup. |

## Conventions (from Round 1)

- Every domain table: `id uuid` (UUIDv7) PK, `team_id uuid` FK (RLS + global scope),
  `created_at`/`updated_at`, soft-delete where the mockup implies recoverable entities.
- Enum columns store snake_case string wire values (the Laravel <-> Flutter/magic
  contract; change both sides in the same PR). Enum shape follows the MOCKUP, which is
  simpler than v2 (e.g. `SignalSource` = threshold/anomaly/manual; `IncidentLifecycle` =
  detected/investigating/identified/monitoring/resolved).
- Time-series tables (`monitor_checks`, `monitor_metric_values`) are hypertables:
  `(id, <time>)` composite PK, space-partitioned on `monitor_id`, composite index
  `(monitor_id, region, <time> DESC)`, compression after the active-write window.
- API envelope: Laravel API Resources `{data, links, meta}`; cursor pagination for
  high-insert feeds (checks, incident updates).

## Schema

Convention (F1-F4) applies to every table: `id uuid` (UUIDv7) PK, `team_id uuid` FK on
domain tables (global scope + RLS FORCE), `created_at`/`updated_at`, `deleted_at` where
the entity is recoverable. Enum columns are snake_case strings following the mockup.
Only domain-specific columns are listed below.

### Identity + tenancy (from magic-starter-laravel)

- **users** id, name, email, password, profile_photo_path, timezone, locale, two_factor_*.
- **teams** id, name, slug, color, personal_team.
- **team_user** team_id, user_id, role (owner/admin/member).
- **team_invitations** team_id, email, role.
- **personal_access_tokens** (Sanctum) + device_name, ip_address, user_agent, location,
  last_used_at (powers the Sessions screen: id, device, location, last_active, current).

### Monitors, checks, metrics

- **monitors** name, url, type (http/tcp/ping/keyword/ssl), method, request_headers jsonb,
  request_body, expected_status_code, check_interval_sec, timeout_sec, regions jsonb (array
  of region codes), auth_config jsonb (encrypted), assertion_rules jsonb, tags jsonb,
  ai_mode (off/suggest/auto), status (active/paused), last_status (up/down/degraded/paused),
  last_checked_at, last_response_ms, next_check_at (scheduler clock), consecutive_fails,
  incident_threshold, slo_target (double), escalation_policy_id, show_on_status_page,
  only_show_if_degraded, is_group, parent_id. Index: (team_id, status), next_check_at.
- **monitor_checks** HYPERTABLE. `(id, checked_at)` PK, monitor_id, team_id, region,
  status (up/down/degraded), status_code, response_ms, timing_dns/connect/tls/ttfb/
  download_ms, response_headers jsonb, response_body_preview (10KB cap), error_message,
  assertions_passed, assertion_results jsonb, probe_run_id (idempotency). Space-partition
  on monitor_id; index (monitor_id, region, checked_at DESC); compress after active window.
- **monitor_metrics** (CUSTOM metrics only, D2) monitor_id, key (snake_case, `^[a-z][a-z0-9_]*$`,
  <=40, unique per monitor), label, unit, source (jsonpath/xpath/regex/header), path,
  direction (high/low), warn (num), critical (num), display_order.
- **monitor_metric_values** HYPERTABLE. `(id, recorded_at)` PK, monitor_id, metric_id,
  check_id, team_id, numeric_value, string_value, status_value, band (ok/warn/critical).
- (System metrics = response time etc. are computed from monitor_checks, no rows, D2.)

### Incidents (unified Signal to Incident)

- **incidents** title, primary_monitor_id, impact (down/degraded/info), severity
  (critical/warning/info), signal_source (threshold/anomaly/manual), lifecycle
  (detected/investigating/identified/monitoring/resolved), ai_owned, assignee_id (team
  member), acknowledged_by, acknowledged_at, started_at, resolved_at, trigger_metric_key,
  embedding vector(1536) (pgvector, HNSW; float4[] fallback), postmortem_body,
  postmortem_published_at. Index: (team_id, lifecycle, started_at).
- **incident_monitors** (pivot, affected components) incident_id, monitor_id,
  status_at_start, status_current.
- **incident_updates** (single timeline, D3) incident_id, actor (human/ai/system), author,
  status, message, is_public, autonomous, display_at. Public page filters is_public=true.

### AI layer (full architecture in ai-design.md)

- **ai_analyses** (1:1 incident) incident_id, trigger, confidence (strong/moderate/insufficient),
  tldr.
- **ai_evidence** ai_analysis_id, stance (for/against), label, detail, signal_ref
  (check_id/metric_key/region, resolved + null-checked before persist).
- **ai_suggested_actions** ai_analysis_id, title, rationale, priority, accepted_at.
- **ai_similar_incidents** incident_id, similar_incident_id, similarity (double).
- **ai_suggestions** (dashboard inbox) monitor_id, kind, recommendation, confidence,
  status (pending/accepted/dismissed), dismiss_reason, expires_at.
- **ai_agent_runs** (audit) agent, task, provider, model, incident_id, monitor_id,
  input jsonb, output jsonb, usage jsonb (prompt/completion/cache/reasoning tokens),
  cost_usd, duration_ms, status.
- **anomaly_candidates** (statistical detector output, feeds LLM triage) monitor_id,
  signal, method (mad/ewma/cusum/hysteresis/seasonal), score, severity, window jsonb,
  evidence jsonb, region_votes jsonb, quorum, consecutive_confirmations, dedupe_key
  (unique), status (candidate/triaged/promoted/dismissed). Hypertable-eligible.
- **ai_budget_usage** (per-plan quota + graceful degrade, A4) team_id, period, task,
  invocations, cost_usd.
- **monitors** gains anomaly-state columns for O(1) incremental detectors: `ewma_value`,
  `ewma_var`, `cusum_pos`, `cusum_neg`, `baseline_updated_at`.
- **incidents.embedding**: voyage-3.5-lite 1024d (int8), global HNSW `vector_cosine_ops`
  (m=16, ef_construction=200) + team_id btree + `hnsw.iterative_scan=relaxed_order`;
  content-hash gate for re-embed; float4[] fallback when pgvector absent.

Full AI architecture (anomaly two-tier runtime, model routing table, budget model,
grounding guardrails, laravel/ai patterns) is in [ai-design.md](ai-design.md).

### Status pages

- **status_pages** name, slug (unique), domain_mode (subdomain/path), custom_domain,
  brand_color, logo_path, logo_text, description, is_public, subscriptions_enabled,
  preview_token.
- **status_page_monitors** (pivot) status_page_id, monitor_id, display_order, custom_label.
- **status_page_metrics** (pivot) status_page_id, monitor_id, metric_key.
- **status_page_subscribers** status_page_id, email, confirmed_token, unsubscribe_token,
  subscribed_at, confirmed_at, newsletter_opt_in.
- **component_daily_uptime** (rollup for the public 90-day bar, F4) monitor_id, day,
  uptime_pct, total_checks, failed_checks, worst_status. Kept long (cheap).

### Notifications, on-call, escalation

- **notification_channels** type (email/sms/slack/teams/webhook), name, config jsonb
  (webhook url/secret, slack channel, phone, teams url), severity (all/critical),
  connected, enabled. (one per type per team)
- **escalation_policies** name, description, steps jsonb (ordered: after_minutes,
  targets[]), repeat_last_step, is_default.
- **on_call_schedules** name, rotation jsonb (member ids, cadence, handoff_at),
  current_member_id (cached), + on_call_overrides (member_id, from, to).
- **user_notification_preferences** user_id, channel (in_app/web_push/email), enabled.

### Billing (delivered as a magic plugin, D1 + P3)

- **plans** name, tagline, monthly, annual, ai_line, features jsonb, responder_addon,
  recommended. (admin-editable, seeded from the pricing model)
- **plan_limits** plan_id, monitors, check_interval_sec, status_pages, subscribers,
  responders, ai_level (inbox/analysis/auto/custom), white_label, private_pages, sso.
- **subscriptions** team_id, plan_id, source (stripe/apple/google), external_subscription_id,
  status (active/trialing/past_due/canceled), current_period_end, seats, cancel_at_period_end.
- **payment_methods** team_id, source, brand, last4, expiry. (Stripe/web only; IAP carries none)
- **invoices** team_id, source, external_id, number, amount, currency, status
  (paid/pending/failed), issued_at, pdf_url.
- **usage_counters** team_id, metric (monitors/responders/checks_this_month), used, period.

## Time-series: hypertables, continuous aggregates, retention

- Hypertables: `monitor_checks`, `monitor_metric_values` (chunk interval sized to ~25% RAM
  of active writes; start with 1-day chunks, tune under load). Compression on chunks past
  the active-write window (columnar; delta-of-delta timestamps, XOR floats).
- Continuous aggregates off `monitor_checks`: `monitor_uptime_hourly` (per monitor per
  region: uptime %, counts by status, avg/p50/p95/p99 response). Powers the dashboard +
  monitor-detail charts incrementally (materialized_only tuned per staleness tolerance).
- Daily rollup `component_daily_uptime` (per monitor per day) for the public status page
  90-day bars, precomputed by a 00:15 job (never live).
- Retention (D4): drop raw `monitor_checks`/`monitor_metric_values` past the team plan's
  history limit (3d/30d/1y/custom) via a Timescale retention policy keyed off the owning
  monitor's team plan; keep `component_daily_uptime` + hourly aggregate long so uptime
  history survives beyond raw retention.

## Ingestion pipeline + Horizon queues

Queues (Horizon supervisors, `auto` balance, per-purpose so AI can't starve checks):

- **scheduling** (1 worker, unique lock) `ScheduleMonitorChecks` every 30s: pick monitors
  where `next_check_at <= now()`, advance `next_check_at` in a txn (no double-dispatch),
  dispatch one `PerformMonitorCheck` per region.
- **checks** (N workers) `PerformMonitorCheck` signs + POSTs the probe spec to the
  Cloudflare Worker; the worker callback re-enters via the relay result endpoint.
- **processing** (N workers) `ProcessCheckResult`: buffer results and flush via batched
  multi-row INSERT / COPY into the hypertable (not row-at-a-time), update the monitor's
  denormalized state, extract custom metrics, run the threshold evaluator (open incident on
  metric-band breach or `consecutive_fails >= incident_threshold`). Idempotency key =
  `monitor_id + region + probe_run_id`, checked/stored in the same txn as the write.
- **ai** (M workers) anomaly evaluation (gated by an AnomalyGate: skip unchanged state /
  active AI incident / cooldown), incident analysis, auto-resolve.
- **aggregates** rollup + retention jobs. **notifications** channel dispatch.
  **billing** Stripe webhooks + Apple/Google receipt validation.

## Cloudflare Worker checker contract

- Laravel -> Worker `POST /run`: `{monitor_id, region, type, method, url, headers, body,
  timeout_sec, expected_status_code, auth_config, assertion_rules, probe_run_id}` with
  `X-Relay-Signature` (HMAC-SHA256 of `timestamp:body`, `RELAY_SECRET`) + `X-Relay-Timestamp`.
- Worker: routes to a region-pinned Durable Object via `locationHint` (best-effort egress
  from the target geography), runs the probe with an AbortController timeout, captures
  timing breakdown; `ctx.waitUntil()` to process/callback in the background.
- Worker -> Laravel `POST /api/v1/relay/result` (VerifyRelaySignature middleware):
  `CheckResultPayload {monitor_id, region, checked_at, status, status_code, response_ms,
  timing{dns,connect,tls,ttfb,download}, response_headers, response_body_preview,
  error_message, probe_run_id}`. Secrets byte-match `.env RELAY_SECRET` <-> `wrangler
  secret put` or callbacks 401. Paid plan required (Free: 10ms CPU / 50 subrequests).

## Realtime (Reverb) + API contract

- Reverb (P2) broadcasts on team + status-page channels: incident opened/updated, monitor
  status change, check tick. Client subscribes; magic_notifications polling is the fallback.
- REST `/api/v1`, Sanctum bearer token, team-scoped by `current_team_id`. Laravel API
  Resources envelope `{data, links, meta}`. Cursor pagination (`meta.next_cursor`) for
  high-insert feeds (checks, incident updates). UUID string keys, snake_case enum wire
  values (the Laravel <-> magic contract; change both sides same PR). Resource endpoints
  per domain: monitors (+ pause/resume/test/checks/uptime/response-times/metrics),
  incidents (+ updates/acknowledge/resolve), status-pages (+ subscribers/publish),
  ai (suggestions/agent-runs/settings), teams, billing.

## Billing plugin architecture (D1 + P3)

Delivered as a magic ecosystem plugin, mirroring magic_notifications' shape:

- **Flutter plugin** (`magic_billing`): shows plan + usage + limits on every platform;
  the purchase/upgrade flow is platform-conditional via `_io`/`_web`/`_stub` imports:
  web -> Stripe Checkout, iOS -> StoreKit, Android -> Play Billing. UI in Wind widgets.
- **Laravel package** (backend): laravel/cashier (Stripe) + Apple App Store Server API +
  Google Play Developer API receipt validation. All THREE sources reconcile into ONE
  `subscriptions` entitlement row; a single entitlement resolver answers "active plan +
  limits for this team" regardless of source. Endpoints under `/api/v1/billing/*`
  (plans, subscription, checkout-session, receipt-validate, usage). Webhooks (Stripe) +
  server-side receipt verification (Apple/Google) keep the entitlement fresh.
- Paywall: `PlanLimits` gates check-interval floor, monitor count, AI level, responder
  seats, status pages/subscribers, white-label/private-pages/SSO. Enforced server-side
  (authoritative) + surfaced client-side via UpgradeNudge.

## Still to detail when building (not blocking the design)

Exact migration DDL + chunk/compression/retention policy SQL; the Reverb channel auth +
event payloads; the Apple/Google receipt-validation flows; the custom-domain TLS
mechanism (Cloudflare for SaaS vs Caddy on-demand); RSS/Atom + subscriber CSV export +
scheduled-maintenance notifications (design-lab parity gaps).
