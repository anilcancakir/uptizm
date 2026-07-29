# Custom metrics: QA case set

## What the feature is (from the code, not the docs)

A **custom metric** is an operator-defined value pulled out of a monitor's own
check response, stored per check, banded against thresholds, and charted.

Each metric owns:

| Field | Meaning |
|---|---|
| `label` | display name (required, <=120) |
| `key` | machine name, `^[a-z][a-z0-9_]*$`, <=40, **unique per monitor** |
| `type` | `numeric` / `status` / `string` |
| `source` | `json_path` / `regex` / `xpath` / `header` / `http_status` |
| `extraction_path` | the JSON path, regex, XPath, or header name (<=500) |
| `unit` | one of 16 backend units (`millisecond`, `percent`, `byte`, ...) |
| `threshold_direction` | `high_bad` / `low_bad` |
| `warn_bound`, `critical_bound` | numeric band edges |
| `display_order` | ordering within a group |

**Pipeline:** every check result flows through
`CheckPersistenceService::extractAndPersistMetrics`, which runs each configured
metric through `MetricExtractor` against the response **body preview**, the
response headers, and the status code, then writes a `MonitorMetricValue` with
its band **frozen at insert time** (so later threshold edits never rewrite
history) and feeds the numeric samples to `ThresholdEvaluator`.

**Load-bearing constraint:** the body is truncated to
`BODY_PREVIEW_BYTES = 10_240` in the Cloudflare worker
(`workers/regional-checker/src/regional-probe.ts:56`). Anything past 10 KiB is
invisible to extraction.

**Endpoint inventory (8), and what the client reaches (5):**

| Endpoint | Reached from the UI? |
|---|---|
| `GET .../metrics` | yes |
| `POST .../metrics` | yes |
| `PUT .../metrics/{metric}` | yes |
| `DELETE .../metrics/{metric}` | yes |
| `PUT .../metrics/reorder` | **no** (client method exists, no UI calls it) |
| `POST .../metrics/preview` | **no** |
| `GET .../metrics/{metric}/series` | **no** |
| `GET .../metrics/series` (batch) | **no** |

## Test fixture

`GET /api/v1/public/fixtures/random` is a purpose-built target covering every
source in one response:

```json
{ "status":"up", "status_code":500, "healthy":false,
  "data": { "latency_ms":64, "active_users":120,
            "database": {"size_mb":1440.21, "connections":17},
            "cache": {"hit_rate":0.41} },
  "notes":"service stable, active: 120, build #8565",
  "xml":"<response><status>up</status><latency>64</latency></response>" }
```
Headers: `X-Response-Time: 821`, `X-Cache: STALE`, `X-Request-Id: <uuid>`.

Note the trap: `status_code: 500` is a JSON FIELD while the real HTTP status is
`200`, so an `http_status` metric must report 200, not 500.

---

## Cases

### A. Definition CRUD (CM-CRUD)

| ID | Case | Verify |
|----|------|--------|
| CM-1 | Create a numeric json_path metric | row persisted; `GET .../metrics` returns it; wire `source == json_path` |
| CM-2 | Key must match `^[a-z][a-z0-9_]*$` | `Latency`, `1st`, `a-b` rejected 422 on `key`; `latency_ms` accepted |
| CM-3 | Key is unique per monitor | second metric with the same key -> 422 on `key` |
| CM-4 | The same key on a DIFFERENT monitor is allowed | uniqueness is scoped to `monitor_id`, not global |
| CM-5 | Editing a metric may keep its own key | `unique` rule ignores self, so a label-only edit does not 422 |
| CM-6 | Edit persists every changed field | `PUT` then re-read: label/path/unit/bounds/direction all updated |
| CM-7 | Delete removes it | subsequent `GET .../metrics` omits it |
| CM-8 | `label` required, `type` required | omitting either -> 422 |
| CM-9 | Unknown enum values rejected | `source: 'telepathy'`, `unit: 'furlong'`, `type: 'vibes'` -> 422 |
| CM-10 | Cross-team metric access is masked 404 | another team's monitor/metric -> 404, never 403 or data |
| CM-11 | A metric id belonging to another monitor is rejected | `PUT /monitors/A/metrics/{B's metric}` -> 404 |

### B. Extraction correctness (CM-EXT) - the heart of the feature

