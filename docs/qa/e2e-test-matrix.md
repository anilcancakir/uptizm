# Uptizm E2E QA test matrix

Live end-to-end acceptance matrix, driven through Chrome with `fluttersdk_dusk`
against the real stack (Laravel API on `:8000`, Reverb on `:8080`, Cloudflare
checker worker on `:8787`, Redis + queue workers, Flutter web on `:3100`).

Every case is verified twice: once through the UI (what the operator sees) and
once against the source of truth (`api/v1` or the database). A case passes only
when both agree. A UI that renders a value the backend does not have is a
failure, not a pass, because the product's own honesty rules forbid it
(`docs/uptizm-system/ai-design.md`, `DESIGN.md`).

## Conventions

- **ID**: stable case id, referenced by commits and by the results log.
- **Verify**: the assertion made against the source of truth, not the screen.
- Status values: `PASS`, `FAIL`, `BLOCKED`, `N/A (deferred)`.
- Two accounts exist locally: `demo@uptizm.test` (seeded data) and the
  developer's own account (empty team). The empty team is the primary QA
  subject, because the empty-to-populated path is what a new customer walks.
- Free plan caps are `1` monitor / `1` status page / `1` responder / 3-day
  history / 3 metered AI setups. Breadth cases that need more than one of a
  capped resource lift the team's plan for the duration of the case and restore
  `free` afterwards; the cap itself is asserted separately (see `PLAN-*`).

---

## A. Authentication and shell (AUTH)

| ID | Case | Verify |
|----|------|--------|
| AUTH-1 | Unauthenticated boot redirects to login | no in-app shell chrome renders; login form present |
| AUTH-2 | Login with valid credentials lands on the dashboard | Sanctum token stored; `GET /dashboard/stats` returns 200 |
| AUTH-3 | Login with wrong password shows a field error, not a crash | no navigation; error text visible; no uncaught exception |
| AUTH-4 | Revoked/expired token does not leave a half-authenticated shell | app returns to login rather than rendering empty panels as if healthy |
| AUTH-5 | Logout clears the session and returns to login | token revoked server-side; back-navigation cannot re-enter the shell |
| AUTH-6 | Reload preserves the session (Vault restore) | dashboard renders without a second login |
| AUTH-7 | Deep link to a gated route while unauthenticated redirects to login | after login the user lands on the requested route or the dashboard, never a blank shell |

## B. Dashboard (DASH)

| ID | Case | Verify |
|----|------|--------|
| DASH-1 | Zero-monitor team shows the onboarding hero, not a zeroed KPI grid | `monitors_total == 0`; hero CTA navigates to `/monitors/new` |
| DASH-2 | KPI values equal `GET /dashboard/stats` exactly | each rendered number matches the payload field |
| DASH-3 | No-data KPI renders an em-dash placeholder, never `0` or `100%` | `uptime_24h == null` -> `—` on screen |
| DASH-4 | Fleet summary never claims "all operational" while monitors are pending or down | banner text vs `monitors_pending` / `monitors_down` |
| DASH-5 | Active incidents panel matches `GET /dashboard/active-incidents` | ids and titles match; click opens that incident |
| DASH-6 | Monitor snapshot matches `GET /dashboard/monitors-snapshot` | statuses match per monitor |
| DASH-7 | AI inbox renders pending suggestions, or an honest empty state | `GET /dashboard/ai-inbox` count == rendered rows |
| DASH-8 | Accept an AI suggestion creates the incident it promises | `POST /ai-suggestions/{id}/accept` -> incident exists; row leaves the inbox |
| DASH-9 | Dismiss an AI suggestion removes it and persists the dismissal | suggestion status `dismissed` after reload |

## C. Monitors (MON)

