# The verification loop

How a change gets proven in this repository, for any agent and any tool. Three
layers, in order of cost. A change is not done because the first one passed.

1. **Static and unit** (`bin/check`): seconds to a minute. Never skipped.
2. **Visual** (preview catalog + screenshots): for a component or a screen.
3. **End to end** (dusk driving a real Chrome): for anything a person clicks,
   at desktop and at mobile width both.

Section 4 is not a fourth layer of proving a change. It is how to read a system that
is already running without producing a confident wrong answer, which is a separate
skill with its own failure modes.

## 1. The static gate

```sh
bin/check              # all seven jobs, in parallel
bin/check --fast       # the four static ones: analyze, design tokens, pint, tsc
bin/check flutter      # one half; also backend, worker
```

The seven are `flutter-analyze`, `design-tokens`, `backend-pint` and
`worker-typecheck` (the static four, which is what `--fast` runs), then
`flutter-test`, `backend-test` and `worker-test`. Two of them are narrower than
their names suggest, and a change that leans on the wrong one is unverified:

- `design-tokens` is a comment-stripped regex over `lib/**/*.dart` for `Color(0x`
  and `Colors.`, with four allowlisted paths. It does not see `Color.fromARGB`
  and its siblings, a hardcoded pixel value, a colour token written without its
  `dark:` pair, or a one-off widget where a registry component already exists.
  Those are reviewer-enforced; a green gate is not a statement about them.
- `worker-test` runs in real workerd, so it reaches the Durable Object and a real
  `connect()`, but no test in it can tell you how a given target answers a
  datacenter IP. CI runs it inside the job named `Regional checker (typecheck)`,
  which is a frozen required-check name rather than a description of what it does.

`flutter analyze` is the real Dart gate. Do not run `dart format`: the committed
tree predates the current SDK's tall formatter and running it rewrites dozens of
untouched files.

## 2. The visual loop, for components and screens

```
CREATE -> SCREENSHOT -> ANALYZE -> FIX -> VERIFY
```

Three rounds maximum. Stop and surface the problem if a full round produces no
improvement, rather than looping on the same finding.

- **CREATE** using semantic tokens and existing components. Check
  `docs/component-registry.md` before building a new widget.
- **SCREENSHOT** light and dark, from the preview catalog:

  ```sh
  ./bin/fsa dusk:navigate --route=/preview
  ./bin/fsa dusk:screenshot -o .ac/evidence/<name>-light.png
  # switch the catalog to dark, then:
  ./bin/fsa dusk:screenshot -o .ac/evidence/<name>-dark.png
  ```

- **ANALYZE** with the `component-visual-reviewer` reviewer
  (`.claude/agents/component-visual-reviewer.md` for Claude Code; other tools can
  read that file as the scoring rubric). It scores token compliance, dark/light
  parity, spacing, typography, and radii, and returns BLOCKING and ADVISORY items.
- **FIX** every BLOCKING item. ADVISORY items only when they need no scope creep.
- **VERIFY** by re-screenshotting and re-scoring.

## 3. The end-to-end walk with dusk

`fluttersdk_dusk` drives a running Flutter app over VM Service extensions: it
reads the Semantics tree as a YAML snapshot with stable `[ref=eN]` handles and
dispatches real gestures through a six-check actionability gate. It is how Flutter
work gets proven, and it is the only layer that catches a defect that only exists
once real data and a real viewport are involved.

Every verb below has a second face. `.mcp.json` wires `./bin/fsa mcp:serve` as a
project MCP server, so an agent whose client reads that file gets the same dusk,
telescope and artisan surface as tools rather than as shell commands. Both routes
drive the same running app through the same `~/.artisan/sessions/` state, so they
are interchangeable and can be mixed within one walk. The traps recorded further
down are properties of the app and the web substrate, not of the CLI, and they
apply verbatim to the tools. This file stays written in CLI form because that is
the form that can be pasted into a terminal and read back in a log.

### The app needs six services, and two of them fail as "the app is broken"

1. `php artisan serve --port=8000`
2. **Redis on 6379.** Without it every `api/v1` call 500s on the rate limiter,
   login included. `redis-server --port 6379` is enough.
