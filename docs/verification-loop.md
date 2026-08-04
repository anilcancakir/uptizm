# The verification loop

How a change gets proven in this repository, for any agent and any tool. Three
layers, in order of cost. A change is not done because the first one passed.

1. **Static and unit** (`bin/check`): seconds to a minute. Never skipped.
2. **Visual** (preview catalog + screenshots): for a component or a screen.
3. **End to end** (dusk driving a real Chrome): for anything a person clicks,
   at desktop and at mobile width both.

## 1. The static gate

```sh
bin/check              # everything, in parallel
bin/check --fast       # analyze + pint + tsc only
bin/check flutter      # one half; also backend, worker
```

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
flutter run -d chrome --web-port=3100 --host-vmservice-port=8181 \
  --web-browser-flag="--window-size=1680,1050"
```

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

Resize through `Browser.getWindowForTarget` + `Browser.setWindowBounds`.
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

## What counts as evidence

A claim needs the artifact behind it: the `bin/check` summary, the screenshot
pair, the snapshot or the response body. "Should work" and "green locally" are not
evidence, and neither is a passing test that could not have failed. Screenshots and
snapshots go under `.ac/evidence/`.
