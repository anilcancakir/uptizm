# Live pass 3 (2026-07-29): production-readiness QA

Run on a `migrate:fresh --seed` database against the full live stack (Laravel
`:8000`, Reverb `:8080`, Cloudflare checker `:8787`, Redis + queue worker,
Flutter web `:3100`), driven through Chrome with `fluttersdk_dusk` at 1440x900,
1024x800, 768x1024 and 390x844.

Method for every user-facing control, applied uniformly:

1. **Fabricated options** - are the control's options/rows sourced from
   `lib/app/mocks/*` instead of the API?
2. **Dropped write** - does the value reach the backend? A key absent from the
   matching `FormRequest::rules()` is silently discarded by `validated()`.
3. **Dead control** - does the control call nothing, or does a real endpoint
   exist that no client code calls?

## Verified working

| Area | Evidence |
|------|----------|
| Static gates | `flutter analyze` clean; 960 client tests; 573 backend tests / 1657 assertions |
| X-1 no uncaught exceptions | 23 routes x 4 viewports = 92 visits, `dusk:exceptions` empty throughout |
| X-2 no overflow | zero render overflow at 1440 / 1024 / 768 / 390 |
| Responsive shell | sidebar shell above `lg`; at 390 the mobile chrome + bottom tab bar (Home/Monitors/Incidents/Status) |
| AUTH-1 | unauthenticated boot lands on Sign In, no shell chrome |
| AUTH-2 | login stores the Sanctum token and lands on the dashboard |
| DASH-2 | every KPI equals `/dashboard/stats` exactly (checked twice, incl. after a live data change) |
| DASH-3 | `uptime_24h_delta: null` renders nothing, never a fabricated `0` |
| DASH-4 | fleet banner names the actual down monitors, never "all operational" |
| X-9 realtime | dispatching `MonitorStatusChanged` refetched the dashboard within ~8s to match the API exactly |
| Region catalog parity | client `allRegions` == backend `MonitorRegion` (us-east, us-west, eu-west, eu-central, ap) |
| SSRF guard | `POST /monitors` with a loopback URL is rejected 422 "The url host is not allowed." |
| OPS-15 | on-call renders an honest empty state, no fixture responders |
| OPS-10 | escalation list shows the team's real policy |
| CHAN-8 | "Push not yet configured" renders from the backend flag |
| STAT-11 | the real public page at `/s/acme` is correct and honest ("Major System Outage", all 3 components with uptime) |
| No fabricated people | none of the fixture names (Ada Lovelace, Mara Pohl, Ravi Shah) reaches any screen |
| On-call / escalation / channels | audited end to end for all three failure modes above: clean |

## Defects found

### P0