| ID | Case | Verify |
|----|------|--------|
| CM-12 | `json_path` extracts a nested number | `$.data.latency_ms` -> 64 |
| CM-13 | `json_path` extracts a float and a ratio | `$.data.database.size_mb` -> 1440.21; `$.data.cache.hit_rate` -> 0.41 |
| CM-14 | `json_path` on a missing key records NOTHING | no `monitor_metric_value` row, and no `0` invented |
| CM-15 | `regex` captures a group | `build #(\d+)` -> 8565 |
| CM-16 | `regex` that matches nothing records nothing | no row |
| CM-17 | `header` extraction is case-insensitive | `x-response-time` and `X-Response-Time` both -> 821 |
| CM-18 | `header` for an absent header records nothing | no row |
| CM-19 | `http_status` reports the REAL status, not a body field | 200, NOT the `status_code: 500` in the payload |
| CM-20 | `xpath` against a JSON body fails cleanly | no row, no exception, monitor still checks fine |
| CM-21 | `type: status` / `type: string` store in their own columns | `status_value` / `string_value` populated, `numeric_value` null |
| CM-22 | A numeric metric pointed at a non-numeric value is a type mismatch | no row (typeValid false), not a silent 0 |
| CM-23 | Extraction beyond the 10 KiB body preview fails | a path only present past 10240 bytes records nothing |
| CM-24 | One failing metric does not block its siblings | 3 metrics, 1 broken -> the other 2 still record |
| CM-25 | Extraction failure never fails the CHECK itself | monitor `last_status` still resolves from the HTTP result |

### C. Thresholds and banding (CM-BAND)

| ID | Case | Verify |
|----|------|--------|
| CM-26 | `high_bad`: value >= critical bands critical | stored `band` is critical |
| CM-27 | `high_bad`: warn <= value < critical bands warn | stored `band` warn |
| CM-28 | `low_bad` inverts the comparison | a LOW value bands critical |
| CM-29 | The band is frozen at insert | editing bounds afterwards does NOT rewrite historical rows |
| CM-30 | A metric with no bounds records a value with no band | null band, not a fabricated "ok" |

### D. UI honesty (CM-UI)

| ID | Case | Verify |
|----|------|--------|
| CM-31 | The metrics tab lists real persisted metrics | equals `GET .../metrics` |
| CM-32 | The tab's current value is the real latest reading | equals `latest.numeric_value` |
| CM-33 | A metric with no readings yet shows no value, not `0` | honest empty/pending, never a fabricated number |
| CM-34 | **The extraction test panel must report a real extraction** | it must call `POST .../metrics/preview` and show what the backend actually extracted |
| CM-35 | **The detail chart must be the metric's real history** | must equal `GET .../metrics/{metric}/series` |
| CM-36 | The detail's "latest value" agrees with the tab | one source of truth |
| CM-37 | An anomaly marker appears only for a real anomaly | never injected at a fixed index |
| CM-38 | Unit round-trips through save + reload | pick Custom -> reload still Custom |
| CM-39 | Reordering metrics is possible and persists | display_order survives reload |
| CM-40 | 422 field errors render inline on the offending field | key/path errors land on their own field |

### E. Cross-cutting (CM-X)

| ID | Case | Verify |
|----|------|--------|
| CM-41 | No overflow / exceptions on the metrics tab, form, detail at 1440 / 390 | clean |
| CM-42 | Deleting a monitor removes its metrics and values | no orphans |
| CM-43 | Deleting a metric leaves history queryable or removes it deliberately | documented either way, not a crash |
| CM-44 | TR locale renders no raw i18n key on any metric surface | no `uptizm.` visible |

---

# Results (2026-07-29, live stack)

Executed against a purpose-built monitor (`Metrics Lab`) pointed at
`/api/v1/public/fixtures/random`, with 14 metrics covering every source, driven
through real `POST /monitors/{id}/test` checks and verified in the database.

## Passed

