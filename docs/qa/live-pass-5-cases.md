# Live pass 5: the four operational domains, A to Z

Scope, set by the request: custom metrics and the AI incident lifecycle (open,
update, close), the on-call rota inside a team with its escalation and webhook
paging, notification delivery, and status pages. Everything a person clicks is
driven for real at desktop and at mobile width; everything a person cannot click
(a webhook 500, a rota with nobody in it, a locale on a queued mail) is driven at
the layer that can answer it.

Executed against the seeded dev stack: `php artisan serve` on 8000, Redis on
6379, Reverb on 8080, the regional-checker on 8787, one queue worker covering
every queue from `config/horizon.php`, and the Flutter web build under Chrome at
3100 with CDP. Demo account `demo@uptizm.test`, team **Demo's Team**, plan
**business** (so no feature is hidden behind a plan gate), 4 monitors, 5 open
incidents, 4 status pages.

Case types:
- **W** white box: aimed at a specific branch I have read.
- **B** black box: driven as an operator with no knowledge of internals.
- **C** complex: multi-step, cross-surface, or adversarial.

Surface column: `D` desktop (1200+), `M` mobile (below the `lg` gate), `A` API or
queue level, `-` not applicable to that layer.

## A. Custom metrics: definition and extraction

| ID | Type | Surface | Case | Expected |
| --- | --- | --- | --- | --- |
| CM-1 | B | D, M | Open a monitor, add a numeric metric with a JSON path | Persists, appears in the list, and the next check records a value |
| CM-2 | B | D, M | Add a metric whose key already exists on that monitor | Refused with a legible message, not a 500 and not a silent second row |
| CM-3 | W | A | A metric whose extraction fails (JSON path matches nothing) | No `monitor_metric_values` row at all, and the UI says "no reading" rather than showing 0 |
| CM-4 | W | A | A numeric metric with no bounds set (`band` is null) | Threshold evaluation must not open an incident on a null band |
| CM-5 | W | A | A string metric with all three value lists empty | `alertsOnString()` false, so no incident can open from it |
| CM-6 | B | D, M | Edit a metric's bounds after samples exist | Historic samples keep the band they were written with; only new samples use the new bounds |
| CM-7 | C | D | Delete a metric that has an open incident against it | The incident does not become uncloseable (this is the closed-forever class) |
| CM-8 | B | M | The metric list and editor at mobile width | Readable, no overflow, the form's submit is reachable without hunting |

## B. The AI incident lifecycle

| ID | Type | Surface | Case | Expected |
| --- | --- | --- | --- | --- |
| AI-1 | B | D, M | Open an incident detail carrying an AI analysis | The analysis renders with its confidence, and nothing claims AI when the text is a template |
| AI-2 | W | A | Auto mode, model returns `confirmed: false` | No incident is opened, and the suggestion records why |
| AI-3 | W | A | Auto mode, model returns confidence below High | No incident, degrades to a pending suggestion |
| AI-4 | W | A | Over daily AI budget in Auto mode | No incident opened, degrade reason recorded as a value, not prose |
| AI-5 | W | A | Model returns unparseable JSON | Degrades to the statistical suggestion; the anomaly still stands |
| AI-6 | C | A | An AI-owned incident whose anomaly clears | Auto-resolves; the three silent skip branches (no incident, no level, readings not under) must be distinguishable in a log |
| AI-7 | W | A | Threshold lane must not close an AI-owned incident, and vice versa | Each lane closes only its own rows |
| AI-8 | B | D | Acknowledge an AI incident | Lifecycle moves to investigating, paging stops |
| AI-9 | B | D, M | Post a public update on an incident | Appears on the timeline and on the public status page |
| AI-10 | B | D | Resolve, then reopen an incident | Both transitions stamp a timeline note; `resolved_at` clears on reopen |
| AI-11 | C | D | An incident escalating warn to critical | Severity rises, an escalated notification fires, and severity never falls back |
| AI-12 | B | M | Incident detail at mobile width | Timeline, actions and the AI card all reachable; actions behind the overflow control |

