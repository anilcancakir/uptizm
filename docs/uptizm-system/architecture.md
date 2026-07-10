# Architecture (how it works)

This is the server-side monitoring engine, described at the level needed to understand
and plan. It is drawn from the two full-stack iterations; v2 is the canonical shape.

> DEEP TECHNICAL = FUTURE BUILD. The exact table DDL, TimescaleDB hypertable setup,
> queue-worker counts/tuning, Cloudflare Worker code, and AI agent wiring are captured
> here only at overview level. We flesh those out (and actually build them) as their own
> pass, working from this document. When we do, expand this file with the concrete
> schema tables and worker configs.

## Stack (v2 reference)

- **Backend:** Laravel 13, PHP 8.3, Octane + FrankenPHP runtime.
- **DB:** PostgreSQL + TimescaleDB (hypertables for `monitor_checks` and
  `monitor_metric_values`), pgvector optional (incident-similarity; PHP-cosine fallback).
- **Queues/cache:** Redis via Laravel Horizon (queues: `scheduling`, `checks`,
  `processing`, `ai`, `aggregates`).
- **Realtime:** Reverb WebSockets (config exists in v2 but currently disconnected).
- **Auth/teams:** `fluttersdk/magic-starter-laravel` (Sanctum tokens, 2FA, profiles,
  teams). Admin: Filament v4 at `/sosecretadmin` (whitelist-gated).
- **Regional probes:** Cloudflare Worker + Durable Objects, deployed separately.
- **AI:** Laravel AI (Anthropic Claude Sonnet agents), model pinned in PHP attributes.
- **Client:** Flutter (magic/wind/magic_starter), `Http` facade to `api/v1`, Bearer
  token in `Vault`, response envelope `{data: ...}`.

## The check-engine lifecycle (v2, canonical)

```
[scheduler every 30s]  ScheduleMonitorChecks (scheduling queue, unique lock)
        |  pick monitors where next_check_at <= now(); advance next_check_at in a txn
        |  (txn advance prevents double-dispatch if a worker crashes)
        v  dispatch one job per configured region
[checks queue]         PerformMonitorCheck(monitor, region)
        |  RelayClient signs an HMAC (SHA-256 of timestamp:body, RELAY_SECRET)
        v  POST probe spec to the Cloudflare Worker at RELAY_URL
[edge]                 Cloudflare Worker -> region-pinned Durable Object
        |  verify HMAC; route by region (REGION_TO_HINT, 5 regions)
        |  run HTTP/TCP probe with locationHint so egress is from the target geography
        |  capture status + timing (dns/connect/tls/ttfb/download), headers (1KB),
        |  body preview (10KB); AbortController timeout -> status=down on failure
        v  callback POST /api/v1/relay/result (VerifyRelaySignature middleware)
[processing queue]     ProcessCheckResult
        |  CheckPersistenceService (one txn):
        |    - insert MonitorCheck into the checks hypertable
        |    - update monitor denorm state (last_status/last_checked_at/last_response_ms,
        |      consecutive_fails++ or reset)
        |    - MetricExtractor: JSONPath/XPath/Regex/header/status -> MonitorMetricValue
        |  ThresholdEvaluator (after the txn, so a failure cannot corrupt the check):
        |    - band each metric with a threshold_direction (high_bad/low_bad); warn/critical
        |      -> open incident with metric context
        |    - else consecutive_fails >= incident_threshold (default 2) -> open incident
        |    - openIncident() -> Incident(status=detected, signal_source=UserThreshold)
        |      + dispatch RunIncidentAnalysis to the ai queue
        v  (if monitor AI-enabled) dispatch EvaluateAnomalies + maybe AutoResolveIncidents
[ai queue]             AI path (parallel to the threshold path)
             EvaluateAnomalies: AnomalyGate (skip if state unchanged / active AI incident
               / cooldown) -> AnomalyDetectorAgent (Claude Sonnet, structured output) ->
               AiSuggestion (suggest mode) or auto-open incident (auto mode)
             RunIncidentAnalysis: IncidentAnalyzerAgent -> AiAnalysis (root cause +
               impact forecast + confidence + evidence)
             AutoResolveIncidents: on a 3+ healthy streak -> AutoResolveAgent closes
               AI-owned incidents when evidence allows
```