| Cases | Result |
|---|---|
| CM-1..CM-11 | **All definition CRUD, validation and scoping correct.** Key regex, per-monitor uniqueness, `unique` ignoring self on edit, same key allowed on another monitor, required label/type, all three unknown-enum rejections (`type`+`source`+`unit` reported together), cross-monitor metric id masked 404. |
| CM-15..CM-22, CM-24, CM-25 | **Extraction semantics are sound.** `regex` captured `build #(\d+)` -> 2936. `header` is case-insensitive (`X-Response-Time` and `x-response-time` both -> 185). `http_status` reported the REAL status (500/200) and never the body's decoy `status_code`. Every failure path recorded **no row at all**: missing json key, non-matching regex, absent header, xpath against a JSON body, and a numeric metric aimed at a string. One broken metric never blocked its siblings, and no extraction failure affected the check verdict. |
| CM-21 | `status` and `string` metrics land in their own columns (`status_value='down'`, `string_value='ap'`), numeric null. |
| CM-26..CM-30 | **Banding correct and frozen.** `high_bad` 4342.83 vs (1000/2000) -> critical; `low_bad` 0.97 vs (0.8/0.5) -> ok; a metric with no bounds stored a value with a **null** band, not a fabricated "ok". Widening the bounds afterwards left the historical row's band untouched. |
| CM-31, CM-32 | The tab lists the real metrics and their real latest readings (2936 / 0.97 / 4342.83 / 500 all matched the DB). |

## Failed

| ID | Sev | Defect |
|----|-----|--------|
| **CM-12/13** | **P0** | **A `$.`-prefixed JSON path silently never extracts, and the UI prescribes exactly that format.** The extractor is `Arr::get($decoded, $path)` with no prefix handling, so it wants bare dot notation (`data.latency_ms`). The path field's placeholder is `$.system.memory.used_pct`, the source is labelled "JSON path", and the client's own preview resolver strips `^\$\.?`. Measured: `$.data.latency_ms` -> `No value at path`; `data.latency_ms` -> `1197`. Wildcards (`data.items.*.value`) also fail; array indices (`data.items.0.value`) work. Every json_path metric created by following the UI produces nothing, forever, with no error surfaced anywhere. |
| **CM-34** | **P0** | **The "Test extraction" panel is simulated and fabricates success.** Its copy promises "Fetch a live sample to verify this rule resolves a value", but `_runTest()` is an 800ms `Future.delayed` with no network call; it resolves the path against a hardcoded `kMetricSample` map and falls back to a constant per unit. Live proof: for `$.system.memory.used_pct`, a path absent from the monitor's real response AND in the format the backend rejects, the panel reported **"RESOLVED / 73.4 %"**. For non-json sources `_found` is hardcoded `true`, so any regex/xpath/header rule always reports found. A real `POST .../metrics/preview` endpoint exists and is never called. |
| **CM-35/36/37** | **P0** | **The metric detail sheet is entirely synthetic.** `chartData()` generates 24 points as `base + sin(i/3) * base * 0.18`; an anomaly is **injected at a fixed index 17**; `latestOf()` reads the last fake point. Live proof on `cache_hit` (3 real readings, real latest 0.97): the sheet showed latest **1.1**, a full 24-hour hourly series all reading 1.1, a "RECENT READINGS" list of invented hourly rows, and the claim *"Shaded band is Uptizm's learned expected range. Cache hit stepped outside it once in the last 24h (at 17:00), dropping below its normal range."* The tab (real 0.97) and the detail (fake 1.1) contradict each other. A real `GET .../metrics/{metric}/series` endpoint exists and is never called. |
| **CM-AI** | **P0** | **The threshold suggestion claims a measured baseline for a metric with no data.** On a brand-new metric the form asserts *"This metric typically reads near 73.4 %. Based on that baseline, Uptizm suggests warn at 84 and critical at 95."* `73.4` is `fallbackValue('%')`, a constant. The product states a measurement it has never taken and derives recommendations from it. |
| **CM-33** | P1 | **A metric that has never extracted renders `0`.** `MonitorMetricRecord.fromMap` defaults `latest.numeric_value` to `0`. Live: `Ghost` and `Absent hdr`, both with zero recorded values, displayed `0` on the tab. For a latency or error-count metric `0` reads as perfect health, and combined with CM-34 the operator has no way to notice the rule is dead. |
| **CM-38** | P2 | **Unit round-trip corrupts "Custom".** `_unitToWire` maps both `req_s` and `custom` to the backend's `custom`, and `_unitFromWire` returns the first match, so a metric saved as Custom reloads as **req/s**. The backend has no `req_s` unit at all, so req/s is unrepresentable. |
| **CM-39** | P2 | **Reordering is unreachable.** `PUT .../metrics/reorder` and `MonitorMetricsController.reorder` both exist; no UI calls either. |

## Not covered