## C. On-call, escalation and webhooks

| ID | Type | Surface | Case | Expected |
| --- | --- | --- | --- | --- |
| OC-1 | B | D, M | Create a schedule, add two responders, read "who is on call" | The ring answers with the responder whose shift covers now |
| OC-2 | W | A | A schedule whose `timezone` is not UTC | The stored timezone changes the answer, or the field is not offered at all |
| OC-3 | W | A | A schedule with zero rotations, escalation step targets on-call | Someone is told nobody was paged; it is not a silent no-op |
| OC-4 | W | A | An override covering now | Beats the ring, at both inclusive edges |
| OC-5 | C | A | A rota crossing a DST boundary in a non-UTC zone | The responder does not silently shift by an hour |
| OC-6 | B | D, M | Create an escalation policy with two steps | Steps persist in order with their delays |
| OC-7 | C | A | Incident opens, first step pages, operator acknowledges before step 2 | Step 2 does not page |
| OC-8 | W | A | The step's target user was deleted before it fired | Not a crash, and not silence either |
| OC-9 | B | D, M | Add a webhook channel and send the test | Honest outcome: a 500 target reports failure, not "delivered" |
| OC-10 | W | A | Webhook target answers 500 | Reported, siblings still delivered, queue not poisoned |
| OC-11 | W | A | Webhook target times out | Same, with the timeout distinguishable from a refusal |
| OC-12 | W | A | Webhook target answers 429 with `Retry-After: 3` | Exactly one retry, delay clamped into range |
| OC-13 | C | A | Webhook signature | HMAC over `{timestamp}.{body}` verifies with the stored secret |
| OC-14 | B | D | A webhook URL pointing at an internal address | Refused at save time, and again at send time |
| OC-15 | B | M | On-call and escalation editors at mobile width | Usable, submit at the bottom, no overflow |

## D. Notification delivery

| ID | Type | Surface | Case | Expected |
| --- | --- | --- | --- | --- |
| N-1 | B | D, M | Toggle a notification preference off, then trigger that event | Nothing arrives on that channel; the others still do |
| N-2 | W | A | A user with no preference rows at all | Receives the defaults (fail-open is deliberate) |
| N-3 | W | A | A team channel gated to critical only, warn incident opens | Not sent |
| N-4 | C | A | Two incidents inside the throttle window on one channel | One send, and the second is not lost silently forever |
| N-5 | W | A | Recipient locale on every human-facing payload | Mail, push and in-app carry the recipient's language; webhook and PagerDuty may stay English by decision |
| N-6 | B | D, M | The in-app bell after an incident opens | The row appears, marks read, and the count is right |
| N-7 | C | A | A channel whose credentials were removed | Skipped without throwing, and the operator can tell |
| N-8 | B | D | Send a test to each channel type present | Each reports its real outcome |
| N-9 | C | A | Maintenance window open with `suppress_alerts` | Paging held, but the dashboard and public page still update |
| N-10 | B | M | Notification settings at mobile width | Every toggle reachable |

## E. Status pages

| ID | Type | Surface | Case | Expected |
| --- | --- | --- | --- | --- |
| SP-1 | B | D, M | Editor loads a saved page | Fields, monitors, preview all populated |
| SP-2 | C | D | Attach a monitor, save, open the public page | The component appears with the right status |
| SP-3 | W | A | A paused monitor attached to the page | Hidden from the public page, and the Flutter side agrees |
| SP-4 | W | A | A page with zero visible components | Overall status is unknown, never "all systems operational" |
| SP-5 | W | A | A monitor that has never been probed | Component reads unknown, not operational |
| SP-6 | B | - | The public page in both languages | Banner, components and dates all follow the locale |
| SP-7 | C | - | Subscribe, confirm, unsubscribe | Double opt-in works end to end; the token is burned on confirm |
| SP-8 | W | A | Subscribe twice with the same address | No second mail, no duplicate row, same answer to the visitor |
| SP-9 | C | A | A maintenance window in progress | Rendered as a window, and it does not turn the banner red |
| SP-10 | W | A | An unpublished (private) page | 404, indistinguishable from an unknown slug |
| SP-11 | B | D, M | Logo upload, then the public page | The image renders on both surfaces |
| SP-12 | B | M | Editor at mobile width | Preview below the fields, submit reachable, no overflow |
| SP-13 | C | A | Delete a monitor that a page publishes | The component disappears rather than rendering a hole |

