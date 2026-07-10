# AI layer design

The AI architecture for the real Uptizm product. AI is the differentiator and is BUNDLED
into pricing tiers (not metered), so per-task cost/performance is load-bearing. Grounded
in a 6-stream research pass (2026-07): model price/performance, anomaly-detection methods,
laravel/ai framework, embeddings/pgvector, competitive AIOps, and cost economics.

Hard constraint (honest-AI-boundary): Uptizm is a synthetic EXTERNAL observer with NO
access to the customer's deploys/git/logs/APM/traces. The AI reasons ONLY from
Uptizm-owned signals: check results (status code, response time), regional probe
breakdown, the customer's own exposed custom metrics, failure cadence, cross-monitor
correlation, baseline deviation. Everything below enforces this.

## Decision log (LOCKED)

| # | Decision | Choice | Why |
|---|---|---|---|
| A1 | Anomaly runtime | **Two-tier: cheap primitives in PHP/SQL + Python sidecar for seasonal** | MAD z-score / EWMA / CUSUM / hysteresis run per-check for free (O(1) state or SQL over continuous aggregates); STL / S-H-ESD / Holt-Winters are not incremental (need multiple full cycles), so they run in a Horizon-scheduled Python sidecar that recomputes seasonal baselines hourly. Per-check LLM calls are cost-prohibitive and pointless (>99% of checks are nominal). |
| A2 | Model/provider routing | **Multi-provider best-of-breed** | Per-task routing beats a single pin: Opus 4.8/Sonnet 5 for reasoning, Haiku 4.5 for drafting, a Groq-hosted open model for high-volume triage (15-60x cheaper). laravel/ai failover gives cross-vendor resilience. |
| A3 | LLM framework | **laravel/ai (accepting pre-1.0)** | First-party, covers structured output + multi-provider failover + streaming + pgvector + Horizon-queued agents in one PHP package. Caveat: v0.9.0 (0.x); the ai_agent_runs audit + per-team budget cap are NOT built in and are our responsibility (via the `Usage` object + `AgentPrompted` event). |
| A4 | AI budget / gating | **Per-plan invocation quota + graceful degrade; never gate a live alert behind AI budget** | Checkly's bundled-with-quota model, deduped first-occurrence-per-incident. When quota is spent, the LLM NARRATIVE degrades to statistical-only, but the underlying alert/notification always fires. Quota is per-team (not per-user), with an in-product visible counter (no bill-shock). |
| A5 | Model delivery + cost routing | **Route through OpenRouter (native `Lab::OpenRouter` in laravel/ai), cheap open models for the 5 volume tasks, Claude only on the rare quality-critical analysis** | OpenRouter adds no inference markup (5% only on BYOK). Cost analysis: incident analysis is ~86% of the bill, and it is the one task where grounding fidelity matters, so keep it on Claude (Sonnet 5 default, Opus escalation for P1), while triage/drafting/digest/assistant move to cheap OpenRouter models (6-32x cheaper, low-stakes). Lands at ~62.7% savings ($3.09 vs $8.28/team/mo) with Claude quality preserved on the critical call. Keep a direct-Anthropic fallback for the strict-schema path. (Decided by best-judgment default while the user was away; cost-lean honored, revisable.) |

## 1. Anomaly detection (two-tier)

Cheap statistical detectors flag candidates on every check; an LLM only ever sees flagged
candidates. Method per signal:

| Signal | Method | Params | Runtime |
|---|---|---|---|
| Response-time spike | Modified z-score on MAD | `0.6745*(x-median)/MAD`, flag \|M\|>3.5, window 20-60 checks | SQL over continuous aggregate |
| Slow drift | EWMA control chart | lambda 0.2-0.3, k=3 | O(1) state column on the monitor row (updated in ProcessCheckResult) |
| Regime shift | CUSUM | k approx 0.5 sigma, h approx 4 sigma | O(1) accumulator column (hand-rolled; no Timescale hyperfunction) |
| Flapping | Hysteresis + consecutive-confirmation | 3 consecutive fails to trip DOWN, stricter recovery | pure counters in PHP |
| Seasonal baseline | STL + Seasonal-Hybrid ESD, or Holt-Winters | seasonal_periods = checks/day and checks/week; hybrid median/MAD, alpha 0.05 | Python sidecar, hourly, writes thresholds back |
| Custom-metric band | user warn/critical bounds; river HalfSpaceTrees for learned shape | per-metric | PHP for static bounds; sidecar for learned |

- Cheap primitives keep `ProcessCheckResult` to O(1) writes (never a per-check windowed
  re-scan). Seasonal decomposition needs 2+ full weekly cycles, so it runs only in the
  sidecar and only after enough history exists.
