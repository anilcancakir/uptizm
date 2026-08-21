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

### F12 (fixed) "Plan a window" opened the incident form

`SP-9`. The maintenance tab's own empty state navigated to `/incidents/new` with
no kind, so the operator landed on the INCIDENT form, one unnoticed switch away
from declaring an outage that pages the on-call and publishes a red banner, for
work they meant to announce. The route now carries `?kind=maintenance`.

### F14 (fixed) Every incident page claimed the monitor was down

`N-5` adjacent, found in the bell. `notifications.incident_opened_*` hardcoded
":monitor is down" across the mail subject, the in-app row title and the push
heading. Measured on a monitor that answered 200 from all three regions
throughout: "API is down" over a body reading "HTTP status code breached
critical bound", where only the second line was true and it was the small one.
The same false headline reaches an AI anomaly, an SSL expiry and a hand-filed
incident. The copy now renders the incident's own composed title, which for a
real down incident is the same sentence, so the common case is unchanged.

### F15 (fixed) A malformed id was a 500, on production's engine only

`X-3` adjacent, found while probing cross-team masking. Laravel runs an `exists`
rule as a real query, and PostgreSQL raises
`SQLSTATE[22P02] invalid input syntax for type uuid` when a non-uuid string
meets a `uuid` column. `{"user_id": "x"}` therefore answered **500** on five of
six write endpoints (add rotation, add override, add escalation step, open
incident, schedule maintenance, create monitor), measured against the dev
database:

```
select count(*) as "aggregate" from "team_user"
where "user_id" = x and "team_id" = a26c03f7-...
```

Every one now answers 422 with a field error, verified against real PostgreSQL.
`App\Support\IdFormat` supplies the format rules behind a `bail`, and it reads
`magic-starter.use_uuids` rather than hardcoding `uuid`, because the schema is
UUID-optional.

The reason this reached production: the suite runs on SQLite, which compares the
same input happily and returns no rows, so the endpoints answered a clean 422 in
every test. `tests/Feature/Http/MalformedIdRejectionTest.php` says so in its own
docblock; it was run against PostgreSQL with the fix reverted (fails: "rotation
user answered 500") and with it in place (passes).

### Cases that passed, with the artifact

- `AI-8`, `AI-9`, `AI-10`: acknowledge moved the incident to `investigating` and
  wrote a public note; an update posted and appeared on the public page within
  the same second; resolve stamped `resolved_at` and its own note. Three notes,
  in order, at 14:00:26, 14:00:47 and 14:01:29.
- `SP-7`: subscribe → 200 and an unconfirmed row carrying both tokens; confirm →
  200, `confirmed_at` and `opt_in_confirmed_at` set, confirm token burned to
  null; unsubscribe → 200 and the row deleted.
- `SP-6`: the Turkish public page renders "Büyük kesinti", "Bileşenler",
  "Olaylar" and a translated incident title. The page description stays English
  because it is the operator's own copy.
- `X-3`: every cross-team read and write answered 404, never 403, across
  monitors, incidents, status pages, on-call schedules (including a nested
  rotation write), escalation policies and notification channels.
- The maintenance filter tab is clipped at 430px but scrolls and taps fine.
- The metric close path took exactly two healthy rounds (bounds raised 13:45:53,
  resolved 13:46:33, six ok samples = two rounds of three regions).

### F18 (fixed) The channel throttle dropped a second outage

`N-4`. `dispatchChannels` claimed a cache key of `(channel, event)` for 60
seconds, so a second incident opening inside that window was DROPPED on every
integration channel: not deferred, not queued, dropped, with nothing anywhere
saying so. One shared dependency failing takes several monitors down inside the
same half minute, and the team's Slack hears about one of them. The key now
carries the incident, so the window still coalesces repeated announcements of the
SAME incident (what a resolve-and-still-broken loop produces) and no longer
collapses distinct outages into one.

**This is a behaviour change worth a veto**: a burst of N distinct incidents is
now N sends rather than one. Each is a real, separate outage, and the four
integration channels already carry a bounded Retry-After-aware retry for an
endpoint that rate-limits, so the trade is deliberate.

### F19 (fixed) Every shell control was announced twice, and unnamed

`X-4`. `WPopover` wraps its trigger in an unlabelled
`Semantics(button: true, onTap: toggle)`, because its toggle runs on a raw
`Listener` that assistive technology cannot see, and the trigger content produces
a node of its own. Measured in the DOM at 1280px: the team switcher, the bell and
the account menu each rendered TWO overlapping buttons, none of the six carrying
an `aria-label`, and the bell's only accessible name was the unread COUNT ("14").
`ShellControlSemantics` merges each into one named node, above the popover rather
than inside its trigger builder. Measured after: three nodes instead of six,
named, at 1200px and 430px.

The nav rows in the same snapshot look like the same defect (an inactive
destination prints as a button nested inside an identically-labelled button) and
are NOT: the DOM carries exactly one button element per row. That one was a
`dusk:snap` artifact, which is why the test asserts the semantics tree rather
than what the snapshot prints.

### F20 (fixed) Deleting a metric left its incident open forever

`CM-7`. The metric lane's auto-resolve asks whether the trailing run of frozen
bands for a metric KEY is clear, and a deleted metric produces no further
samples, so the run stayed whatever it was at the breach and the answer was no
forever. Nothing else closed it either: `resolveIfRecovered` is scoped to
`trigger_metric_key IS NULL`, and `closeOrphanedBy` only fires when the MONITOR
goes. The incident sat `detected` until somebody noticed it.

`closeOrphanedByMetric` mirrors the monitor-deleted precedent one level down,
including its silence (no page, no public update) and its internal note stating
the reason. Two exclusions mirror guards the evaluator already applies: an
`ai_owned` incident is not the metric's (its `trigger_metric_key` is a signal
name), and the scope is the metric's own monitor, since a key is unique per
monitor and two monitors may both measure `cpu`.

