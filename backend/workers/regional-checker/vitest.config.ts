/**
 * Vitest configuration for the regional-checker worker.
 *
 * The suite runs inside real `workerd` via `@cloudflare/vitest-pool-workers`
 * instead of plain Node. That is not a preference: this worker's answers wake
 * the on-call, and the parts most likely to be wrong are the ones Node cannot
 * reproduce (Durable Object state and its `locationHint` placement, subrequest
 * limits, `ctx.waitUntil`, and `cloudflare:sockets` `connect()` for the TCP
 * probe, for which no protocol-level mock exists at all). A green Node test
 * would certify behaviour the deployed edge never executes.
 *
 * The pool is registered as a Vite PLUGIN, `cloudflareTest()`. Every tutorial
 * in circulation still reaches for `defineWorkersConfig`, which was removed in
 * pool 0.13.0; the same removal wave took `SELF` (use `exports` from
 * `cloudflare:workers`), `fetchMock` (gone in 0.20.0, spy on `globalThis.fetch`
 * instead) and `isolatedStorage`/`singleWorker` (per-file isolation is now the
 * default). The first three fail loudly at import. `isolatedStorage` does not:
 * the pool validates its options with a zod schema that STRIPS unknown keys, so
 * a stale option is accepted and silently ignored, which is the worse failure.
 * `node_modules/@cloudflare/vitest-pool-workers/dist/codemods/` carries the
 * upstream v3-to-v4 codemod if an old config ever needs porting.
 *
 * Bindings are read from `wrangler.toml` rather than declared here, so a test
 * sees the same `RegionalProbe` binding and migration tag production runs, and
 * a drift between the two shows up as a failing test rather than as a probe
 * that only works in CI. Nothing is set under `miniflare` deliberately: those
 * values take precedence over the wrangler config and would reintroduce the
 * drift this indirection removes. `RELAY_SECRET` therefore arrives the same way
 * `wrangler dev` supplies it, from the unsuffixed `.dev.vars`; the
 * environment-suffixed `.dev.vars.<env>` variant is broken upstream
 * (workers-sdk#5641), so a named test environment would need an explicit
 * `miniflare.bindings` override.
 *
 * The pool is ESM-only, which is why `package.json` declares `"type": "module"`.
 * Without it Vite bundles this config as CJS, `require()`s the pool and dies
 * with "This package is ESM only" before a single test is collected. The worker
 * ships no CJS file, so nothing else was affected by the switch.
 *
 * Separately, the `test` script carries NO `--passWithNoTests`, deliberately: a
 * suite that is green on an empty set certifies nothing, and this one is a
 * required CI check. `vitest run` exits non-zero when it collects no test file,
 * which is the behaviour to keep.
 */

import { cloudflareTest } from "@cloudflare/vitest-pool-workers";
import { defineConfig } from "vitest/config";

export default defineConfig({
    plugins: [
        cloudflareTest({
            wrangler: {
                configPath: "./wrangler.toml",
            },
        }),
    ],
    test: {
        // The TCP probe's fixture, and the one thing in this suite that cannot
        // live in a test file. `cloudflare:sockets` `connect()` has no
        // protocol-level mock, so the probe opens a real socket and something has
        // to be listening on the other end; only `globalSetup` runs in Node,
        // where a listener can be created at all. See `test/global-setup.ts`.
        globalSetup: [
            "./test/global-setup.ts",
        ],
    },
});