- Multi-region correlation = a per-cycle quorum vote over the region map (any / majority /
  all, configurable per monitor): low agreement = regional degradation, high = global
  outage. This is a key honest-AI signal ("single region slow, others fine").
- Cold start: a new monitor uses its configured warn/critical bands as static thresholds
  for the first ~100 checks / 24h, then switches to EWMA/MAD; seasonal only after 2+ cycles.
- False-positive control: consecutive-confirmation, hysteresis, dead-bands, multi-region
  quorum, and a dedupe key so one incident does not re-flag.

Candidate object (fed to LLM triage):
```json
{ "monitor_id": "...", "signal": "response_time", "method": "ewma", "score": 3.8,
  "severity": "warn", "window": {"from": "...", "to": "...", "n": 60},
  "evidence": {"observed": 812, "baseline": 240, "threshold": 3.0, "unit": "ms"},
  "region_votes": {"fra": true, "iad": true, "sin": false}, "quorum": "majority",
  "consecutive_confirmations": 3, "dedupe_key": "monitor:...:response_time:ewma:2026-...",
  "status": "candidate" }
```

## 2. AI pipeline (where each step runs in the Horizon flow)

```
ProcessCheckResult (processing queue)
  -> cheap statistical detectors (in-process / SQL): emit anomaly candidate or nothing
  -> if candidate AND monitor AI-enabled AND within budget: dispatch to `ai` queue
[ai queue]
  EvaluateAnomaly: AnomalyGate (skip unchanged state / active AI incident / cooldown /
    over-budget) -> LLM TRIAGE (Groq small model) confirms + scores -> AiSuggestion
    (suggest mode) or auto-open incident (auto mode)
  RunIncidentAnalysis (on threshold/manual/AI-opened incident): LLM ANALYSIS -> AiAnalysis
    (root cause + evidence for/against + confidence + advisory actions) + embedding for
    similar-incident search
  AutoResolveIncidents (on 3+ healthy streak, AI-owned incidents): LLM decision -> close
  DraftIncidentUpdate: LLM drafting of a public update (operator edits before publish)
[scheduled]  seasonal-baseline sidecar (hourly); WeeklyDigest (Batch API, Monday)
```

## 3. Model routing (via OpenRouter, per task, plan-gated)

Delivery: all LLM calls go through **OpenRouter** (native `Lab::OpenRouter` in laravel/ai;
no inference markup, 5% only on BYOK). Use `:floor` / `provider.sort=price` for the cheap
tasks, `require_parameters:true` + a pinned model + a direct-Anthropic fallback for the
strict-schema analysis path. Embeddings can also route through OpenRouter (or direct
Voyage). Caveat: OpenRouter has NO batch-API discount (unlike OpenAI direct), so "batch"
tasks just use `:floor`; stream-cancellation billing is not honored on some upstreams.

OpenRouter prices dated 2026-07. Cheap models carry the 5 volume tasks; Claude carries
only the rare quality-critical analysis (per A5).

| Task | Default model | $/M in-out | ~cost/call | Notes / fallback |
|---|---|---|---|---|
| Anomaly triage (flagged only) | Llama 3.1 8B | $0.02/$0.03 | ~$0.00001 | Response Healing on. NOT DeepSeek V4 Flash for any grounded output (96% hallucination) |
| Incident analysis (rare, critical) | **Sonnet 5** default; **Opus 4.8** escalate P1/high-confidence | $2/$10 ; $5/$25 | ~$0.019-0.047 | Cost-ceiling on low tiers: **GLM-5.2** ($0.54/$1.76, BFCL v3 at/above Claude) via a constrained-decoding host + validate-retry. Direct-Anthropic fallback for the strict path |
| Auto-resolve decision | DeepSeek V4 Flash | $0.09/$0.18 | ~$0.0001 | small structured yes/no + confidence |
| Incident-update drafting | Qwen3.6 Plus (or GLM-4.5-Air) | $0.325/$1.95 | ~$0.0008 | templated prose from structured data |
| Weekly digest | GLM-5.2 (`:floor`) | $0.54/$1.76 | ~$0.007 | non-interactive; no OR batch discount |
| Ask Uptizm assistant (streaming) | Gemini 3.1 Flash Lite | $0.25/$1.50 | ~$0.0015 | streaming + tools; do NOT chase the floor (no JSON safety net on streams); step up to GPT-5-mini if tool-call fidelity slips |

Cost (Analysis-tier team/month): Claude-centric $8.28 -> this routing ~$3.09 (**62.7%
saving**) -> Claude-free (GLM-5.2 analysis) ~$0.70 (91.6%, analysis-quality risk).
Prompt caching (60-90% off repeated system prompt + schema) narrows it further; not baked
into the figures.