3. **Reverb on 8080** (`php artisan reverb:start --port=8080`). Load-bearing for
   the client: `.env` sets `BROADCAST_CONNECTION=reverb`, and the boot-time Echo
   connect throws an uncaught exception when the socket refuses, which kills the
   whole Flutter boot. The page renders blank, the snapshot is empty, and nothing
   says why. If the console shows Env / Cache / Database / Locale ready and then a
   WebSocket error, this is it.
4. `cd backend/workers/regional-checker && npm run dev` (`:8787`, matching
   `RELAY_URL`) for anything that produces check data.
5. A queue worker covering **every** queue, not just `default`. A check is a
   two-hop pipeline: `PerformMonitorCheck` (queue `checks`) calls the relay and
   then dispatches `ProcessCheckResult` on `processing`. A worker without
   `processing` logs a successful probe while `monitor_checks` stays empty and the
   monitor sits on Pending forever, which reads exactly like a product bug. Take
   the list from `config/horizon.php` rather than guessing.
6. The app itself, under Chrome with a CDP port.

### Booting the app (measured 2026-08-03)

`./bin/fsa start --cdp-port=N` does not boot this app's web build: its CDP branch
waits a hardcoded 60s for the serve line and the cold build takes about that long,
so it times out, kills the process, and leaves a partial build that makes the next
attempt cold again. `--timeout` does not help; the serve-wait fires first.

The path that works:

```sh
flutter run -d chrome --web-port=3100 --host-vmservice-port=8181
```

**Do not pass a window size through `--web-browser-flag`.** The flag it needs,
`--window-size=1680,1050`, contains a comma, and `--web-browser-flag` is an
`addMultiOption` whose `splitCommas` defaults to true
(`args-2.7.0/lib/src/parser.dart:341` is a bare `value.split(',')` with no escape
handling, so `\,` does not help either). Chrome therefore receives two arguments:
`--window-size=1680`, and a bare `1050`. A bare argument is a URL to Chrome, and
its fixup reads `1050` as a 32-bit integer address, so the browser opens
`http://0.0.4.26/` in the first tab and puts the app in the second. Measured
2026-08-25; the recipe here used to carry that flag.

Set the size after launch instead, with `./bin/fsa dusk:resize --width=W
--height=H`, which is the same CDP path the responsive section below already
prescribes.

Start it so it survives the shell that launched it. Then read
`Debug service listening on ws://127.0.0.1:8181/<token>=/ws` from the log and
write `~/.artisan/state.json` with `pid`, `vmServiceUri`, `webPort`,
`vmServicePort`, `projectRoot`, and `device` so the dusk CLI can find it. Chrome's
CDP port is the one Flutter chose, not one you pick: read it from the process args
(`ps aux | grep -o "remote-debugging-port=[0-9]*"`). Check for
`Address already in use` on 3100 first; a killed run leaves the port held.

### Responsive: desktop and mobile are both required

The shell swaps at `lg` (1024px): a sidebar plus content column above it, a bottom
tab bar below. A change to any screen is verified on both sides of that line, since
they are different widget trees. Useful widths: 390 (phone, no sidebar), 768
(tablet portrait, still the mobile shell), 1200 (sidebar, container starts at 272),
1440 or wider (desktop).

Resize through `./bin/fsa dusk:resize --width=W --height=H`, which drives
`Browser.getWindowForTarget` + `Browser.setWindowBounds`.
**Not** `Emulation.setDeviceMetricsOverride`: Flutter web reads its logical size
from the host element, so that override grows the screenshot canvas while the app
keeps laying out at the old width, and everything renders doubled and clipped.

### Driver behavior on this build (measured 2026-08-03)

- `ext.dusk.snap` returns an empty snapshot here even though the semantics tree is
  live. `ext.dusk.navigate` returns a populated one, so navigate-then-read is the
  way in. This is the extension, not the CLI: calling it directly over the VM
  Service websocket behaves the same.
- `dusk:scroll` does not move a starter page, because the shell's region owns the
  primary scrollable. A real `Input.dispatchMouseEvent type: 'mouseWheel'` scrolls
  it. Verify scrolling with wheel events.