### F21 (fixed) A page with no measurement published "All Systems Operational"

`SP-4`, `SP-5`, and the most consequential finding of the pass. `unknown` is not
on the severity ladder, because it is the absence of a severity rather than a rung
of it, so `worstOf` skipped it and returned the ladder's bottom. A page whose
components were all published and none of them ever probed therefore published
**operational**.

That is the state EVERY monitor is in for its first interval, and the state a
monitor stays in for as long as our own edge cannot probe it at all: exactly when
a status page must not claim health. The empty-page case had already been fixed
with the same reasoning and the same sentence in its comment; the case one step
along, where components exist and none is measured, fell through it.

One measured component is now enough to earn a verdict, and it decides among the
measured ones. The unmeasured components still read `unknown` on their own rows,
so nothing is hidden by letting what IS known speak. Three cases: all-unknown,
never-probed, and the mixed page where one down component still turns the banner
red.

### Checked and closed without a change

- **The AI auto-resolve path's three silent skip branches** (no active AI
  incident, no numeric level on its evidence, too few readings). All three are
  fail-closed guards on states that do not occur: every `ai_owned` incident is
  opened with a linked accepted suggestion (`sweepAuto` sets
  `accepted_incident_id` inside the same transaction, and the manual accept path
  does too), all three detector methods write a numeric `threshold` into
  evidence, and `PruneExpiredAiSuggestions` is scoped to `status = Pending` so an
  accepted suggestion is never deleted out from under its incident. The docblock
  already says the branches are never worse than the state they replace.
- **Webhook delivery under a hanging target.** Bounded by Laravel's own 30s
  default; the test-send endpoint catches the throwable and answers
  `delivered: false` with a 502.

### More cases that passed, with the artifact

- `AI-1`: the incident's AI card never claims an analysis it does not have. It
  renders "Reading the checks..." while the request is in flight and "The
  analysis could not be loaded." with a retry when it fails, which is what
  happened here: `local.ERROR: Maximum execution time of 30 seconds exceeded`,
  the dev server's own wall against a 150s AI budget. A harness limit, not a
  product defect, and the card told the truth about it.
- `AI-10`'s reopen half, by accident and correctly. Resolving the Checkout
  incident by hand at 17:01 while the monitor was still failing produced, at
  17:04, a system note reading "Checkout is still failing; the incident was
  reopened." That is `sameOutageJustResolved` doing exactly what it exists for.
- `CM-3`: a metric whose extraction cannot succeed records NOTHING rather than a
  fabricated zero. In the round where `http_status_code` recorded three samples,
  a `$.nothing.here` path recorded none and opened no incident, and the tab
  renders it as an em-dash.
- `CM-2`: a duplicate metric key on the same monitor answers 422 with "The key
  has already been taken."
- `SP-12`: the status-page editor at 430px renders every field, both header
  actions and the brand-mark controls, with no overflow.

- `OC-9`: a test send to a target that does not answer 2xx returns **502** with
  `{"delivered": false}`, not an optimistic "queued".
- `OC-14`: loopback (v4 and v6), the cloud metadata address, a private range and
  `localhost` by name are all refused at save time with "The url host is not
  allowed", and zero channels are created. A plain `http://` URL is refused one
  rule earlier, on the scheme.
- `SP-10`: a private page and an unknown slug both answer 404, indistinguishable,
  and subscribing to a private page is 404 too.
- `SP-8`: two identical subscribe submissions produce ONE row and the same 200
  both times, so a visitor cannot tell whether the address was already
  subscribed.

- Realtime, end to end: resolving an incident through the API moved the
  dashboard's OPEN INCIDENTS from 2 to 1 in eight seconds with no navigation, so
  the broadcast fires on an operator write, the client is subscribed to its team
  channel, and the dirty-mark plus debounced reload path works. Measured on a
  COLD boot after the same test read as broken on a session that had survived
  four hot restarts: that session was throwing
  `TypeError: dart_rti.instanceType(...)[_eval] is not a function` every frame
  and had stopped reacting to anything.

### F22 (fixed upstream) A preference row overflowed at a phone width

`N-10`. Every notification-preference row carrying the push hint under its label
overflowed by 14 pixels at 430px, with Flutter's yellow-and-black stripe painted
across it. The icon-plus-text half demanded its intrinsic width, and a two-line
text column is wider than a one-line one.

The surface is `magic_starter`'s, and the memory rule for it is to improve the
starter rather than work around it in the app, so the fix is
[magic_starter#100](https://github.com/fluttersdk/magic_starter/pull/100):
`flex-1 min-w-0` on the half and on the text column. Every existing case in that
file runs at 1280 or 1920, which is exactly why it was invisible; the new one runs
at 430 with the hint present and fails without the fix by 267 pixels (the test
font is wider than the shipped one, which is why the assertion is "no exception"
rather than a pixel count).

Verified live in uptizm at 430px against the patched sibling: the stripe is gone
and the hint wraps under the label.

- `SP-9`: an active window created through the API renders on the public page
  with its title, its own section and an "in progress" state, and does not
  participate in the component roll-up: the banner stayed red because two
  monitors were genuinely down, not because of the window.
- Slug enumeration is throttled: 30 unknown slugs answer 404 and the 31st
  answers 429, per IP.
- There are no RSS / atom / JSON feed routes on the public surface. Nothing to
  test, and worth recording as a negative result rather than a gap somebody
  assumes was covered.

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