Plan-tier gating (A5 + F-tier): **Inbox** = statistical flag + cheap triage only, no
analysis/auto. **Analysis (Pro)** = incident analysis on Sonnet 5 (no Opus escalation),
assistant, digest, all on the cheap stack. **Auto (Business)** = + auto-resolve +
auto-promotion + Opus escalation for P1. **Custom/Enterprise** = Opus 4.8 default on
analysis + prompt caching + unrestricted routing. The cheapest tier may run GLM-5.2 on
analysis instead of Sonnet 5 as a cost ceiling. GPT-5.6 is still limited-preview
(2026-07); do not pin production to it yet.

Reliability guardrails (required for the cheaper models, built regardless of choice; NOT
provided by laravel/ai): (1) validate-then-retry loop on invalid JSON; (2) an
owned-signal-ID ALLOWLIST check on every citation and tool call (`check_id`/`metric_key`/
`region` must resolve in the live catalog, else null it); (3) FLATTEN the evidence schema
(avoid deep nesting / `oneOf`, which raises adherence on cheaper models per
JSONSchemaBench); (4) OpenRouter Response Healing for non-streaming JSON repair; (5) gate
each candidate model on its live OpenRouter Tool-Call-Error-Rate before routing prod
traffic. Structured-output trust tier (BFCL 2026-07): Gemini strict + OpenAI strict =
guaranteed; GLM-5.2 / Qwen3.7 Max = GO via a real constrained-decoding host; DeepSeek V4
Flash + Llama 4 = NO-GO for the strict analysis schema.

## 4. Similar-incident search (embeddings)

- Model: **voyage-3.5-lite @ 1024 dimensions, int8** (best retrieval-per-dollar for short
  technical text; text-embedding-3-small @ 512d is the one-fewer-vendor alternative if we
  already call OpenAI). Cost is negligible (~$0.01-0.03/team/month).
- Embed a deterministic, field-normalized template (NOT raw prose) so identical signal
  signatures match regardless of wording:
  ```
  Incident: {title}
  Signal: {metric_key} breached in {sorted_regions}; status={sorted_status_codes}; cadence={bucket}
  Monitors: {monitor_types}
  Severity: {severity}, duration={bucket}
  Root cause: {ai_root_cause_summary}
  ```
- Index: one GLOBAL HNSW `vector_cosine_ops` (m=16, ef_construction=200) + a `team_id`
  btree; set `hnsw.iterative_scan = relaxed_order` so a narrow team/status/time filter does
  not starve the ANN scan (pgvector 0.8+). Query is always team-scoped.
- Re-embed only when a template field changes, gated by a content-hash column (idempotent).
- Fallback (no pgvector on host): `float4[]` + app-side cosine; fine indefinitely for
  team-scoped corpora until a single team crosses ~10-50k incidents.

## 5. Framework (laravel/ai) and what to build

laravel/ai covers, out of the box: `HasStructuredOutput`/`JsonSchema` (typed
root_cause/confidence-enum/evidence-array), `#[Provider]`/`#[Model]` + array-provider
failover (only `FailoverableException`/`RateLimitedException`/`ProviderOverloadedException`/
`InsufficientCreditsException` fail over), `->stream()` (SSE + tool events + `->broadcast()`),
`->queue()` (Horizon), `whereVectorSimilarTo`/`SimilaritySearch` tool, Anthropic prompt
caching via `HasProviderOptions` (`cache_control: ephemeral`), and read-only grounding
tools via the `Tool` contract.

Provider = **OpenRouter** (`Lab::OpenRouter`, native in laravel/ai, incl. embeddings),
with a direct-Anthropic binding in `config/ai.php` as the strict-schema fallback for the
incident-analysis path (A5). Note: broadcasting has a ~10KB WebSocket ceiling, so mark
large tool payloads `#[WithoutBroadcasting(ToolCall::class, ToolResult::class)]`.

We build on top (NOT in the SDK): the `ai_agent_runs` audit table (persist the `Usage`
object: prompt/completion/cache/reasoning tokens + computed cost, via the `AgentPrompted`
event), the per-team budget cap + graceful-degrade middleware, per-task model routing
config, the validate-retry + owned-signal-ID allowlist loop (section 3 guardrails; laravel/ai
does NOT ship JSON-retry or tool-call verification), and the Flutter SSE client for the
assistant (manual line-buffered decode; Flutter has no built-in SSE). Caveat: laravel/ai is
v0.9.0 (0.x); pin the version and watch for breaking changes to 1.0.