- `dusk:hot_reload_and_snap` reports `reloaded: false` on web. Hot restart works:
  `./bin/fsa hot-restart`, then wait about 25s.
- `dusk:tap --ref=eN` is the tap verb; there is no `dusk:click`.
- `dusk:wait` prints a human line rather than JSON. Parse the text.
- `dusk:navigate --route=/dashboard` no-ops because the dashboard is `/`.
- `fsa tinker --eval=...` is broken against a web-server device (dwds answers
  `NoSuchMethodError`). Use CDP `Runtime.evaluate` instead.

### Three traps that produce confident wrong measurements

- An exact-label lookup over the semantics tree resolves to the **sidebar** nav
  item, which carries the same label as the page it opens. Constrain the search to
  the content region or you will measure the sidebar and conclude two pages differ.
- A hardcoded content-region threshold (`x > 300`) is wrong at other widths: at
  1200px the container starts at 272, at 390px there is no sidebar at all. Derive
  it from the width under test.
- "The bottom-most content node" matches an aggregate parent whose box spans the
  whole page, so an overlap check reads true on every page including unchanged
  ones. Look at the screenshot.

## 4. Reading a system that is already running (measured 2026-08-12)

The three layers above run locally against code you just wrote, and they fail loudly.
Answering a question about a system that is ALREADY running, in production or in a
live local stack, fails quietly instead: nothing goes red, you get a number, and the
number is wrong. In one production audit every false finding came from this list
rather than from the product, so rule the harness out before filing a defect.

- **Read the identifier, never guess it.** `response_time_ms` (the column is
  `response_ms`) read as "no latency is being stored", and `status.subscribe.blurb`
  (the key is `subscribe.body`) read as "dead copy". Tinker prints null for a field
  that does not exist and `__()` prints the key back, neither of which is
  distinguishable from missing data. `Schema::getColumnListing()` and the `lang/`
  file itself each settle it in one line.
- **Take a before/after boundary from the artifact, not from your estimate of when
  you acted.** Counting crashes "in the 26 minutes since the change" put eleven of
  them AFTER a fix that had already stopped them; `stat -c %Y` on the file that IS
  the change gave 27 before and 0 after.
- **The box runs `Europe/Istanbul` and the apps run UTC.** A Laravel log line at
  `09:31` beside a shell `date` reading `12:32` is one minute of lag, not three hours
  of a dead scheduler. Print both clocks in the same command.
- **`grep -c` exits 1 when the count is zero**, so a `|| echo "?"` fallback fires on
  a perfectly good measurement and looks like a broken connection. The non-zero exit
  is a second piece of evidence that the count really is zero.
- **A string composed outside the template does not follow a later locale switch.**
  The status banner is built in `StatusPageAssembler`, so rendering ONE view model
  under `en` and then under `tr` leaves the page's largest string in English and
  reads as "the headline was never localized". Build the view model fresh per locale.
  The 60-second `status-page:{slug}` cache carries one language on purpose.
- **Requesting Octane directly on `127.0.0.1:9502` makes every request-derived URL
  `http://`,** because nginx is what sets `X-Forwarded-Proto`. Ask the edge before
  filing a canonical-URL defect.
- **A 404 is not a regression until `route:list` says the route exists.** Two
  "regressions" in one pass were URLs that had never existed.
- **An AI timeout is usually routing, and a second call's shorter wall is the request
  budget already spent.** One call timed out at 149s, the identical payload returned
  in 62.4s, and the next got 87s because `ai.request_budget_seconds` (150) was down
  to its remainder. One timeout proves nothing; repeat it.

## What counts as evidence

A claim needs the artifact behind it: the `bin/check` summary, the screenshot
pair, the snapshot or the response body. "Should work" and "green locally" are not
evidence, and neither is a passing test that could not have failed. Screenshots and
snapshots go under `.ac/evidence/`.

A claim about a running system needs one thing more: the reading has to survive
section 4. A count is only evidence once its boundary comes from the artifact, an
identifier only once it was read rather than guessed, and a single timeout is never
evidence of a wall.