| ID | Case | Verify |
|----|------|--------|
| MON-1 | Empty list shows the empty state with a working CTA | no fabricated rows |
| MON-2 | Create an HTTP monitor persists every submitted field | `GET /monitors/{id}` matches the form input field-for-field |
| MON-3 | Create a TCP monitor persists host + port and hides HTTP-only fields | `type == tcp`; no `method`/`expected_status` leakage |
| MON-4 | Submitting an invalid URL surfaces the field error inline | 422 field error rendered on the field, not a toast only |
| MON-5 | Required-field omission is caught client-side before any request | no network call issued |
| MON-6 | Region selection is persisted and honoured by the probe | `regions` array matches; checks appear per region |
| MON-7 | A newly created monitor is checked within one scheduler tick | `monitor_checks` row exists; `last_status` transitions off null |
| MON-8 | Pending (never-checked) monitor renders as Pending, not as up/down | null `last_status` -> Pending copy |
| MON-9 | Detail page KPIs equal `GET /monitors/{id}` | uptime 24h/7d/30d, last response, consecutive fails |
| MON-10 | 90-day uptime bar marks no-data days neutral | no-data days are not counted as up |
| MON-11 | Reliability / error budget is real, never fabricated for a fresh monitor | matches computed uptime; no `0%` breach on a monitor with no data |
| MON-12 | Response-time chart data equals `GET /monitors/{id}/response-times` | point count and range match the selected window |
| MON-13 | Check history table equals `GET /monitors/{id}/checks` | newest-first ordering; status codes and latencies match |
| MON-14 | Edit changes persist and are reflected after reload | `PUT` applied; no stale form state |
| MON-15 | Pause stops checks and shows the paused state | `last_status == paused`; no new checks scheduled |
| MON-16 | Resume restarts checks | new check row after resume |
| MON-17 | "Run check now" triggers an out-of-schedule check | `POST /monitors/{id}/test` reaches the worker; result visible |
| MON-18 | Delete removes the monitor from the list and the API | subsequent `GET` is 404 |
| MON-19 | Custom metric create/edit/delete/reorder persists | `GET /monitors/{id}/metrics` reflects each write |
| MON-20 | Metric extraction preview returns a real extracted value | `POST /monitors/{id}/metrics/preview` |
| MON-21 | Metric series chart equals its series endpoint | `GET /monitors/{id}/metrics/{metric}/series` |
| MON-22 | Cross-team monitor id is masked as not-found | 404, never another team's data |

## D. Incidents (INC)

| ID | Case | Verify |
|----|------|--------|
| INC-1 | Empty list shows the empty state | no fabricated incidents |
| INC-2 | A sustained monitor failure opens an incident automatically | incident row with `signal_source == user_threshold` |
| INC-3 | Manual incident create persists title/severity/impact/monitors | `GET /incidents/{id}` matches the form |
| INC-4 | List filters (lifecycle, monitor, search) narrow against the API | filtered result set matches query params |
| INC-5 | Detail timeline shows actor-tagged entries in order | entries match `GET /incidents/{id}` updates |
| INC-6 | Acknowledge records an entry and advances lifecycle | timeline entry persisted after reload |
| INC-7 | Post a public update persists and is marked public | `is_public == true`; appears on the public status page |
| INC-8 | Resolve closes the incident and sets `resolved_at` | lifecycle `resolved`; leaves the dashboard active panel |
| INC-9 | Reopen returns a resolved incident to active | lifecycle back to an active value |
| INC-10 | AI analysis card renders only real analysis, with confidence | matches `GET /incidents/{id}/analysis`; no invented evidence |
| INC-11 | AI analysis honours the honesty boundary | no deploy/git/log/APM claims; actions advisory only |
| INC-12 | Assignee selection reflects real team members and persists | roster equals the team's members; selection survives reload |
| INC-13 | Postmortem control either persists or is not offered | no control that silently does nothing |
| INC-14 | Weekly digest renders real digest data, gates honestly by plan | `GET /incidents/digest`; plan wall carries an upgrade action |
| INC-15 | Cross-team incident id is masked as not-found | 404 |

## E. Status pages (STAT)

| ID | Case | Verify |
|----|------|--------|
| STAT-1 | Empty list shows the empty state | no fabricated pages |
| STAT-2 | Create persists name/slug/description/branding | `GET /status-pages/{id}` matches |
| STAT-3 | Duplicate slug is rejected with a field error | 422 on `slug`, rendered inline |
| STAT-4 | Attach a monitor as a component persists | pivot row exists; component visible |
| STAT-5 | Reorder components persists the display order | order survives reload |
| STAT-6 | Detach a monitor removes the component | pivot row gone |
| STAT-7 | In-app preview reflects the saved configuration | components and branding match |
| STAT-8 | Add a subscriber persists and appears in the roster | `GET /status-pages/{id}/subscribers` |
| STAT-9 | Invalid subscriber email is rejected inline | 422 on `email` |
| STAT-10 | Remove a subscriber persists | roster shrinks after reload |
| STAT-11 | Public page renders unauthenticated with real component status | no auth required; statuses match the API |
| STAT-12 | Public page shows public incident updates only | private updates absent |
| STAT-13 | Private page is masked as not-found to the public | 404, not 403 |
| STAT-14 | Delete removes the page and its public URL | subsequent `GET` 404 |