### Status determination (per check)

`error/timeout -> down`; `status_code != expected -> down`; `assertion fails -> down`;
`response_time > 80% of timeout -> degraded`; else `up`. (v1 wording; v2 keeps the same
intent, with the worker classifying up / degraded / down from the probe.)

### Incident lifecycle

`detected -> investigating -> identified -> monitoring -> resolved` (+ acknowledged).
Operator posts public `IncidentUpdate`s (broadcast to subscribers via WebSocket +
email/push). AI-owned incidents can auto-close on a healthy streak. A 30-minute cooldown
(v1) prevents auto-incident spam. Incident to monitor is many-to-many
(`incident_monitors`) for multi-component outages, with per-component status at start vs
current.

## Regional probes (Cloudflare Worker)

`workers/regional-checker/` in v2. Entry (`src/index.ts`) verifies the relay HMAC and
routes the payload to a region-pinned Durable Object (`src/regional-probe.ts`) via a
`REGION_TO_HINT` map. Each DO's egress leaves from the target geography, so a probe from
`eu-west` actually tests from Europe. It returns a `CheckResultPayload` (status, code,
response ms, timing breakdown, headers, body preview, error message). Secrets must
byte-match between the Laravel `.env` `RELAY_SECRET` and `wrangler secret put` or
callbacks 401. v1 used a `relay_nodes` table for this; v2 moved region routing into the
worker and dropped the table.

## Scheduled jobs

- **Every 30 s:** `ScheduleMonitorChecks` (fan out due checks; unique lock).
- **Daily 00:15 UTC:** `AggregateMonitorDailyUptime` rolls yesterday's checks into
  `monitor_daily_uptime` (one row per monitor per day) so the public 90-day uptime bar
  never scans the raw hypertable.
- **Weekly Mon 08:00 UTC:** `GenerateWeeklyDigest` per team (gated on the digest flag):
  top incidents, anomalies detected, uptime trends, suggestions accepted.

## AI agents (5)

`AnomalyDetector` (per-check anomaly), `IncidentAnalyzer` (narrative root-cause),
`AutoResolve` (close AI incidents on recovery), plus post-update drafting and the weekly
digest. Each agent logs an `ai_agent_runs` row (tokens + cost + duration). Model version
is pinned in a PHP attribute (`#[Model('claude-sonnet-4-6')]`), not env, so it is
grep-able before upgrades. `AnomalyGate` avoids token waste by skipping unchanged state,
active AI incidents, and cooldowns. Similarity search uses pgvector when present, else a
PHP cosine over stored JSON embeddings.

## Notifications and alerting

Server-side: incident opened, status transition, and public `IncidentUpdate` posts
trigger delivery. Team channels (email / OneSignal push / webhook / Slack / Teams / SMS)
are configured per team; team members receive all incidents; public status-page
subscribers receive updates for the components they follow. On the client,
`magic_notifications` handles in-app database polling + OneSignal push + mail. (v1 had a
concrete `MonitorDownNotification` over mail/database/OneSignal filtered by the
magic_starter preference registry; v2 wires channels through magic-starter but the exact
channel dispatch was not fully traced and is a build-out item.)

## Uptime stats

Computed from the checks hypertable: team stats (counts by last_status, avg response),
per-monitor 24h/7d/30d uptime % + trend, uptime timeline (daily buckets), response-time
series (raw 1h / 5-min buckets 6h / hourly 24h). Postgres time-bucket in production, a
SQLite datetime fallback for tests.

## Current-mock reality

The current `fluttersdk/uptizm` repo implements NONE of the above server-side. Its
screens render deterministic mock data from `lib/app/mocks/` (monitors, incidents,
metrics, status_pages, oncall, billing, settings, teams). The `network.dart` base URL
(`localhost:8000/api/v1`) and the `magic_starter` auth config are inherited scaffold,
not exercised by the monitoring screens. This engine is the target for a future backend.