## F. Cross-cutting

| ID | Type | Surface | Case | Expected |
| --- | --- | --- | --- | --- |
| X-1 | W | D | Boot the app and read captured exceptions | Zero render errors on a clean boot |
| X-2 | B | D, M | Every route in the four domains, at both widths | No exception, no blank screen, no fabricated number |
| X-3 | W | A | Every write in these domains against a foreign team's id | 404, never 403, never a leak |
| X-4 | B | D | Screen-reader labels on the controls these flows use | No unlabelled button |

## Findings

Recorded as they are proven, with the artifact behind each. A case that passes is
not listed; a case that cannot be run says so and why.

### F1 (fixed) A zone mismatch on every boot, and Sentry's only web error path

`X-1`. The app reported one render error on a clean boot: `FlutterError: Zone
mismatch. The Flutter bindings were initialized in a different zone than is now
being used.` `main()` called `WidgetsFlutterBinding.ensureInitialized()` in the
root zone (it has to, because `Env.load` reads the bundled `.env` through
`rootBundle` and swallows a failure into "using default values"), and
`SentryFlutter.init` then ran `appRunner` inside its OWN `runZonedGuarded`,
because sentry_flutter 9.27.0 does that whenever it is called from the root zone
and `PlatformDispatcher.onError` is unavailable, which is the case on web.

On web that zone handler is the only uncaught-async-error path Sentry has, so
this was not only console noise. Fixed by entering one zone in `main` before the
binding, which makes `isRootZone` false and keeps `appRunner` in the same zone;
the zone's own handler now does both halves of what Sentry's would have done.
Verified: `dusk:exceptions` returns `count: 0` on a clean boot, against 1 before.

### F2 (fixed) The metric lane counted rows, so one round satisfied any threshold

`CM-4` adjacent, found while driving `CM-1`. `ScheduleMonitorChecks` fans out one
job per region per interval, and each writes its own metric sample, so a
3-region monitor writes three rows per round. `breachStreakMet` counted ROWS and
excluded only the current check's own id, so the sibling regions of the SAME
round counted as prior history: any monitor with as many regions as its
`incident_threshold` opened an incident on its first breaching round. The close
path had the mirror bug through `metricRunIsOk`, so the same monitor also
resolved after one healthy round.

Measured live on the demo team's `API` monitor (3 regions, threshold 2): bound
lowered at 13:05:03, incident opened at 13:05:04 off checks
`a28e3a7e-742c…` (us-east), `a28e3a7e-7a72…` (eu-west) and `a28e3a7e-8706…`
(ap), all three from that one round. The down lane has counted rounds since
`consecutiveFullyDownTicks` landed for exactly this reason; the metric lane never
got the same treatment.

Fixed by reconstructing rounds in time (samples within half a check interval are
one round) and requiring every sample in a round to agree, which also preserves
the published rule that one healthy region stops the count. Re-measured live on
the same monitor: first breaching round 13:16:03, incidents 0; second breaching
round 13:17:03, one critical incident. Six new unit cases, two of which fail
without the fix.

### F3 (fixed) A status code measured in percent