CM-10 (cross-team monitor, only the cross-monitor half was exercised), CM-14
was verified for a missing key but not for the 10 KiB boundary (CM-23), and
CM-42..CM-44 (cascade delete, history retention on metric delete, TR locale).

## Verdict

The **backend half of this feature is solid**: extraction, typing, failure
handling and band freezing all behave correctly and honestly. The **client half
is a design-lab mock wired to a real list**: definitions save for real, but the
two screens an operator uses to decide whether a rule works (the extraction test
and the metric detail) are fabricated, and the one format the UI tells them to
type is the one format the backend cannot read. As shipped, a user following the
UI cannot successfully configure a json_path metric, and would be told they had.

---

# Fixes (2026-07-29) and their live verification

Every fix carries a regression test that fails without it, and each was then
re-verified against the running stack.

| ID | Fix | Live proof |
|----|-----|------------|
| CM-12/13 | `MetricExtractor::extractJsonPath` now strips the conventional JSONPath root, so `$.a.b`, `$a.b` and `a.b` all address the same value. Only the LEADING root is removed, so a payload with its own key named `$` (`meta.$.id`) stays addressable. | a real check extracted `$.data.latency_ms` -> 376, `$.data.database.size_mb` -> 2623.51, `$.status` -> `down` into the status column. Previously every one of these recorded nothing. |
| CM-34 | The form's "Test extraction" panel calls `POST .../metrics/preview` for real. The backend applies the draft rule to the monitor's most recent check (the same payload the pipeline itself ran on) and reports `has_sample` / `sample_checked_at` / `sample_status_code`, so the panel names its evidence instead of implying it fetched the endpoint. The local `resolveJson` / `fallbackValue` / `kMetricSample` path is gone from the form, and there are now four distinct verdicts: resolved, wrong type, resolved nothing, and no sample to test against. | for `$.system.memory.used_pct` (absent from the monitor's response) the panel now reads **"No value at path `$.system.memory.used_pct`." / "Verified against the check from 5 minutes ago (HTTP 500)"**. It previously read **"RESOLVED / 73.4 %"**. For a real path it reads RESOLVED with the extracted value and the same provenance line. |
| CM-35/36/37 | The detail sheet fetches `GET .../metrics/{metric}/series` on mount and renders one of three honest states: loading, no readings yet, or the real series. `chartData`/`latestOf`, the fabricated "learned expected range" multipliers, the anomaly injected at index 17 and the insight narrating it are all deleted. The latest reading is banded by the band the backend FROZE when it was recorded. | for `latency_ms` the sheet now shows latest **376** and readings 376 / 1126 / 749 / 891 / 198, matching the six database rows exactly. It previously showed latest **1.1**, a 24-point hourly series all reading 1.1, and the claim "stepped outside it once in the last 24h (at 17:00)". |
| CM-AI | The threshold suggestion is offered only after a test has measured a real value, and is derived from that value. | zero occurrences of "typically reads near" before a successful test; after one, the suggestion quotes the measured value. It previously asserted "typically reads near 73.4 %" (a constant) for a metric with no data. |
| CM-33 | A metric with no reading renders an em-dash. `MonitorMetricRecord` now also carries the latest status/string/band, and the row renders those instead of the LITERAL words "operational" / "ok" that every status / string metric used to display. | `Ghost` and `Absent hdr` now read **—** (were `0`); `Svc status` reads **down** and `Region` reads **us-east**, both matching the database. A status metric reading `down` previously displayed as "operational". |
| CM-38 | The unrepresentable `req_s` unit option is removed (the backend `MetricUnit` enum has no throughput unit), so Custom round-trips as Custom instead of decoding back as "req/s". | - |

## Still open

| ID | Sev | Finding |
|----|-----|---------|
| CM-39 | P2 | Reordering metrics is still unreachable from the UI. `PUT .../metrics/reorder` and the client's `reorder()` both exist and are tested; no screen calls them. Left as a missing surface rather than removed, because `display_order` is already honoured by the index ordering. |
| CM-23 | - | The 10 KiB body-preview boundary is still unexercised: a path present only past `BODY_PREVIEW_BYTES` should record nothing. |
| CM-42/43/44 | - | Cascade delete of a monitor's metrics and values, history retention when a metric is deleted, and the TR locale pass over the metric surfaces. |

## Gates

- `flutter analyze` clean; client suite **986** passing.
- `php artisan test` **586** passing, 1693 assertions; `pint --test` clean.
