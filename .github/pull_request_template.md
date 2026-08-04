<!-- Keep this short. The point is the evidence, not the prose. -->

## What changed

<!-- One or two sentences. What behaviour is different after this merges? -->

## Why

<!-- The problem this solves. Link the doc under docs/uptizm-system/ or the plan under .ac/plans/ if there is one. -->

## Evidence

<!--
Paste what you actually ran, not what you believe. `bin/check` output, a
screenshot pair for a component, a dusk snapshot or a response body for a
behaviour change. docs/verification-loop.md is the procedure.
-->

- [ ] `bin/check` green
- [ ] Exercised for real (dusk walk at desktop and mobile width for UI, a real request for an endpoint, a real target for a probe)

## Contract check

<!-- Tick only what applies; delete the rest. -->

- [ ] Touched a domain enum, so `backend/app/Enums/` and `lib/app/enums/` both changed
- [ ] Touched an `api/v1` endpoint shape, so the Flutter caller changed with it
- [ ] Touched a `DESIGN.md` token, so `design:sync` ran and `backend/resources/css/app.css` was mirrored
- [ ] Touched the relay spec, so both `RelayClient` and the Worker changed
- [ ] Touched a migration that adds a column something writes (note it: the deploy needs queues stopped across the pull-to-migrate window)
- [ ] Structural change worth mirroring into `../magic_example` (link that PR)
