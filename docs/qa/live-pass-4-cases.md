# Live pass 4: end-to-end QA over dusk

Executed against a freshly reset and seeded dev database, with the full stack up
(serve, reverb, redis, a queue worker consuming every queue including `previews`,
the regional-checker edge worker) and the Flutter web app driven through dusk on
CDP.

Scope: the whole product A to Z, with the status-page preview render as the primary
target because it is new and because the code review found that NO existing case
asserted the editor actually DISPLAYS the PNG. Every earlier live assertion about
the preview was made at the HTTP layer.

Case types:
- **W** white box: I know the implementation and aim a case at a specific branch.
- **B** black box: drive the UI as an operator with no knowledge of internals.
- **C** complex: multi-step, cross-surface, or adversarial.

## A. The preview render, through the UI (the reviewed gap)

| ID | Type | Case | Expected |
| --- | --- | --- | --- |
| P-1 | B | Open a saved status page's editor | The right pane shows the customer-view label and the PNG of the real page, not an empty frame |
| P-2 | W | Same, but prove it is the SHOW read that supplies it | The pane is populated on a plain open, with no Generate tap, because `refetch()` chains `reload()` then `reloadPage()` |
| P-3 | B | Edit a field without saving | The pane switches to the DRAFT label and the Flutter approximation; the customer-view label disappears |
| P-4 | C | Save the edit | The pane returns to the customer view and a fresh render is dispatched automatically |
| P-5 | B | A page that has never rendered | Explicit empty state plus a Generate action, never a blank frame under a customer-view label |
| P-6 | C | Tap Generate and watch through to completion | In-flight skeleton immediately (not after a worker starts), then the PNG |
| P-7 | C | Stop the previews consumer, then tap Generate | The skeleton appears, then the check-again affordance, and the request eventually expires into the failed state with a retry rather than holding the skeleton forever |
| P-8 | W | The age chip | A render older than the threshold carries the may-be-out-of-date chip; a fresh one does not |
| P-9 | B | Tap the PNG | Opens it full size, since it is unreadable at a third scale in a 380px column |
| P-10 | C | Attach a component, then check the PNG | The new render includes the component (this is the drift class the whole feature exists to kill) |

## B. Auth and shell

| ID | Type | Case | Expected |
| --- | --- | --- | --- |
| A-1 | B | Sign in with the seeded demo account | Lands on the dashboard |
| A-2 | B | Sign in with a wrong password | Inline error, no navigation, no crash |
| A-3 | C | Sign out, then hit a deep route directly | Redirected to sign-in, not a blank screen |
| S-1 | B | Every sidebar destination at desktop width | Each renders its own screen with no exception |
| S-2 | B | Mobile shell below `lg` | Bottom tab bar replaces the sidebar; every tab reachable |

## C. Domain sweep

| ID | Type | Case | Expected |
| --- | --- | --- | --- |
| D-1 | B | Dashboard with seeded data | Real KPIs, no fabricated numbers, no zeroed grid |
| M-1 | B | Monitors list, then a monitor detail | Rows render; detail shows KPIs, uptime, tabs |
| M-2 | C | Create a monitor through the form | Persists and appears in the list |
| I-1 | B | Incidents list, then an incident detail | Timeline renders with real updates |
| ST-1 | B | Status pages list | Rows render with real state |
| ST-2 | C | The public page in a real browser tab | Matches what the PNG showed |
| O-1 | B | On-call, escalation, notification channels | Each renders |
| PL-1 | B | Billing and plan surface | Real plan, real limits |

## D. Adversarial and failure paths

| ID | Type | Case | Expected |
| --- | --- | --- | --- |
| X-1 | C | Rapidly re-tap Refresh on the preview | No duplicate renders queued, no stuck state (the job is unique per page until processing) |
| X-2 | C | Navigate away mid-poll | No exception, no timer left running |
| X-3 | C | Kill the backend, then interact | Honest error states, never fabricated data |
| X-4 | W | A private status page's editor | Preview still renders, because the renderer authorises by header token |
| X-5 | B | Every screen at 390px wide | No overflow, no clipped controls |
| X-6 | W | Exception buffer after the full pass | Empty, or only entries explained and attributed |

## Results