| ID | Defect | Evidence |
|----|--------|----------|
| L3-1 | **The monitor form's escalation-policy control is fabricated AND non-functional, and it governs paging.** Options come from the `escalationPolicies` fixture (`lib/app/mocks/oncall.dart`), so the form offered "Standard" / "Critical path" to a team whose real policy count was 0. `buildFields()` never includes `escalation_policy_id`; neither `StoreMonitorRequest` nor `UpdateMonitorRequest` validates it, so it is dropped even when sent explicitly; and `MonitorResource` never emits it, so an edit cannot round-trip. Meanwhile `EscalationDispatcher::resolvePolicy()` reads `monitors.escalation_policy_id` to decide who gets paged and silently falls back to the team's earliest-created policy. | POST with a valid same-team policy id -> `200`, DB `escalation_policy_id = NULL`. `backend/app/Services/OnCall/EscalationDispatcher.php:101` reads the column. Client: `lib/resources/views/monitors/monitor_form.dart:122,470`, payload `:713-735`. |
| L3-2 | **Status-page components can never be configured from the UI.** The editor's Components picker is built from the fixture monitor list via `monitorRegions()`, so it offered "Marketing site / API gateway / Checkout service / Docs" to a team whose real monitors are "API / Website / Checkout". The selection is posted as `monitors:[{id}]`, a key neither `StoreStatusPageRequest` nor `UpdateStatusPageRequest` validates, so it is discarded. The real `attach`/`detach`/`reorder` endpoints exist and `StatusPageController` (client) even implements them, but the editor never calls them. Because the option values are fixture ids while `_monitorIds` holds real UUIDs, nothing renders as checked either, so the editor also misreports the current configuration. | `PUT` with fixture ids -> `200` and `"monitors":[]`, no error. Client: `lib/resources/views/status/status_form_support.dart:47-51`, `status_page_editor_view.dart:281-282`. Backend: `StatusPageController::attachMonitor:118`. |
| L3-3 | **The status-page list and in-app preview show a false all-clear during a live outage.** Both build components through the fixture resolver `componentsFor()`, which looks real monitor UUIDs up in the fixture list, always misses, and returns an empty list. `StatusBadge(worstStatus([]))` then renders "Operational". With Website and Checkout both `down`, the list read "0 components" + "Operational" and the preview read "No components yet" + "All systems operational", while the real customer-facing page at `/s/acme` correctly read "Major System Outage". The operator-facing surface contradicts the customer-facing one, in the reassuring direction. | `lib/resources/views/status/status_pages_list_view.dart:163,193`; `lib/ui/components/status_page_preview/status_page_preview.dart:54-55`; `lib/app/mocks/status_pages.dart:126-141`. |
| L3-4 | **The incident update composer silently publishes internal notes.** The composer collects a lifecycle `status` and a publish switch, but `postUpdate` posts only `{message}`. The backend validates both and resolves the missing key as `isPublic: (bool) $request->validated('is_public', true)` - defaulting to **public**. So an operator who turns the switch off to write an internal note has it published to the public status page, and a selected status change is discarded. | Client: `lib/app/controllers/incident_controller.dart:529-532`. Backend: `IncidentController::postUpdate:218`, `PostIncidentUpdateRequest:36-43`. |

### P1

| ID | Defect | Evidence |
|----|--------|----------|
| L3-5 | **No view refetches on route re-entry, so screens serve stale data with no staleness indicator.** magic caches controllers as Type-keyed singletons and runs `onInit` once per instance lifetime. Creating a monitor then navigating away and back left the list at 4 rows with the new "Staleness Probe" absent; the dashboard held `1 / 3`, `65.08%`, `20ms` while the API served `2 / 4`, `63.38%`, `75ms`. Aggregates only refresh when a broadcast happens to fire (a status transition or incident open/resolve), which in a healthy fleet can be hours. | measured directly; `lib/app/controllers/dashboard_controller.dart:125-147`. |
| L3-6 | The status-page editor's System/Custom metric pickers are built from fixture metrics keyed by fixture monitor ids, so they are empty or wrong for real monitors. | `lib/resources/views/status/status_form_support.dart:55-72`, `lib/app/mocks/status_pages.dart:166-180`. |
| L3-7 | The billing view seeds `_currentPlanId` from the `currentPlanId` fixture (`'pro'`), and that seeded value gates the CTA label and the upgrade/downgrade arrow direction. A Free user therefore sees the Pro card labelled "Current plan" until the real read resolves. | `lib/resources/views/teams/plan_billing_view.dart:133,879,895`. |
| L3-8 | `/settings/changelog` presents an invented release history (2.4.0, 2.3.0, 2.2.0 with specific June 2026 dates and features) as the product's real changelog. No backend endpoint exists to serve one. | `lib/resources/views/settings/changelog_settings_view.dart:97`, `lib/app/mocks/settings.dart:364-427`. |

### P3

| ID | Defect | Evidence |
|----|--------|----------|
| L3-9 | Four production widgets import `lib/app/mocks/*` purely so a docblock can link a fixture symbol (`[recentChecks]`, `[uptime90]`). Harmless at runtime, but it keeps production code compile-coupled to the design-lab fixtures. | `check_history_table.dart:5`, `uptime_bar.dart:5`, `monitor_detail_view.dart:18`, `incident_form_support.dart:10`. |

### Found while fixing