## 6. AI budget + gating (per plan)

| Tier | AI surface | Monthly LLM budget/team | Over-budget behavior |
|---|---|---|---|
| Inbox | Statistical flag + cheap triage; no analysis | Near-unlimited (triage is ~$0.001) | N/A |
| Analysis | + incident analysis, assistant, digest | ~150 analyses (deduped first-occurrence-per-incident) | Degrade to statistical-only summary; alert still fires |
| Auto | + auto-resolve + auto-promotion | ~400 analyses | Auto-promotion pauses, suggest-mode continues |
| Custom | Opus/Fable, org pooling, dashboard | Negotiated | Soft alerting cap, never a mid-incident hard stop |

Guardrails: (1) NEVER gate a live incident's alert/notification behind AI budget, only the
LLM narrative; (2) pool budget per team (not per user) so one noisy service cannot starve
the account; (3) show the invocation count + degrade state in-product (visible counter, not
a surprise invoice); (4) size the budget so AI cost stays under ~15-20% of the tier's margin.

## 7. Grounding + honest boundary (guardrails)

- Output is forced through a strict schema; evidence fields are typed as REFERENCES to
  owned-signal IDs (`check_id`, `metric_key`, `region`), never free text.
- Post-generation null-out pass: every cited ID is resolved against the actual signal set
  the model was given; unresolved citations are nulled (not repaired) before persist. This
  is the concrete defense against hallucinated "deploy"-style claims.
- Confidence is a constrained bucket (Strong / Moderate / Insufficient), tied to countable
  evidence: "Strong" requires cross-region + cross-monitor confirmation; "Insufficient"
  triggers refusal.
- Require BOTH evidence-for and evidence-against before a verdict (graduated trust); an
  empty "against" list is itself surfaced, not silently accepted.
- Refuse over guess: on ambiguous signals (single flaky check, no corroboration) the output
  is "insufficient signal to conclude", never a synthesized cause. Advisory actions only
  ("check your origin capacity"), never a one-click "Apply".
- Optional groundedness judge: a cheap second-model pass scores factual grounding against
  the raw signal payload before surfacing.

## 8. AI-UX patterns (borrowed, honest-boundary-scoped)

Anomaly inbox (approve/dismiss, AI-origin styling); autonomy dial mapping to
Inbox/Analysis/Auto/Custom (Observe+Suggest -> Plan+Propose -> Act-with-Confirmation ->
Act-Autonomously); suggestion-to-incident promotion; confidence as an icon + rationale (not
a bare %); a dismissed-anomaly feedback loop that actually suppresses that pattern going
forward (not a write-only table); weekly digest for low-confidence/batched anomalies with
real-time interrupts reserved for high-confidence/high-severity; escalate-over-guess when a
signal is missing. What NOT to do: never claim deploy/log/trace-level causes we cannot see;
never meter AI (the bill-shock-during-outage trap).

## 9. Schema deltas (added to db-design.md)

- **monitors**: anomaly-state columns (`ewma_value`, `ewma_var`, `cusum_pos`, `cusum_neg`,
  `baseline_updated_at`) for O(1) incremental detectors.
- **anomaly_candidates**: id, monitor_id, team_id, signal, method, score, severity, window,
  evidence jsonb, region_votes jsonb, quorum, consecutive_confirmations, dedupe_key
  (unique), status (candidate/triaged/promoted/dismissed), created_at. Hypertable-eligible.
- **ai_agent_runs**: + `usage` jsonb (prompt/completion/cache/reasoning tokens), `cost_usd`,
  `model`, `provider`, `task`.
- **ai_budget_usage**: team_id, period, task, invocations, cost_usd (drives the quota +
  graceful-degrade). Incidents/analyses/suggestions/evidence tables as in db-design.md; the
  incident `embedding` is voyage 1024d with the HNSW cosine index above.

## Still to detail when building

Exact anomaly parameter defaults per metric type; the Python sidecar interface (job schedule
+ threshold write-back contract); the OpenRouter integration + the grounded read-only tool
set for the assistant; the groundedness-judge prompt; prompt-cache segmentation of the system
prompt; and the budget-reset/period accounting.

Analysis-default is LOCKED to **Sonnet 5** (with Opus 4.8 P1 escalation; GLM-5.2 as the
low-tier cost ceiling + strict-path fallback). Action item before launch: run a PILOT EVAL of
Sonnet 5 vs GLM-5.2 on a corpus of real (or realistic) incidents, scoring grounding fidelity +
narrative quality, to confirm whether GLM-5.2 is good enough to become the broader default and
save the ~$2-3/team/month. The routing is config-driven, so the default flips with one edit.