| ID | Result | Evidence |
| --- | --- | --- |
| A-1 | PASS | Signed in; dashboard rendered. Fills had to be done one field at a time with the value asserted between them; filling both then tapping left the form reporting both as required. |
| D-1 | PASS | Real KPIs: 2/3 monitors up, 66.67% uptime (consistent with 2/3), 1 open incident, 533ms avg. One real AI suggestion with its reasoning. No zeroed grid, no fabricated figures. |
| ST-1 | PASS | List shows the real public URL, Degraded, "3 components / Path / 0 subscribers". |
| P-5 | PASS | Fresh seed, nothing rendered: explicit "No preview yet" plus Generate, under the customer-view heading. No blank frame. |
| P-6 | PASS | Generate showed the sized skeleton IMMEDIATELY (the semantic tree lost "No preview yet" and the Generate button on the very next frame), then the PNG. Backend went `completed` with a path and a stamp. |
| P-1 | PASS | The PNG is displayed in the pane, with "Rendered a few seconds ago", Refresh and View full page. |
| P-2 | **PASS, and this was the reviewed gap** | Navigated to Dashboard, back to the list, re-opened the editor with NO Generate tap: the PNG is there ("Rendered a minute ago"). Proves the chained show read supplies it. The code review found that no case anywhere asserted the editor DISPLAYS the PNG; this is that case. |
| P-3 | PASS | Editing the name flipped the heading to "DRAFT PREVIEW" and removed the customer-view heading, the stamp, Refresh and View-full-page in the same frame. The honesty gate holds. |
| P-4 | PASS | Save persisted the new name, returned to the list, and auto-dispatched a second render (queue log 07:53:23 RUNNING, 07:53:25 DONE). |
| P-7 | **FAIL, then fixed** | See the defect below. |
| X-5 | PASS | 390x844: bottom tab bar replaces the sidebar, columns stack, brand swatches wrap to two rows, no overflow, no clipping. |
| M-1 / I-1 | PASS | Monitors and Incidents both render real data at mobile width. |
| X-6 | PASS | Exception buffer empty after the whole walk, checked repeatedly. |

## Defect found: Refresh was a dead control (P-7)

**Symptom.** On a page that already has a render, tapping Refresh changed nothing
observable. With the `previews` queue deliberately unconsumed I watched the pane for
130 seconds across four samples: it kept showing "Rendered a minute ago" and the
Refresh button, with no acknowledgement of the tap at any point.

**Why the earlier fix did not cover it.** The in-flight marker added during the code
review is only consulted while `preview_render_status` is null, i.e. on the
never-rendered path. An already-rendered page reports `completed`, so it never
reached that branch. Refresh on an existing preview is the most common action this
feature has, which makes it the worst possible place for a silent control.

**Not a lie, but a dead control.** The stamp stayed accurate the whole time, so
nothing false was shown. The failure is that the operator's action was never
acknowledged.

**Fix.** Both the completed and the failed bodies now show the existing
"Still generating. Check again in a moment." line while a request is outstanding.
The stored image and its stamp deliberately stay visible: they are still the truth
about the last successful render, and hiding real data to signal a pending one would
trade a dead control for a lie. Red-phase verified.

**Also confirmed while there:** the job was genuinely queued and waiting the whole
time (`laravel-database-queues:previews` depth 1, 0 attempts), and it drained and
completed within seconds of a consumer returning. So the recovery path works.

## Open, and needing your call: the demo account is over its plan limit

The Monitors screen shows **"MONITORS USED 3 / 1"** on the demo login.

That is honest arithmetic, not a display bug: `config/plans.php:43` gives Free a
limit of 1 monitor, and `DemoSeeder` creates 3 for a team it puts on Free. So the
first screen a person sees after logging into the demo reads as broken, and any
plan-gated create is blocked because the team starts 3x over.

I did not change it, because the two fixes mean different things and the choice is
yours:

- **Seed the demo team on a paid tier.** The demo then showcases 3 monitors with
  incidents and metrics as intended, and the KPI reads normally. Costs the ability to
  demonstrate the over-limit nudge from the demo account.
- **Seed one monitor.** The KPI reads normally on Free, but the dashboard, incidents
  and status page all lose most of their content, which is most of what the demo is
  for.

A third option is that the current state is deliberate, to show the plan wall
working. If so it is worth a line in the seeder saying so, because it currently
reads as a defect.

## Method note

Two harness traps cost time and are worth recording. Filling two fields back to back
and then submitting leaves the form reporting both as empty; fill one, assert the
value landed, then fill the next. And a mutation used to prove a test non-vacuous
removed the wrong one of two identical blocks (the failed body precedes the completed
body in the file), which made a real test look vacuous. Target the occurrence, not
the first match.