## F. On-call and escalation (OPS)

| ID | Case | Verify |
|----|------|--------|
| OPS-1 | On-call view renders real schedules from the API | rendered rotation equals `GET /on-call/schedules`; no fixture names |
| OPS-2 | "Who is on call now" is real | matches `GET /on-call/current` |
| OPS-3 | Create a schedule persists | schedule exists after reload |
| OPS-4 | Add a rotation member persists | rotation row exists |
| OPS-5 | Reorder rotation persists | order survives reload |
| OPS-6 | Remove a rotation member persists | row gone |
| OPS-7 | Add an override persists and is shown as covering the shift | override row exists |
| OPS-8 | Remove an override persists | row gone |
| OPS-9 | Rotation member picker lists real team members only | equals the team's member roster |
| OPS-10 | Escalation policy list is real | equals `GET /escalation-policies` |
| OPS-11 | Create a policy with ordered steps persists | steps and delays match |
| OPS-12 | Reorder / remove steps persists | survives reload |
| OPS-13 | Edit a policy persists | `PUT` applied |
| OPS-14 | Delete a policy persists | 404 afterwards |
| OPS-15 | Empty state renders when a team has no policies | not an empty column |

## G. Notification channels (CHAN)

| ID | Case | Verify |
|----|------|--------|
| CHAN-1 | Channel list reflects real configured channels | equals `GET /notification-channels` |
| CHAN-2 | Configure a Slack channel persists its config and severity floor | payload stored; secret not echoed back |
| CHAN-3 | Configure a webhook channel persists and validates the URL | SSRF-blocked hosts rejected |
| CHAN-4 | Test-send reports a real delivery outcome | `POST /{id}/test` result surfaced truthfully on failure |
| CHAN-5 | Disable a channel stops it being selected for delivery | `enabled == false` persisted |
| CHAN-6 | Severity floor is honoured | a below-floor incident does not notify that channel |
| CHAN-7 | Delete a channel persists | row gone |
| CHAN-8 | Unconfigured push shows the honest "not provisioned" hint | hint driven by backend `meta.push_provisioned` |

## H. Billing and plan gates (PLAN)

| ID | Case | Verify |
|----|------|--------|
| PLAN-1 | Billing view shows the real current plan | equals `GET /billing` |
| PLAN-2 | Usage meters equal `GET /billing/usage` | used/limit per resource |
| PLAN-3 | Plan catalog equals `GET /billing/plans` | tier names, prices, limits |
| PLAN-4 | Free monitor cap of 1 blocks the second create with an upgrade action | 403/422 carries the upgrade marker; nudge offers Upgrade |
| PLAN-5 | Free status-page cap of 1 blocks the second create | as above |
| PLAN-6 | Free responder cap of 1 blocks the second invite | as above |
| PLAN-7 | Check-interval floor below the plan minimum is rejected | backend rejects; UI pre-empts |
| PLAN-8 | AI analysis is walled above the Free allowance | 403 with `upgrade.required_plan` |
| PLAN-9 | Free AI monitor-setup allowance is exactly 3 and counts down | remaining 3 -> 0; wall on the 4th |
| PLAN-10 | A failed or rejected AI setup does not spend an allowance | counter unchanged on 422 |
| PLAN-11 | Every plan wall offers an Upgrade action that reaches billing and starts checkout | one checkout per click, never two |
| PLAN-12 | 3-day history retention is reflected in the UI's range options | no range promising data the plan cannot retain |
| PLAN-13 | Invoices and payment method render real data or an honest empty state | matches the API |

## I. Account and team (ACCT)

| ID | Case | Verify |
|----|------|--------|
| ACCT-1 | Profile update persists | name/email changed after reload |
| ACCT-2 | Password change works and invalidates nothing unexpectedly | new password authenticates |
| ACCT-3 | Two-factor enable/disable round-trips | challenge required on next login when enabled |
| ACCT-4 | Session list shows real sessions and revoke works | revoked token stops authenticating |
| ACCT-5 | Language switch applies immediately and persists | TR/EN copy swaps; survives reload |
| ACCT-6 | Timezone change applies to rendered timestamps | timestamps shift accordingly |
| ACCT-7 | Notification preference matrix persists per channel/type | equals the preference endpoint |
| ACCT-8 | Team rename persists and updates the switcher | reload shows the new name |
| ACCT-9 | Invite a member sends an invitation and lists it as pending | invitation row exists |
| ACCT-10 | Team switch re-scopes every screen | data belongs to the newly active team only |

