# Iterations

Four prototypes exist. Each contributed something; know which to reuse and which to drop.

## 1. v1 (`/Users/anilcan/Code/uptizm`)

First full-stack attempt. Laravel API (`uptizm-api`, `:8412`) + Flutter (`uptizm-app`,
883 files, magic/wind, fl_chart). Classic uptime monitor with an **AlertRule engine**:
Monitor -> check -> threshold-based `AlertRule` -> `alerts` (state machine) -> incident
-> status page. 24 Dart models, 26 screens. Brand sage green `#009E60`.

- **Working check engine** (proven): `ScheduleMonitorChecks` (every minute) ->
  `PerformMonitorCheck` per region -> `MonitorCheckService` (direct HTTP or Cloudflare
  relay via `relay_nodes`) -> `ProcessCheckResult` (status, metrics, assertions,
  auto-incident with 30-min cooldown, alert state machine) -> mail/database/OneSignal.
- **Carry forward:** the check-lifecycle mechanics, assertion + metric extraction,
  auto-incident + recovery, uptime-stats aggregation, announcements on status pages.
- **Drop:** the `AlertRule` / `alerts` / `alert_rule_states` engine (v2 replaced it with
  the unified Signal-to-Incident model). The multi-region `relay_nodes` table (v2 moved
  routing into the worker).

## 2. v2 (`/Users/anilcan/Code/uptizmv2`)

Most mature, clean reset (no v1 migration path). Laravel 13 / PHP 8.3 / Octane +
FrankenPHP / Postgres + TimescaleDB / Horizon / Reverb / Filament v4 admin, plus a
Cloudflare Worker + Durable Object probe fleet, and a Claude-agent AI layer with pgvector
similar-incidents. Flutter app (`uptizm-app`, 397 files) on magic/wind/magic_starter +
dusk/telescope/artisan tooling. Also ships `my-api-starter` (a reusable Laravel API
starter sharing the `magic-starter-laravel` foundation; uptizm-api does NOT depend on it).

- **This is the canonical backend + data model.** See [architecture](architecture.md)
  and [data-model](data-model.md).
- **Carry forward:** everything, as the reference. The 30-s scheduler, edge-pinned
  regional probes, TimescaleDB hypertables + nightly rollup, unified Signal-to-Incident,
  the 5 AI agents + AnomalyGate + honest-AI boundary, per-monitor/team AiMode.
- **Known gaps (v2 unfinished):** MagicStarter action stubs threw `not implemented`
  (signup was broken), Flutter tests removed, fluttersdk packages marked spike-only,
  Reverb config present but disconnected, NO billing/metering tables, channel dispatch
  not fully wired.

## 3. React design-lab (`/Users/anilcan/Code/uptizm-new/uptizm-design`)

React 19 + Vite + Tailwind v4 + base-ui. Deliberately NOT based on the prior MVPs'
visuals: from v1/v2 it took only the product purpose and domain; every visual is designed
from scratch at final-product quality. 47 semantic-token-only components, all interaction
states designed (loading/empty/filtered-empty/error/confirm/toast/paused/no-data). Geist
+ Geist Mono, single green brand, modern-web aesthetic.

- **This is the UX source of truth.** The status vocabulary (up/down/degraded/paused/
  info/ai) and semantic tokens map 1:1 to Wind for a mechanical Flutter port. Pricing
  model, AI graduated-trust, honest-AI-boundary, team system, and status-page
  architecture were all settled here.
- **Carry forward:** the whole design system + product surface + the pricing/paywall
  model (which the v2 backend lacks).

## 4. Current mock (`/Users/anilcan/Code/fluttersdk/uptizm`)

The newest iteration: a Flutter/Wind port of the design-lab, built on the fluttersdk
ecosystem (magic + magic_starter + wind + magic_devtools), now the design showcase for
that component system. 44 views, 25 components, pure mock data (`lib/app/mocks/`), no
backend. Single green brand (#008560/#00C292), Geist, Wind semantic tokens + the
hand-authored monitoring status families, `design:sync` theme generation, a `/preview`
catalog, and a component registry.

- **This is where the UI lives now.** It faithfully renders the design-lab surface.
- **Not yet:** any real backend, auth exercise, or live data. That is the open question.

## The through-line

React lab (look + product) -> v1 (proved the engine) -> v2 (AI-first engine + data model)
-> current mock (newest UI on fluttersdk). The real product = the design-lab UX + the v2
engine/data model + the design-lab pricing model, delivered on the fluttersdk stack. The
main unknown is which frontend (this mock vs uptizmv2/uptizm-app) and which backend path
carry forward. See [open-decisions](open-decisions.md).