| ID | Severity | Defect | Evidence |
|----|----------|--------|----------|
| L3-11 | **P0** | **Editing a monitor silently overwrote its configuration.** `MonitorEditView` passed only name/url/regions, so the form filled everything else with CREATE defaults, and Submit posts the form's COMPLETE field map. Renaming a monitor therefore reset `method` (head -> get), `check_interval_sec` (600 -> 30), `timeout_sec` (45 -> 30) and `slo_target` (99.99 -> 99.9), and replaced the operator's real `X-Api-Key` header with the literal placeholder `Authorization: Bearer …`, which then went out to their endpoint on every probe. | reproduced against the live API: PUT the exact payload a rename builds, then re-read the monitor. |
| L3-12 | **P0** | **The AI-assist toggle was dead on BOTH sides, so the AI pipeline never ran for any UI-created monitor.** `ai_mode` was missing from the client model's `fillable` (stripped before the request) AND unvalidated by both monitor FormRequests (dropped by `validated()`). Meanwhile `SweepAiSuggestions` selects the fleet with `whereIn('ai_mode', ['suggest','auto'])` and `TriageAnomalyCandidate` gates on `AiMode::Suggest`. Every monitor stayed at the `off` default while the UI showed the operator they had enabled it, and AI setups are a metered, plan-gated feature. | `grep` of both request classes + the client fillable; red-phase feature tests. |
| L3-13 | P1 | **A second, undocumented gate on public visibility.** `StatusPageAssembler` filters public components on `monitors.show_on_status_page`, a flag no screen exposes. An attached monitor with the flag false appeared in the in-app preview and the list but never on the real public page. | live: a 4th attached component rendered in-app while `/s/acme` showed 3. |
| L3-14 | P2 | `monitors.escalation_policy_id` is declared `$table->uuid(...)` while `escalation_policies.id` resolves through `MigrationHelper::primaryKey()`. Under `use_uuids => false` the reference column type would not match the key it points at. Not exercised by the shipped config (`use_uuids => true`), so FLAGGED, not changed: editing a landed migration mid-QA is riskier than the latent mismatch. | `database/migrations/2026_07_11_000001_create_monitors_table.php:59`. |

## Fixes and their live verification

Every fix carries a regression test that fails without it (red phase run and
recorded), and every one was then re-verified against the running stack.