## J. Cross-cutting (X)

| ID | Case | Verify |
|----|------|--------|
| X-1 | Every route renders with no uncaught exception | `dusk:exceptions` empty per route |
| X-2 | No horizontal overflow at 390 / 768 / 1024 / 1440 px | no render overflow on any route |
| X-3 | Dark mode has no missing pair on any route | no unreadable or default-coloured element |
| X-4 | TR and EN both render without a raw i18n key | no `uptizm.` or `notifications.` key visible |
| X-5 | Every destructive action asks for confirmation | delete paths confirmed |
| X-6 | Every list has empty / loading / error states | error state reachable by killing the API |
| X-7 | API failure degrades honestly, never as fake-empty success | error state, not a zeroed dashboard |
| X-8 | No fixture/mock value reaches any production screen | no `lib/app/mocks` value rendered |
| X-9 | Realtime updates arrive over Reverb without a reload | status change appears live |
| X-10 | Tab title is correct per route | no empty or malformed suffix |

---

## Static-audit findings (pre-execution)

Found by reading the code before driving the UI. Each becomes a case above.

| # | Finding | Case | Severity |
|---|---------|------|----------|
| S1 | `/teams/on-call` renders the `onCallRotation` / `teamMembers` fixtures (`on_call_schedule_view.dart:79,104,141,190`) while `GET /on-call/schedules`, `GET /on-call/current`, `GET /on-call/schedules/{id}` are implemented and never called | OPS-1, OPS-2, OPS-9, X-8 | P0 fabricated data |
| S2 | Incident assignee roster is four invented people (`incident_form_support.dart:179-184`) though the team's real members are available from the starter's members endpoint | INC-12, X-8 | P0 fabricated data |
| S3 | Incident assignee selection is local state only, never persisted; `incidents` has no assignee column | INC-12 | P1 dead control |
| S4 | Postmortem "Edit & publish" calls `editPostmortem()`, a toast-only no-op with no write endpoint | INC-13 | P1 dead control |
| S5 | `POST /monitors/{monitor}/test` is implemented but no UI reaches it | MON-17 | P1 missing surface |
| S6 | `GET /monitors/{monitor}/checks` is implemented; the check-history table's data path needs confirming | MON-13 | P1 verify |
| S7 | Metric series + preview endpoints (`/metrics/series`, `/metrics/{metric}/series`, `/metrics/preview`) are implemented and appear unused | MON-20, MON-21 | P1 verify |
| S8 | `/settings/changelog` renders a hardcoded fixture release list | X-8 | P2 static mock |
| S9 | Several views import `lib/app/mocks/*` without using them (monitor detail, monitor form, incident detail, status pages list) | X-8 | P2 dead import |
| S10 | `docs/uptizm-system/architecture.md` still describes the app as a fixture mock, and `product.md` still advertises the old Free tier (10 monitors) | documentation | P2 stale docs |
| S11 | The escalation-rung target picker builds from the `teamMembers` fixture (`escalation_support.dart:98`), so a rung can page a person who does not exist | OPS-11, X-8 | P0 fabricated data |

## Defects found by execution

Each was reproduced live against the running stack before being fixed, and each
carries a regression test that fails without the fix.

