# Data model

The canonical entity model is the **v2 shape** (most mature). UUID primary keys,
`SoftDeletes` on all domain models, everything team-scoped. The current Flutter mock
mirrors many of these as Dart classes over mock data; real persistence is deferred.

> As with architecture, the exact columns/DDL are captured at overview level. Expand
> with concrete migrations when we build the backend.

## Entity map

### Monitoring

- **Monitor** the config + denormalized live state. Key fields: `team_id`, `name`,
  `type` (http/tcp/ping/keyword/ssl), `url`, `method`, `request_headers`,
  `request_body`, `expected_status_code`, `check_interval`, `timeout_seconds`,
  `regions[]`, `auth_config` (encrypted), `assertion_rules`, `ai_mode`, `status`,
  `last_status`, `last_checked_at`, `last_response_ms`, `next_check_at`,
  `consecutive_fails`, `incident_threshold`, `ssl_tracking`, `alert_on_down/warn`,
  `show_on_status_page`, `only_show_if_degraded`, `is_group`, `parent_id`.
- **MonitorCheck** (TimescaleDB hypertable) one probe result. `(id, checked_at)` PK,
  `monitor_id`, `team_id`, `region`, `status`, `status_code`, `response_ms`, timing
  (`dns/connect/tls/ttfb/download_ms`), `response_headers`, `response_body_preview`
  (10KB cap), `error_message`, `assertions_passed`, `assertion_results`.
- **MonitorMetric** the extraction definition. `key` (snake_case, `^[a-z][a-z0-9_]*$`,
  max 40, unique per monitor), `label`, `type` (numeric/status/string), `source`
  (jsonpath/xpath/regex/header/status), `extraction_path`, `unit`, `threshold_direction`
  (high_bad/low_bad), `warn_bound`, `critical_bound`, `group_name`, `display_order`.
- **MonitorMetricValue** (TimescaleDB hypertable) extracted samples. `(id, checked_at)`
  PK, `check_id`, `metric_id`, `numeric_value`, `status_value`, `string_value`, `band`
  (ok/warn/critical).
- **monitor_daily_uptime** nightly rollup: `monitor_id`, `date`, `uptime_percent`,
  `total_checks`, `failed_checks` (powers the public 90-day bar).

### Incidents

- **Incident** `team_id`, `monitor_id` (primary hint), `title`, `severity`
  (critical/warn/info), `status` (detected/investigating/identified/monitoring/resolved/
  acknowledged), `signal_source` (UserThreshold/AiAnomaly/AiAnalyzer/Manual),
  `trigger_ref`, `metric_key`, `ai_owned`, `kind` (realtime/maintenance), `impact`
  (none/minor/major/critical), `started_at`, `resolved_at`, `postmortem_published_at`,
  `description_embedding` (pgvector 1536d, optional).
- **incident_monitors** (pivot) affected components: `incident_id`, `monitor_id`,
  `component_status_at_start`, `component_status_current`.
- **IncidentEvent** internal audit trail: `at`, `actor` (uuid/'system'/'ai'),
  `event_type`, `message`, `metadata`.
- **IncidentUpdate** operator-authored public updates: `status`, `display_at`, `body`
  (markdown), `pinned`, `notified_at`.

### Status pages

- **StatusPage** `team_id`, `title`, `slug` (unique), `primary_color`, `logo_path`,
  `is_public`, `preview_token`.
- **status_page_monitors** (pivot): `display_order`, `custom_label`.
- **StatusPageSubscriber** `email`, `confirmed_token`, `unsubscribe_token`,
  `newsletter_opt_in`.
- (v1 also had **Announcement** nested under status pages: maintenance/incident notices.)

### AI

- **ai_agent_runs** audit: `agent_class`, `input`, `output`, `tokens_input/output`,
  `cost_usd`, `started_at`, `completed_at`.
- **ai_analyses** `incident_id`, `root_cause`, `impact_forecast`, `confidence_level`.
- **ai_evidences** `ai_analysis_id`, `check_id`, `relevance_score`, `summary`.
- **ai_suggested_actions** `ai_analysis_id`, `action`, `priority`, `accepted_at`.
- **ai_suggestions** (UI inbox) `monitor_id`, `kind`, `recommendation`, `status`
  (pending/accepted/dismissed), `expires_at`.
- **similar_incidents** `incident_id`, `similar_incident_id`, `similarity_score`.
- **ai_agent_conversations** threaded assistant chat.

### Teams and users (from magic-starter-laravel)

`User`, `Team`, team membership (roles Owner/Admin/Member), `TeamInvitation`,
`PersonalAccessToken` (Sanctum, extended with device/2FA). Everything above is scoped by
`team_id`.

## Key enums (wire = snake_case; the Laravel <-> Dart contract, change both sides same PR)

- `MonitorType` http/tcp/ping/keyword/ssl; `HttpMethod` get/post/head/put; `HttpAuthType`
  none/basic/bearer/apiKey; `MonitorStatus` up/down/degraded/paused; `CheckInterval`
  30s/1m/5m/15m/1h.
- `MetricType` numeric/status/string; `MetricSource` jsonpath/xpath/regex/header/status;
  `ThresholdDirection` above/below (high_bad/low_bad).
- `IncidentStatus` detected/investigating/identified/monitoring/resolved/acknowledged;
  `IncidentSeverity` critical/warn/info; `IncidentImpact` none/minor/major/critical;
  `IncidentKind` realtime/maintenance; `SignalSource` userThreshold/aiAnomaly/aiAnalyzer/
  manual.
- `AiMode` off/suggest/auto; `AiTrigger` threshold/anomaly/rule/manualAssist;
  `AiConfidence` high/medium/low; `AiGateReason` budget/deprecatedModel/regionUnsupported/
  operatorBlocked.
- `ComponentStatus` operational/degraded/partial_outage/major_outage.

## Relationships (summary)

Team 1--* Monitor 1--* MonitorCheck 1--* MonitorMetricValue; Monitor 1--* MonitorMetric;
Monitor *--* Incident (via incident_monitors) with a primary-monitor hint; Incident 1--*
IncidentEvent, 1--* IncidentUpdate, 1--1 AiAnalysis 1--* AiEvidence/AiSuggestedAction;
StatusPage *--* Monitor (via status_page_monitors), StatusPage 1--* Subscriber.

## The unified-pipe invariant (v2 lock)

Monitor -> MonitorMetric (3 types, snake_case key) -> Incident (via `signal_source` +
`ai_owned`), gated by AiMode. There is NO separate "alert" table (v1's `AlertRule` /
`alerts` / `alert_rule_states` were dropped). "Alerts" in the UI and "incidents" in a tab
are the same `Incident` model. If a request seems to need a new top-level concept (e.g. a
separate alerts table), push back: it likely belongs inside an existing one.
