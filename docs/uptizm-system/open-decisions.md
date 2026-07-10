# Open decisions (go-forward)

Decisions to make before/while building the real product. Each names the question, the
context, and a leaning where one exists.

Status update: ALL architecture/DB/AI/product-scope decisions are now RESOLVED; only D10
(the concrete build-out) remains. Resolved in [db-design.md](db-design.md) +
[ai-design.md](ai-design.md) + the phasing below: D1 (go-forward frontend = the current
`fluttersdk/uptizm` mockup), D2 (framing = DUAL-PURPOSE: real product AND fluttersdk
component-system showcase, no fork; /preview + magic_devtools stay debug-only), D3
(backend = fresh build on this stack, v2 as reference), D4 (billing = Stripe magic plugin,
IAP on mobile), D5 (unified Signal to Incident), D6 (AI scope = v1 is SUGGEST-MODE;
auto-mode/auto-resolve/digest phase 2), D7 (public status page = Laravel HTML), D8 (Reverb
+ polling), D9 (parity = core v1 + SSL-expiry; other channels/RSS/CSV/maintenance/templates
phase 2). See the phasing section for the v1/phase-2/phase-3 split.

## Build phasing (v1 / phase 2 / phase 3)

Derived from D2 / D6 / D9. The MVP is a working monitoring product; AI-autonomy, billing,
and parity features layer on top.

### v1 (MVP): the core monitoring loop

Auth + teams (magic-starter-laravel); monitor CRUD (HTTP/TCP) + custom metrics; the
Cloudflare Worker multi-region checker + the ingestion pipeline (check hypertable, uptime
rollups); statistical anomaly + threshold incidents; the unified incident model + timeline
+ public updates; the Laravel-rendered public status page (90-day bars); email + in-app
notifications; SSL-expiry checks; Reverb live updates; **AI in SUGGEST-MODE** (statistical
flag -> LLM triage -> incident analysis -> AI inbox, operator approves). Billing = plan +
PlanLimits paywall gating only (no live payment yet).

### phase 2: AI autonomy, billing, channels

AI auto-mode (AI opens/drives incidents) + auto-resolve + weekly digest + similar-incident
search (embeddings) + the "Ask Uptizm" assistant; the seasonal-anomaly Python sidecar; the
billing magic plugin live payments (Stripe web + IAP mobile); multi-channel notifications
(Slack / Teams / webhook / SMS); on-call + escalation policies.

### phase 3: parity + scale polish

Custom-domain status pages (TLS); RSS/Atom feeds; subscriber CSV export;
scheduled-maintenance notifications; incident templates; postmortems; AI custom guardrails
(Enterprise); org-wide AI budget pooling + spend dashboard.

## D1. Which frontend carries forward?

Two Flutter clients exist: the current `fluttersdk/uptizm` mock (newest UI, on the
released fluttersdk stack, pure mock) and `uptizmv2/uptizm-app` (older UI, wired to the
v2 API, spike-only fluttersdk deps). The current mock has the better design and a
released dependency chain but no data wiring; uptizm-app has the API wiring but the older
look and unfinished auth.

- **Leaning:** current `fluttersdk/uptizm` as the go-forward client (better design +
  released deps), then port the API-wiring patterns from uptizm-app into it. Confirm.

## D2. Does `fluttersdk/uptizm` get a real backend, or stay a showcase?

Today it is a design showcase for the fluttersdk component system, backend-less by
design. Making it the real product means adding auth + data wiring + a live API.

- Options: (a) keep it a pure showcase, build the product elsewhere; (b) grow it into
  the real product by wiring it to a backend. If (b), D1 is effectively answered.

## D3. Which backend path?

- Options: (a) reuse/continue `uptizmv2/uptizm-api` as-is (Cloudflare worker + TimescaleDB
  + Horizon + Claude agents already built); (b) rebuild fresh on the released
  `magic-starter-laravel`; (c) start from `my-api-starter` (the reusable scaffold).
- **Leaning:** (a) reuse uptizmv2/uptizm-api. It is production-grade and already
  implements the canonical engine + data model; rebuilding discards proven work.

## D4. Billing: reconcile the gap

The v2 API has NO billing/metering tables. The design-lab has a full pricing model
(Free/Pro/Business/Enterprise + per-responder, AI bundled) with `PlanLimits` paywall
gates in the UI. To ship billing we need: plan/subscription/usage tables server-side, the
limit-enforcement points (monitor count, check-interval floor, AI level, responder count,
history retention), and a payment integration (Stripe likely).

- Decide: build billing now or defer; where the limit enforcement lives (server-authored
  vs UI-advisory + server-enforced).

## D5. Confirm the canonical incident model

v2's unified **Signal-to-Incident** (AlertRule engine dropped; every signal becomes an
Incident tagged by `signal_source`) is the intended shape. v1's separate AlertRule/alerts
tables are legacy.

- **Leaning:** lock v2's unified model. Do not reintroduce a separate alerts table.

## D6. AI provider + agents

v2 pins 5 Claude Sonnet agents (AnomalyDetector, IncidentAnalyzer, AutoResolve, plus
post-update drafting and weekly digest) with the model version in PHP attributes. The
honest-AI-boundary (reason only from Uptizm-owned signals) is implemented end-to-end.

- Decide: carry the agents forward as-is; set a cost budget + the AnomalyGate tuning;
  keep the model pin discipline (grep-able, not env).

## D7. Public status pages: rendering + custom domains

Flutter web (CanvasKit) is not crawlable, so a public status page needs a
Laravel-rendered HTML path. Custom domains need Cloudflare for SaaS or Caddy on-demand
TLS. Both are design-lab TODOs absent from v2.

- Decide: server-render the public status page; pick the custom-domain mechanism.

## D8. Realtime

v2 has Reverb config but it is disconnected. The design-lab assumes live status updates
(dashboard, incident timeline, public page).

- Decide: wire Reverb (broadcast incident/check updates) or poll (magic_notifications
  already polls). Leaning: Reverb for the public page + dashboard, polling as fallback.

## D9. Parity gaps to close (design-lab has, v2 lacks)

Multi-channel team notifications (Slack/Teams/webhook/SMS), RSS/Atom feeds for status
pages, subscriber CSV export, scheduled-maintenance notifications, incident templates,
SSL-expiry checks (`spatie/ssl-certificate`). Triage which ship in v1 of the real product.

## D10. The deep-technical build-out (the stated next step)

Per the plan for this document: build the concrete technical layer (exact table
schemas/DDL, TimescaleDB hypertable setup, queue/worker configuration, the Cloudflare
Worker, AI agent wiring) as its own pass, then revisit the whole picture and continue
from these docs. When that pass happens, expand [architecture](architecture.md) and
[data-model](data-model.md) with the concrete schemas and configs (replacing their
overview-level notes).