| # | Defect | Evidence | Severity |
|---|--------|----------|----------|
| D1 | After a logout and a login as a different user, no domain data was refetched: the dashboard and monitors list kept the previous session's rows until a hard reload. magic caches controllers as Type-keyed singletons and runs `onInit` once per instance lifetime, so nothing re-fetched on an identity change. On a team-scoped product this shows one tenant's data to another. | `POST /auth/login` -> 200 with no subsequent domain request in the telescope log; the dashboard read "No monitors yet" for a team with 3 monitors | P0 |
| D2 | `/monitors` reported "OPEN INCIDENTS 0" while the API reported 2. The count comes from `DashboardController`, which is not that view's backing controller, so its `onInit` never ran and the view did not listen for its data landing. A zero here reads as "nothing is wrong" during a live outage. | UI `0` vs `GET /dashboard/stats` -> `open_incidents: 2` | P1 |
| D3 | Every explicitly-set timestamp was written three hours into the past. Laravel binds a naive datetime string in the app zone (UTC) and PostgreSQL resolved it against the SESSION zone, which was inherited from the OS (Europe/Istanbul). 16 columns are `timestamptz`, including `monitor_checks.checked_at`, `monitors.next_check_at`, `incidents.started_at` / `resolved_at` and the on-call override window, so check times, uptime windows, incident durations and the resolved on-call responder were all shifted. | an incident resolved that second rendered "resolved 3h ago"; raw `display_at` read `00:28:29+03` for an instant stamped `00:28:29Z` | P0 |
| D4 | Creating a notification channel without an explicit `severity` returned 500 while the channel was in fact created: `severity` and `is_enabled` default in the SCHEMA, so they were absent on the unrefreshed in-memory model and the resource read `severity->value` on null. The client reported a failed save for a channel that then appeared in its own list. Every existing store test sent both fields, which is why it was never caught. | `POST /notification-channels` -> 500 `Attempt to read property "value" on null`, row present in the DB | P0 |
| D5 | The status-page editor showed the operator `uptizm.com/status/<slug>`, an address no route answers. The real public page is served at `/s/<slug>`, so every URL an operator copied into a customer email was a 404. | `GET /status/qa-status` -> 404, `GET /s/qa-status` -> 200 | P1 |
| D6 | Acknowledging an incident wrote `"Acknowledged by Ada Lovelace"` into the timeline: the client hardcoded a fabricated responder name and posted it as the update message. The backend's own `author` was correct (`Demo`), so a person who does not exist was recorded as having responded to an outage. | timeline entry persisted with `author: Demo`, `message: "Acknowledged by Ada Lovelace"` | P0 |
| D7 | The AI analysis card omitted the counter-evidence column when the list was empty, so a "high confidence" verdict rendered backed only by supporting evidence. `ai-design.md` requires an empty "against" list to be surfaced, not silently accepted. A real live analysis returned three supporting items, zero against, and high confidence. | live `GET /incidents/{id}/analysis` -> `evidence_against: []` | P1 |
| D8 | The proactive monitor-cap and status-page-cap refusals were error toasts with no action: they named the tier in prose and left the user to find billing, the plan and the checkout button. | tapping "New monitor" at 3/1 showed a toast with no Upgrade control | P1 |
| D9 | The escalation-rung delay label was assembled from English literals, so a Turkish operator read "After 5 min" inside an otherwise translated ladder. | `escalationDelayLabel` returned untranslated strings | P2 |

## Verified-good behaviour worth recording

These were suspected and turned out correct, so they are pinned here to stop a
future change re-litigating them.

- A revoked token does NOT leave a half-authenticated shell: the 401 on the
  notification poll returns the user to Sign In (AUTH-4).
- Wrong credentials show "Invalid credentials" in place and never navigate
  (AUTH-3).
- The SSRF guard on webhook and Teams URLs rejects loopback, link-local and
  private hosts, and non-https schemes (CHAN-3).
- A channel test-send with bad credentials answers `502 {"delivered": false}`:
  a failed delivery is reported as failed, never as success (CHAN-4).
- Notification-channel secrets never travel back: the resource emits presence
  booleans plus non-secret display bits only (CHAN-2).
- All three AI plan walls answer 403 with a machine-readable
  `upgrade.required_plan` (`pro` for analysis and the assistant, `business` for
  the digest), which is what lets the client offer the right tier (PLAN-8).
- `GET /incidents/digest` answers 404 "No digest generated yet" and the client
  renders an honest empty state rather than an error (INC-14).
- No-data reads as no data: `—` for an unmeasured average or uptime, `Pending`
  for a monitor with no check yet, neutral days in the 90-day bar (DASH-3,
  MON-8, MON-10).
- The AI analysis cites only Uptizm-owned signals (`source: "check"`), hedges its
  language, and suggests advisory actions with no one-click remediation and no
  claim about deploys, logs or traces (INC-11).


---

## Live pass 2 (2026-07-27, post-upstreaming refactor)

Run after the magic/magic_starter upstreaming refactor (27 files deleted from
uptizm, ~150 call sites rebound, `main.dart` and `AppServiceProvider` rewritten),
on a `migrate:fresh --seed` database, driven through Chrome with dusk at 1440x900
and 390x844.

### Verified working