`CM-1`. The blank metric form defaults to `unit: '%'`, which fits the JSON-path
example it is shaped around and nothing else. Switching the source to HTTP status
left it there, and the unit is rendered without ever being validated, so a metric
created with the defaults untouched displayed its reading as **"200 %"**
(measured on `http_status_code`). `MetricForm.withSource` now pairs the unit with
the source for the one impossible combination, and leaves a unit the operator
chose alone.

### F7 (fixed) An escalation rung that reached nobody said nothing

`OC-3`, `OC-8`. `pageOnCall` returned silently when the team had no schedule or
an empty rotation, and `pageUser` returned silently when the pinned user was
gone. All three happen AFTER the idempotency marker is claimed, so a team with a
policy and no rota climbed its whole ladder paging nobody and the only evidence
was silence. Now recorded on the evidence channel, which exists for precisely
this question and already carried the two suppression lines. Four new cases,
three of which fail without the fix.

### F9 (fixed) The in-app component list promised what the public page hides

`SP-3`. `StatusPageAssembler::visibleMonitors` applies four gates; the Dart
`components` getter applied one, while its docblock claimed parity. So a PAUSED
monitor appeared as a component in the app and not on the customer-facing page,
and a healthy `only_show_if_degraded` component appeared in the app for exactly
as long as nothing was wrong with it. Fixed on both halves: the resource now
emits `only_show_if_degraded` (paused already arrives resolved into
`last_status`), and the getter applies all four.

### F10 (fixed) Every avatar painted its initials outside the circle

Reported from a screenshot mid-pass. Eight tiles across three views were spelled
`grid ... place-items-center`. Wind implements `grid` and does NOT implement
`place-items-*`, and an unknown token there is a silent no-op, so the box had no
alignment and the glyphs landed against its top-left edge, clipped by the circle.
Measured in a widget test: 11.6px off-centre horizontally on the 56px hero
avatar.

`flex items-center justify-center` centres correctly but a single-child Wind flex
box does not shrink its child even under `overflow-hidden` (the Flexible wrap
lives in the multi-child branch of `w_div.dart` only), so two initials in a 20px
circle overflowed the row. Centring is therefore done with a Flutter `Center`,
which has no Row in the way. The geometry is pinned by a test that measures the
GLYPH boxes rather than the text widget's box, because both the box and the
circle measure 56x56 and comparing those two centres is vacuous.

### Not defects, ruled out with a measurement

- **The dashboard hiding an open AI incident.** `DB::table('incidents')` bypasses
  the soft-delete scope; the row was deleted at 2026-08-16 22:12:55. The count of
  2 and "0 AI-detected" were both right.
- **A custom metric's value never appearing.** The card read `—` because the
  metric had just been created and no check had landed yet. Measured properly:
  LAST CHECK 38s ago → tap → 2s ago, with a check row at 13:03:24 behind it.
- **A webhook target that hangs poisoning the queue.** Deliberate and documented:
  a transport error propagates so the queue can retry a transient failure, while
  a non-2xx is reported once. The call is bounded by Laravel's own 30s default,
  and the test-send endpoint catches the throwable and answers
  `delivered: false` with a 502.

### Open, not yet fixed

- **F6 The on-call schedule's timezone changes nothing.** It is stored, fillable,
  editable, and rendered as "Primary rotation · times shown in Europe/Istanbul",
  while `RotationResolver` anchors the ring on `schedule.created_at` in absolute
  instants and never reads the field. No times are shown on that surface either,
  so the sentence promises twice what it delivers once. The honest fix is to
  anchor shift boundaries to local midnight in the schedule's zone AND render the
  current shift's window; the cheap fix is to stop claiming times are shown.
- **Duplicate and unlabelled controls in the shell.** Every sidebar destination
  is a button inside an identically-labelled button (`Monitors` inside
  `Monitors`), as are the monitor rows on the dashboard, so a screen reader
  announces each twice; the team switcher, the notification bell and the account
  control are buttons with no accessible name at all.
