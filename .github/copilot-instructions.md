<!-- GENERATED from AGENTS.md by bin/sync-instructions. Edit that file, not this one. -->

<!-- Canonical agent instructions for this repository, shared by every tool. CLAUDE.md is a symlink to this file; .github/copilot-instructions.md is generated from it by bin/sync-instructions. Edit THIS file. -->
# AGENTS.md

Guidance for any AI agent working in this repository (Claude Code, GitHub Copilot, Codex, opencode). This is the single canonical instruction file; see "Where the instructions live" at the bottom for how each tool reaches it.

Uptizm is an uptime, incident, and status-page monitoring product: periodic checks against a customer's sites and services from several regions, custom metrics derived from the response, AI-assisted triage when something breaks, on-call rotations and escalation, SLA and notification delivery, public status pages with subscriber updates, and maintenance windows.

## The three halves

- **Flutter client** (repo root, `lib/`): the product app for web, iOS, and Android. Built on the local `magic` framework + `magic_starter` + `fluttersdk_wind`, wired in `lib/main.dart`. Talks to the backend over `api/v1` (Sanctum bearer, base URL in `lib/config/network.dart`) and subscribes to Reverb `private-team.{id}` for realtime.
- **Laravel backend** (`backend/`): the JSON API, the monitoring core, the landing page, and the public status pages. Laravel 13, PostgreSQL (+ optional TimescaleDB), Redis/Horizon, Reverb, Octane.
- **Regional checker** (`backend/workers/regional-checker/`): a Cloudflare Worker running region-pinned probes in Durable Objects, driven by HMAC-signed specs the backend POSTs to `RELAY_URL`.

Domain behavior is specified in `docs/uptizm-system/` (product, architecture, data-model, db-design, ai-design). Read the relevant file before changing how a monitor, check, incident, or status page behaves; it is more current than any summary of it.

## One task, one worktree, one PR

Several agents work this repo at the same time, so isolation is the default and `master` is never written directly.

- Create the worktree BESIDE the repo, not inside it: `git worktree add ../uptizm-<slug> -b feature/<slug>`. The eleven sibling packages are declared as `path: ../<pkg>`, so from a nested `.claude/worktrees/<slug>` they resolve to a directory that does not exist, and `flutter analyze` reports eight `path_does_not_exist` warnings that no override file can silence. `bin/check` refuses to run in the wrong layout and tells you this.
- `bin/check` then copies the gitignored files a worktree needs from the main checkout. Run `(cd backend && composer install)` yourself: a branch that touches `composer.lock` has to be tested against its own vendor tree.
- Land the work as a PR against `master`, and let CI be the evidence rather than a local run.
- Deploying is manual and stays manual. `deploy/README.md` is the procedure and it belongs to a human; no agent runs it.

## Verifying a change

`bin/check` is the gate. It fans the suites out across cores and prints one line per job:

- `bin/check` runs all of it: `flutter analyze`, `flutter test`, `pint --test`, `artisan test --parallel`, and the worker's `tsc`.
- `bin/check --fast` runs only the static passes when you want an answer in seconds.
- `bin/check flutter|backend|worker` scopes it to one half.

Do not run `dart format`. The committed Dart was formatted before the current SDK's tall formatter, which collapses the multi-line-collection-with-trailing-comma style the whole repo uses; running it churns dozens of untouched files. `flutter analyze` is the real Dart gate.

A green suite is the floor, not the finish line. Anything a person clicks gets driven for real with `fluttersdk_dusk` against a running Chrome, at desktop and at mobile width both, because the shell swaps widget trees at `lg` (1024px) and each side can break alone. An endpoint gets a real request; a probe gets a real target. **`docs/verification-loop.md` is the procedure**: the three layers, the six services the app needs to boot, how to boot it (`fsa start` no longer manages this app's web build), how to resize a viewport correctly, and the measurement traps that have produced confident wrong answers.

## Running it

- Flutter: `flutter run -d chrome`. `.env` has to stay a bundled pubspec asset (`pubspec.yaml` `flutter.assets`); without it `flutter_dotenv` defaults `BROADCAST_CONNECTION` to null on web and realtime never connects.
- Backend: `cd backend && composer dev` (serve + queue + pail + vite). `php artisan migrate:fresh --seed` reseeds the dev DB and the demo account.
- Worker: `cd backend/workers/regional-checker && npm run dev` (`:8787`, matching `RELAY_URL`).

## Off-limits

- Generated files are regenerated, never edited: `lib/config/wind_theme.g.dart` (`design:sync`), `lib/preview/_previews.g.dart` (`previews:refresh`), `lib/app/commands/_index.g.dart` (`commands:refresh`), `.artisan/plugins.json`, and everything `bin/sync-instructions` writes under `.github/`.
- `backend/vendor/`, `build/`, `.dart_tool/`.
- The eleven `fluttersdk` packages under `../` are separate public repositories. Reading them to understand behavior is expected and encouraged; changing one is a PR in that repo under its own rules, never an edit from here. `design:sync`, `design:lint`, `make:component`, and `previews:refresh` are `magic`'s commands, not this project's.
- Secrets never enter the repo. This repository is public: `.env.production` holds only values that ship to every browser anyway, and server credentials live on the box.

## Mirroring the boilerplate

This repo was forked from `../magic_example`, which stays the ecosystem's boilerplate. A structural change here (a rule, a skill, the component contract, tooling like `bin/check`) is mirrored into `magic_example` as its own PR in that repo, in the same piece of work. Product code (monitoring, incidents, status pages, billing) does not travel.

## Design-first, on every surface

The design system is shared by all three halves and is enforced. `DESIGN.md` is the source of truth for tokens; the Flutter side generates from it (`design:sync` writes `lib/config/wind_theme.g.dart`), and the Laravel side hand-mirrors it in `backend/resources/css/app.css`, so a token value change is a two-file change. Read `DESIGN.md` before any UI work, and `docs/component-registry.md` before building a new widget.

Regeneration commands, all through the dispatcher: `dart run bin/dispatcher.dart design:sync` (theme from `DESIGN.md`), `design:lint` (token check), `previews:refresh` (preview catalog), `make:component <Name> [--variants=intent,size] [--slots]` (the 4-file atomic folder under `lib/ui/components/<name>/`).

## Where the instructions live

This file is canonical. Everything else either points at it or is generated from it:

| File | Role |
|---|---|
| `AGENTS.md` | canonical, hand-edited. Read natively by Codex, opencode, and Copilot's agent surface |
| `CLAUDE.md` | symlink to this file, because Claude Code reads `CLAUDE.md` and not `AGENTS.md` |
| `.github/copilot-instructions.md` | generated copy, for Copilot's repo-wide instructions and its PR review bot |
| `.claude/rules/<topic>.md` | path-scoped rules with `paths:` frontmatter; Claude Code loads one when you touch a matching file |
| `.github/instructions/<topic>.instructions.md` | generated from those rules with `applyTo:` frontmatter, so Copilot's PR review applies the same rules |
| `docs/verification-loop.md` | how a change is proven: static, visual, and dusk E2E |

The five path-scoped rules are `design.md` (tokens and the component contract, `lib/**`), `flutter-app.md` (Dart, magic idioms, tests), `backend.md` (the API and monitoring core), `web-pages.md` (landing and status pages), and `relay-worker.md` (the Cloudflare Worker). If your tool does not load them automatically, open the one covering the area you are changing.

After editing this file or any rule, run `bin/sync-instructions` to regenerate the `.github/` mirrors. CI fails when they are out of date.