| Area | Evidence |
|------|----------|
| Boot after the refactor | app boots, zero exceptions at rest |
| AUTH-1 guest redirect | unauthenticated boot lands on login, no shell chrome |
| AUTH-3 bad password | "Invalid credentials", no navigation, no crash |
| AUTH-2 login | 10/10 boot requests 200, including `POST /broadcasting/auth` (Reverb authenticated) |
| AUTH-4 revoked token | wiped tokens server-side; app returned to login, no half-authenticated shell |
| DASH-2 KPI honesty | every rendered number equals `/dashboard/stats` exactly (`1/3`, `65.08%`, `2`, `19ms`) |
| DASH-3 no-data | `uptime_24h_delta: null` renders nothing, not a fabricated `0` |
| DASH-4 fleet banner | names the actual down monitors, never "all operational" |
| DASH-5 / DASH-7 | active-incident and AI-inbox counts and titles match their payloads |
| DASH-8 accept suggestion | incidents 2 -> 3, new incident `ai_owned=true` `signal_source=ai_anomaly`, suggestion `accepted` |
| Monitoring pipeline (live) | 63 probes/monitor since reset -> checks -> consecutive fails -> incident auto-created -> dashboard reflects it |
| Worker error text | unreachable host reports "Could not reach checkout.acme.test", not an opaque internal error |
| MON list honesty | down monitors show an em-dash placeholder for response time; "MONITORS USED 3 / 1 · Free plan" states the over-cap truth |
| PLAN gate (full flow) | at cap -> upgrade dialog with the real cap sentence + "Available on Pro and up." -> "Upgrade" -> Plan & billing |
| ACCT `layout.app` override | starter settings/profile/2FA routes render inside uptizm's own chrome (no dual shell) |
| STAT public URL | status page shows an absolute `http://localhost:8000/s/acme` |
| CHAN push hint | "Push not yet configured" renders from the backend flag |
| Mobile 390x844 | shell swaps to the mobile chrome; ZERO overflow across monitors, incidents, status, on-call |

### Defects found and fixed

| ID | Severity | Defect | Fix |
|----|----------|--------|-----|
| P2-D1 | high (product honesty) | The demo AI suggestion attached to the HEALTHIEST monitor and carried invented evidence (`observed 842` / `baseline 210`) that its own checks contradicted (real median 234ms, live 19-72ms). The AI inbox therefore shipped a confident claim the data denied, which is the one thing the product's AI boundary forbids. | `AiSuggestionSeeder` now measures each monitor's own recent-vs-baseline medians, attaches the suggestion to the monitor whose data supports it, derives every number in the prose and evidence from those rows, and writes NOTHING when no monitor qualifies. `StatusPageSeeder` gives the degraded monitor a real ramp so the fixture contains a true anomaly instead of a higher flat line. Pinned by `tests/Feature/AiSuggestionSeederHonestyTest.php` (red-phase verified). |

### Open, not fixed

| ID | Severity | Finding |
|----|----------|---------|
| P2-O1 | low (cosmetic) | A deep-link web load logs Flutter's non-fatal "Could not navigate to initial route" from `Navigator.defaultGenerateInitialRoutes`. Functionally harmless (the app lands correctly every time) but it pollutes the exception buffer, which can mask real defects during a QA pass. Pinning `initialRoute: '/'` on magic's two pre-router placeholder `MaterialApp`s was expected to silence it and did not, so the emitter is still unidentified. |
| P2-O2 | low | Navigating to an unregistered route silently no-ops rather than showing a not-found view. Observed via `dusk:navigate`, which drives the app's own Navigator, so this needs confirming against a real URL visit before it counts as a product defect. |

### False defects, and what caused them

Recorded because each one nearly shipped as a bug report:

1. **"Tapping New monitor logs the user out."** Reproduced four times, including after a clean re-login. It was a STALE `e`-ref: dusk's `e<N>` handles are snapshot-frozen and resolve by index, so after several navigations `e104` no longer pointed at the button (plausibly the user dropdown's Logout). Instrumenting `EnsureAuthenticated` proved `redirectTarget` was never called at the tap, and with a freshly resolved ref the upgrade dialog appears correctly. **Use `dusk:find` q-refs for anything after a navigation.**
2. **"The UI invents incident lifecycle labels."** The payload had `status: null` while the screen read "Detected". The real field is `lifecycle`, which I had not read. Two more false signals came from the same habit (`MonitorCheck.ok`/`error` and `Monitor.target` do not exist; the columns are `status`/`error_message`/`url`).
3. **"A logout on tapping a nudge."** An earlier instance was caused by MY OWN edit to a magic source file mid-session triggering a reload. **Do not edit source while a live QA session is in flight.**
4. **`telescope:clear` does not empty the buffer `dusk:exceptions` reads**, so a single boot-time warning read as a per-route error on seven routes. Correct the earlier note that said otherwise.
