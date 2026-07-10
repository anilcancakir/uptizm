# Uptizm System Spec

Consolidated, forward-looking reference for the **Uptizm** product, synthesized from
all four prototype iterations. This is the foundation we build the real product from.
It is a reference (what exists, how it works) plus a set of open decisions for the
go-forward direction.

Scope note: the current repo (`fluttersdk/uptizm`) is the **UI/UX mock only** (mock
data, no backend). The monitoring engine and data model described here come from the
two full-stack iterations (v1/v2) and are the reference for the future backend build.
The deep technical layer (exact table DDL, queue/worker configs) is intentionally left
at overview level here; we flesh it out when we actually build those parts.

## What Uptizm is

Multi-region uptime + custom-metric monitoring, incident management, and public status
pages, with an **AI incident-analysis layer as the differentiator** (positioning:
Better Stack / Hyperping class, AI-first). The tenant boundary is a **team**; a team
owns monitors, incidents, status pages, members, and settings.

Brand: single green (calm, operational). Fonts: Geist + Geist Mono. Delivery: single
Flutter codebase (web + iOS + Android) on the fluttersdk ecosystem
(`magic` + `magic_starter` + `wind`), backed by a Laravel API.

## The four iterations

| # | Iteration | Path | Stack | Role | State |
|---|---|---|---|---|---|
| 1 | **v1** | `/Users/anilcan/Code/uptizm` | Laravel API + Flutter (magic/wind) | First full-stack: classic uptime monitor, `AlertRule` engine | Working check engine, 24 models, 26 screens |
| 2 | **v2** | `/Users/anilcan/Code/uptizmv2` | Laravel 13 (Octane/TimescaleDB) + Flutter | Most mature: dropped AlertRule, added AI + unified Signal to Incident | Production-grade reference backend + workers |
| 3 | **React design-lab** | `/Users/anilcan/Code/uptizm-new/uptizm-design` | React 19 + Vite + Tailwind v4 | UX source of truth for the whole product | 47 components, all states designed |
| 4 | **Current mock** | `/Users/anilcan/Code/fluttersdk/uptizm` | Flutter (magic/wind/magic_starter) | Flutter/Wind port of the design-lab, on fluttersdk | Pure mock (44 views, 25 components), no backend |

Evolution: React idea/UX lab defines the look and product surface. v1 proved the
monitoring engine (AlertRule-based). v2 rebuilt it AI-first (Signal to Incident,
Cloudflare probes, TimescaleDB, Claude agents). The current repo is the newest UI port,
now the design showcase for the fluttersdk component system.

## How monitoring works (one paragraph)

A 30-second scheduler picks monitors whose `next_check_at` is due, and dispatches one
check job per configured region. Each job relays an HMAC-signed probe spec to a
**Cloudflare Worker + Durable Object** pinned to that region, which runs the HTTP/TCP
check and returns full timing (DNS/connect/TLS/TTFB/download). The result lands on a
processing queue that persists the check to a TimescaleDB hypertable, updates the
monitor's denormalized state, extracts user-defined metrics (JSONPath/XPath/Regex/
header), and evaluates thresholds. An **incident opens** when a metric breaches a
warn/critical bound or consecutive failures cross a threshold. In parallel, an **AI**
path runs anomaly detection (Claude Sonnet) that either surfaces a suggestion for the
operator or auto-opens an incident (auto mode); other agents write the incident
analysis and auto-resolve on a healthy streak. Public status pages read a nightly
uptime rollup for their 90-day bars. Full detail: [architecture.md](architecture.md).

## Documents

- **[product.md](product.md)** what Uptizm does: feature domains, screen inventory, pricing, AI trust model, status-page architecture, team system.
- **[architecture.md](architecture.md)** how it works: the check-engine lifecycle, regional probes, queues, scheduled jobs, AI agents, notifications, realtime, stack.
- **[data-model.md](data-model.md)** the canonical entity model (v2 shape): monitors, checks, metrics, incidents, status pages, AI tables, enums, relationships.
- **[iterations.md](iterations.md)** what each of the four iterations is, what changed, and what to carry forward vs drop.
- **[db-design.md](db-design.md)** the CONCRETE DB + backend design for the real product: locked foundation decisions (Rounds 1-3), the full schema (tables/columns/hypertables/rollups/retention), the ingestion pipeline + Horizon queues, the Cloudflare Worker contract, the Reverb + API contract, and the billing-plugin architecture.
- **[ai-design.md](ai-design.md)** the AI layer: the two-tier anomaly detection (statistical + LLM), per-task model routing with 2026 pricing, the similar-incident embedding design, the laravel/ai framework patterns, the per-plan AI budget model, and the honest-boundary grounding guardrails.
- **[open-decisions.md](open-decisions.md)** the forward-looking decisions; the DB/architecture ones are now resolved in db-design.md, product-scope ones remain.