| ID | Fix | Live proof |
|----|-----|------------|
| L3-1 | Escalation select now reads the team's real policies (`EscalationController`, explicitly loaded because magic fires `onInit` only for a view's BACKING controller) with a "Team default" sentinel for the null pin; `escalation_policy_id` is posted, validated team-scoped on both requests, emitted by the resource, and added to the client `fillable`. | the select offered exactly "Team default" + the team's one real policy; saving stored the real id, resolving to `QA Critical Ladder`. |
| L3-2 | The editor's Components picker is built from the real monitor roster, and assignment now travels over the `attach`/`detach`/`reorder` pivot endpoints (`_syncComponents`, which no-ops when the set and order are unchanged) instead of a `monitors` key the backend discards. | picker listed the team's 6 real monitors; adding one and saving produced 4 pivot rows in correct `display_order`. |
| L3-3 | `StatusPage.components` reads the eager-loaded pivot (name, live status, order, and the `show_on_status_page` gate); `worstStatus` returns null for an empty list instead of `up`, and both the list badge and the preview banner render the absence. | list read "Major outage · 3 components" (was "Operational · 0 components"); preview rendered the 3 real components with per-component status. |
| L3-4 | `postUpdate` sends `is_public` explicitly (the backend resolves an absent key as **true**) and the composer's selected status. | an update posted with the publish switch off stored `is_public: false`, `status: monitoring`, and does not appear on `/s/acme`. |
| L3-5 | New `RefetchesOnMount` mixin on the six primary views plus the four detail/editor views, so re-entering a route refetches. | the edit form prefilled the monitor's real 180s / 99.99% on FIRST entry, where before it needed a list visit to warm the cache. |
| L3-6 | The status-page metric pickers and the AI draft's preset metric keys are removed: they resolved fixture metrics by fixture monitor id, and nothing renders published metrics now. | editor no longer shows a Metrics card. |
| L3-11 | The edit view seeds every field the form owns from the monitor; `isEdit` stops the form posting defaults for settings it exposes no control for; the interval option set gained `3m` (the Free floor, and every seeded monitor's value) and an off-list interval round-trips verbatim rather than snapping. | one save through the UI preserved method `head`, interval 180, timeout 45, SLO 99.99 and the real `X-Api-Key` header. |
| L3-12 | `ai_mode` added to the client `fillable` and validated on both requests. A new contract test asserts EVERY key the form posts is present in `Monitor.fillable`, which is the check that would have caught both this and L3-1. | red-phase feature tests now green; the parity test fails if the two lists drift again. |
| L3-13 | The pivot payload carries `show_on_status_page` and the client filters on it, so the in-app surfaces match the public page exactly. | in-app list showed 3 components for a page with 4 attached, matching `/s/acme`. |
| L3-7 | The billing view no longer seeds the current plan from a fixture: `_currentPlanId` starts null, and while unresolved no card claims to be the current plan and no card claims an upgrade or downgrade direction. | - |
| L3-8 | `/settings/changelog` renders an honest empty state instead of an invented release history. | - |
| L3-9 | The four production widgets no longer import `lib/app/mocks/*`; their docblocks describe the shape instead of linking a fixture symbol. Also deleted `monitorsToRegions()`, a fixture-iterating helper with no production caller. | - |

### Gates after the fixes

- `flutter analyze`: clean. Client suite: **974** passing.
- `php artisan test`: **578** passing, 1673 assertions. `pint --test`: clean.
- 23 routes x 4 viewports (1440 / 1024 / 768 / 390): zero render overflow, and
  zero exceptions other than the known non-fatal initial-route notice (P2-O1).

### Still open

| ID | Severity | Finding |
|----|----------|---------|
| L3-14 | P2 | The `escalation_policy_id` column type mismatch under `use_uuids => false` (above). Flagged, not changed. |
| P2-O1 | low | Flutter's non-fatal "Could not navigate to initial route" on a deep-link web load. Cosmetic, but it pollutes the exception buffer during a QA pass. Carried over from pass 2; emitter still unidentified. |
| - | low | The in-app status-page preview no longer renders a live-metrics grid or a past-incidents list. Both were fabricated and were removed rather than wired (a deliberate scope decision); the authoritative view is the backend-rendered `/s/{slug}`, linked from the editor. Restoring them against the real endpoints is follow-up work. |
| - | low | `show_on_status_page` still has no UI. The client now honours it, so nothing over-promises, but an operator cannot change it from the product. |

## False leads, and what caused them

Recorded so they are not re-investigated:

1. **"The dashboard never refreshes."** It does, but only on a broadcast. The
   first stale reading was real (L3-5) yet the realtime path itself is healthy:
   dispatching `MonitorStatusChanged` by hand refetched within ~8s. Two
   separate mechanisms, one broken and one working.
2. **"`monitors` is not accepted on status-page create."** True, but not a
   regression: components are deliberately a pivot sub-resource with their own
   attach/detach/reorder endpoints. The defect is the client using the wrong
   mechanism (L3-2), not a missing backend field.
3. **Controller methods that share a fixture helper's name** (`subscribersFor`,
   `metricsFor`, `activeIncidents`, `aiSuggestions`) are real API-backed
   methods. Only the `lib/app/mocks/*` symbol of the same name is a defect.
4. **Doc-comment references count as import usage** in Dart, so `flutter
   analyze` stays clean on a file whose only tie to the fixtures is a
   `[symbol]` docblock link. Unused-import cleanliness does not prove fixture
   independence.

5. **The Components picker looked unresponsive to automation.** `RegionPicker`
   makes the whole tile a `WAnchor` and renders the checkbox inside an
   `IgnorePointer` with `onChanged: null`, so `dusk:set_checkbox` cannot drive it
   and the nested `e`-refs from `dusk:snap` miss the hit target. A `q`-ref from
   `dusk:find` resolves the interactive node and toggles correctly. Use
   `dusk:find` for interaction; treat a silent `dusk:tap` on a snapshot ref as a
   harness miss, not as a dead control.
6. **Over-hammering one form corrupts its state.** Two rapid `dusk:fill` calls
   left the monitor form reporting "Name is required." for a field that visibly
   held text. Filling with a short pause and asserting each value took before
   moving on is reliable.
