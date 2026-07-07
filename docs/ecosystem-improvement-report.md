# Ecosystem Improvement Report (uptizm lens)

> LLM-FRIENDLY ARTIFACT. This file is written to be re-analyzed by an LLM agent.
> Findings have stable IDs, `file:line` anchors, severity/effort/status fields,
> external best-practice citations, and a verification note each. When you (a
> future agent) act on an item, update its `status` and append a `resolution`
> line; do not renumber IDs.

## Metadata

- generated: 2026-07-05
- scope: `fluttersdk_wind`, `magic_starter`, `magic` (framework + magic_cli), `dusk`, `telescope`, `magic_devtools`
- lens: the `uptizm` app (a design-first monitoring mock built on the whole stack); every finding is anchored to a concrete friction hit while building/porting uptizm
- method: 1 orchestrator rough pass + 10 `ac:explore` (codebase) + 3 `ac:librarian` (external best practice) research agents
- paths are absolute under `/Users/anilcan/Code/fluttersdk/<package>/`
- id scheme: `WIND-*` (wind), `MS-*` (magic_starter), `MAGIC-*` (framework), `CLI-*` (design tooling), `DEV-*` (dusk/telescope/devtools)

## How to re-analyze this file

1. Read the Priority Index table first; it is the ranked worklist.
2. Each finding is a `### [ID] Title` block with fixed field labels
   (`package`, `severity`, `effort`, `status`, `evidence`, `locations`,
   `problem`, `fix`, `external`, `verification`). Parse by label.
3. `severity`: P0 (high-impact, cross-cutting) > P1 (high-impact, isolated) > P2 (maturity).
4. Before implementing, re-verify each `locations` anchor still exists (line
   numbers drift); the surrounding symbol name is the durable key.
5. `external` links are best-practice backing; treat them as the target shape.
6. Items 1+2 (WIND-1 / MS-2) share one root cause (recipe className semantics)
   and should be planned together.

## Priority Index

| ID | Title | Package | Severity | Effort | External-backed |
|----|-------|---------|----------|--------|-----------------|
| WIND-1 | `className` must MERGE onto the recipe, not REPLACE | wind + magic_starter | P0 | medium | yes (strong) |
| MS-2 | First-class `fullWidth` / `size` variant | magic_starter | P0 | low | yes (strong) |
| WIND-3 | 14 hardcoded palette colors bypass the token system | wind | P0 | medium | no |
| WIND-4 | No min-width-stretch scroll primitive; internal LayoutBuilder unsafe under intrinsic ancestors | wind | P1 | high | yes |
| WIND-5 | Silent no-op DX (unknown token, `flex-wrap`, `h-full`-in-scroll) | wind | P1 | low | no |
| MS-6 | 9 components need container binding to render (testability) | magic_starter | P1 | low | no |
| MS-7 | Flat-export name collisions + no single theme-adoption hook | magic_starter | P1 | medium | no |
| MAGIC-8 | i18n gaps: missing-key warning, trans_choice, dynamic titles, locale switch | magic | P2 | varied | partial |
| MAGIC-9 | MVC ergonomics: controller-binding boilerplate, route ordering footgun, stubs | magic | P2 | medium | no |
| CLI-10 | design:sync no custom token families; design:lint false positives | magic_cli | P2 | medium | no |
| DEV-11 | Web render-errors under-surfaced to agents | dusk + telescope | P2 | varied | partial |

---

## P0 findings

### [WIND-1] `className` must MERGE onto the recipe, not REPLACE

- package: wind (root cause) + magic_starter (14 affected components)
- severity: P0
- effort: medium
- status: open
- evidence: While porting the Welcome page, `Button(className: 'w-full')` dropped
  the entire primary fill/padding/rounded styling and rendered as plain text.
- locations:
  - `magic_starter/lib/src/ui/components/button/button.dart:73-75` (`if (className != null) return className!` full bypass)
  - `magic_starter/lib/src/ui/components/button/button.recipe.dart:54` (comment documenting the bypass as intentional)
  - Same bypass in: badge, input, textarea, card, switch, checkbox, radio, skeleton, toast, typography, dropdown_menu, tooltip, settings_section (14 total)
  - CONTRAST (correct append pattern, per-slot map): tabs, select, combobox, accordion, segmented_control
  - wind contract that the bypass violates: `wind/lib/src/recipe/wind_recipe.dart:168` (WindRecipe appends caller className last), `:262` (WindSlotRecipe appends per slot)
- problem: 14 magic_starter components treat `className` as a full recipe REPLACE,
  contradicting wind's own recipe contract (append-last) and the 5 multi-slot
  components that already append. Any caller className nukes the component's own
  styling. There is no way to add one utility (e.g. width) without losing the fill.
- fix: convert the 14 bypass components to append caller className via the
  WindRecipe/WindSlotRecipe caller-slot (make bypass the odd case, gated behind a
  separate explicit `classNameOverride` param for the rare full-replace need).
  Add a `twMerge`-equivalent per-CSS-property conflict resolver to wind so a later
  `w-full` overrides only width, not the whole list.
- external:
  - shadcn/ui `cn()` = `clsx` + `tailwind-merge`: https://ui.shadcn.com/docs/installation/manual
  - shadcn Button uses `cn(buttonVariants({variant,size,className}))` (append last): https://github.com/shadcn-ui/ui/blob/main/apps/v4/registry/new-york-v4/ui/button.tsx
  - tailwind-merge (last-wins per property group): https://github.com/dcastil/tailwind-merge
  - Tailwind discussion #1446 (string order does NOT win; use props/merge): https://github.com/tailwindlabs/tailwindcss/discussions/1446
  - tailwind-variants slots + per-slot override: https://www.tailwind-variants.org/docs/slots
- verification: after fix, `Button(intent: primary, className: 'w-full')` renders a
  full-width button that KEEPS the primary fill/padding; a widget test asserts the
  recipe base classes are still present alongside `w-full`.

### [MS-2] First-class `fullWidth` / `size` variant

- package: magic_starter
- severity: P0
- effort: low
- status: open
- evidence: No clean way to make the Welcome CTA full-width; `className:'w-full'`
  (WIND-1 bypass) was the only lever and it destroyed the styling. Settled on a
  content-width centered button (InviteAcceptView convention).
- locations: `magic_starter/lib/src/ui/components/button/button.dart` (no fullWidth prop); same for input, textarea.
- problem: full-width is a common need forced through the className bypass footgun.
- fix: add a `fullWidth` boolean variant (internally wraps in
  `SizedBox(width: double.infinity)`, since Material buttons ignore
  `crossAxisAlignment.stretch`); keep className for expert override only.
- external:
  - MUI `fullWidth`: https://mui.com/material-ui/api/button/
  - Mantine `fullWidth`: https://mantine.dev/core/button/
  - GetWidget (Flutter) `fullWidthButton`: https://docs.getwidget.dev/gf-button/standard-button/
  - Flutter buttons ignore stretch (#19399): https://github.com/flutter/flutter/issues/19399
  - (counterpoint) Chakra deprecated `isFullWidth` toward `width="full"`: https://github.com/chakra-ui/chakra-ui/issues/1002
- verification: `Button(fullWidth: true)` fills its parent width and centers its label; no className needed.

### [WIND-3] 14 hardcoded palette colors bypass the token system

- package: wind
- severity: P0
- effort: medium
- status: open
- evidence: The full-screen "blue flash" investigation surfaced that WSelect's
  selected row is hardcoded blue; a token-driven framework should never bake in a
  raw palette.
- locations:
  - `wind/lib/src/widgets/w_select.dart:914,921,946,954,702-707,852,862,865` (selected row bg/text, checkmarks, multi chip, create-option: `bg-blue-50`, `text-blue-700`, `Colors.blue.shade600`)
  - `wind/lib/src/widgets/w_checkbox.dart:133` (`checked:bg-blue-500`, `border-gray-300`)
  - `wind/lib/src/widgets/w_radio.dart:137,144` (`bg-blue-500`, `selected:border-blue-500`)
  - `wind/lib/src/widgets/w_date_picker.dart:726-734,338,382` (selected/in-range/today blues, `bg-white/gray` surfaces)
  - `wind/lib/src/widgets/w_popover.dart:696-698` (default `bg-white dark:bg-gray-800` surface)
  - `wind/lib/src/theme/wind_theme_data.dart:191` (`ringColor` default `Color(0xFF3B82F6)`, not configurable)
  - LEGITIMATE (leave): parser white/black/transparent fallbacks, `w_dynamic_renderer.dart` debug-red
- problem: interactive-state colors (selected/checked/in-range/ring) and surface
  defaults are hardcoded to Tailwind blue/gray, not brand-configurable; callers
  cannot override (WSelect `itemBuilder` is the only escape hatch).
- fix: promote each to a WindThemeData semantic-token key (e.g.
  `selectSelectedBg`, `dateSelected`, `dateInRange`, `checkboxChecked`, a
  configurable `ringColor`); unify the repeated `bg-white/gray-800` surface
  defaults into one surface token.
- external: none (internal token-integrity work).
- verification: setting a non-blue brand primary recolors select/checkbox/radio/date
  selection states; no `bg-blue-*` / `Colors.blue` remains emitted by widget code.

---

## P1 findings

### [WIND-4] No min-width-stretch scroll primitive; internal LayoutBuilder unsafe under intrinsic ancestors

- package: wind
- severity: P1
- effort: high
- status: open
- evidence: The recent-checks table saga. A LayoutBuilder-based "fill desktop /
  scroll mobile" table CRASHED the monitor detail page on web (`object.dart:2515
  _owner != null`) because it rendered under an `items-stretch` (IntrinsicHeight)
  ancestor; widget tests did not reproduce it. Resolved by switching to fixed
  columns + `overflow-x-auto`, losing the desktop-fill behavior.
- locations:
  - `wind/lib/src/widgets/w_div.dart:1268-1432` (overflow-x scroll: child gets unbounded width; `w-full`/`flex-1` assert)
  - `wind/lib/src/widgets/w_div.dart:552-561,652-655` (internal LayoutBuilder for `basis-*` and column-stretch gating; unsafe under intrinsic ancestors)
  - `wind/lib/src/widgets/wind_equal_height_row.dart:9-20` (existing intrinsic-free two-pass pattern to extend)
  - `wind/lib/src/state/wind_flex_overflow_scope.dart` (skipExpanded scope to extend)
  - see also memory: `layoutbuilder-items-stretch-crash`, `statusbadge-raw-key-test-overflow`
- problem: three coupled gaps: (a) no "min-width, stretch-to-fill-else-scroll"
  primitive (the responsive data-table pattern); (b) internal LayoutBuilder throws
  under IntrinsicHeight/stretched-row/Table ancestors, creating a combinatorial
  "do not use X inside Y" ruleset; (c) `flex-col items-stretch` behaves differently
  from `grid items-stretch`.
- fix: (a) add an `overflow-x-auto` min-width-stretch variant: wrap in
  `ConstrainedBox(minWidth: containerWidth)` + horizontal `SingleChildScrollView`,
  threading container width via a `WindMinWidthScrollScope` InheritedWidget; (b)
  replace the internal LayoutBuilder with an intrinsic-safe path (extend
  WindEqualHeightRow's real two-pass layout); (c) implement explicit
  `flex-col items-stretch`.
- external:
  - Tailwind overflow (`overflow-x-auto` on wrapper): https://tailwindcss.com/docs/overflow
  - Tailwind min-width (`min-w-full`): https://tailwindcss.com/docs/min-width
  - shadcn Table = wrapper `overflow-x-auto` + inner `w-full`: https://github.com/shadcn-ui/ui/blob/main/apps/v4/registry/new-york-v4/ui/table.tsx
  - overflow-x + min-w are a paired contract (#12657): https://github.com/tailwindlabs/tailwindcss/discussions/12657
  - LayoutBuilder intrinsic-ban throw site (architectural guard): https://github.com/flutter/flutter/blob/master/packages/flutter/lib/src/widgets/layout_builder.dart
  - IntrinsicHeight speculative layout pass: https://api.flutter.dev/flutter/widgets/IntrinsicHeight-class.html
  - Flutter DataTable favors fixed/flex cols + external horizontal scroll: https://api.flutter.dev/flutter/material/DataTable-class.html
  - web `_owner` cascade inferred from LayoutBuilder per-callback BuildScope (PR #154694 / issue #156818): https://github.com/flutter/flutter/pull/154694
- verification: a table with flex columns fills its container on desktop and scrolls
  horizontally on a 375px phone with no assertion, AND renders without error inside
  an `items-stretch` grid cell (pump under `IntrinsicHeight` in a test).

### [WIND-5] Silent no-op DX (unknown token, flex-wrap, h-full-in-scroll)

- package: wind
- severity: P1
- effort: low
- status: open
- evidence: Hit `flex-wrap` doing nothing (had to use `wrap`) on the Welcome
  sign-in switch; the class silently no-op'd with no warning.
- locations:
  - `wind/lib/src/parser/wind_parser.dart:306-316` (unknown className silently dropped; no debug warning)
  - `wind/lib/src/parser/parsers/flexbox_grid_parser.dart:68-73` (`flex-wrap` unsupported; must use `wrap`)
  - `wind/lib/src/parser/parsers/sizing_parser.dart:155-175` (`w-full` in a Row silently becomes `Expanded`; `h-full` in a scrollable -> cryptic Flutter unbounded error)
  - `wind/lib/src/theme/defaults/font_sizes.dart` (`text-7xl`+ silently dropped)
  - other silent traps: `ps-/pe-/ms-/me-`, bare `transition`
  - example code still uses broken `flex-wrap`: `wind/example/lib/pages/*`
- problem: unknown/unsupported utilities vanish with zero feedback; typos are
  invisible; several Tailwind-literate expectations silently diverge.
- fix: kDebugMode "unknown token" warning (dedup per token per session); alias
  `flex-wrap` -> `wrap` with a hint; a wind-specific actionable assert for
  `h-full` inside a scrollable; fix the example pages.
- external: none (DX polish).
- verification: typing `flex-wrap` or an unknown class emits a debug warning; `flex-wrap` wraps.

### [MS-6] 9 components need container binding to render (testability)

- package: magic_starter
- severity: P1
- effort: low
- status: open
- evidence: The weekly-digest widget test threw "Service [magic_starter] is not
  registered in the container" until `Magic.singleton('magic_starter', () =>
  MagicStarterManager())` was added to setUp.
- locations:
  - `magic_starter/lib/src/facades/magic_starter.dart:28-29,409` (every theme accessor chains `Magic.make<MagicStarterManager>('magic_starter')`, throws if unbound)
  - components resolving theme in build(): card.dart:110, page_header.dart:108, dialog.dart:76, confirm_dialog, bottom_sheet, social_divider.dart:24, magic_starter_auth_form_card, magic_starter_two_factor_modal, magic_starter_password_confirm_dialog
  - theme classes already have const defaults: `magic_starter_manager.dart:131,139,143,147`
- problem: 9 components fail to render standalone (tests, preview catalogs) without
  a manual 3-line container bootstrap, even though the themes have const defaults.
- fix: (a) ship `lib/src/testing/magic_starter_test_utils.dart` with
  `setUpMagicStarterForTests()` (export from barrel); (b) defensive facade accessor
  that falls back to a const default manager when unbound.
- external: none (Laravel-style testing fakes are the mental model).
- verification: a widget test renders `Card`/`PageHeader` with zero container setup.

### [MS-7] Flat-export name collisions + no single theme-adoption hook

- package: magic_starter
- severity: P1
- effort: medium
- status: open
- evidence: uptizm imports magic_starter with `hide EmptyState` in 25 files (and
  `hide ErrorState` in 1); it re-skins only 2 of 7 theme structs in main.dart.
- locations:
  - `magic_starter/lib/magic_starter.dart:58-84` (30+ classes flat-exported unprefixed: Button, Badge, Input, EmptyState, ErrorState, Tabs, ...)
  - collisions: `empty_state/index.dart:3` (25 `hide` in uptizm), `error_state/index.dart:3` (1 `hide`)
  - theming: `magic_starter/lib/src/configuration/magic_starter_theme.dart` (7 theme classes, 68 properties); uptizm uses only `useTheme`(card) + `useModalTheme` in `uptizm/lib/main.dart:59,75`
- problem: flat unprefixed exports force consumers to memorize a collision list and
  `hide` names; theming requires up to 7 separate struct builds with no single
  "adopt my design system" entry point.
- fix: (a) prefix component exports (`MSButton`, `MSEmptyState`, ...) with a
  backward-compatible compat barrel, or ship a documented `hide` list; (b) add
  `MagicStarter.useWindTheme(WindThemeData)` deriving all 7 theme structs from the
  17 semantic alias keys in one call.
- external: none.
- verification: uptizm drops most `hide` directives; one `useWindTheme(...)` call
  visually aligns all magic_starter surfaces to the consumer palette.

---

## P2 findings

### [MAGIC-8] i18n gaps

- package: magic (localization + routing/title)
- severity: P2
- effort: varied
- status: open (title separator+i18n+reapply already DONE in a prior session)
- evidence: no-loader `trans('uptizm.status.down')` returned the raw 18-char key,
  causing false fixed-width overflows in tests (see memory `statusbadge-raw-key-test-overflow`).
- locations:
  - `magic/lib/src/localization/translator.dart:195` (key-passthrough on miss; no debug warning)
  - `translator.dart:194-204` (`:name` placeholders; no pluralization / trans_choice)
  - `translator.dart:115` (`LocaleChanged` dispatched; no consumer uses it)
  - `magic/lib/src/facades/lang.dart:79-84` (`setLocale` -> `Magic.reload()` full restart)
  - `uptizm/lib/resources/views/settings/language_settings_view.dart:38-41` (mock: never calls `Lang.setLocale`); `uptizm/assets/lang/` (en.json only)
  - title system (done): `magic/lib/src/routing/title_manager.dart`, `magic/lib/src/foundation/magic_app_widget.dart:217-220,256`
- problem: (a) missing keys are invisible in debug; (b) no pluralization; (c) no
  dynamic route-title parameters (`Monitor: :name`); (d) no real locale-switch recipe.
- fix: kDebugMode missing-key log; `trans_plural(key, count, replace)`;
  `TitleManager.setRouteTitle` param overload (or document `MagicTitle` for dynamic
  titles); document a locale-switch + Vault-persist recipe and wire the uptizm switcher.
- external: Laravel Notifications trans_choice model (partial).
- verification: a missing key logs a debug warning; a pluralized string resolves;
  a dynamic route shows `Monitor: <name>`.

### [MAGIC-9] MVC ergonomics: controller-binding boilerplate, route ordering footgun

- package: magic (view/controller + routing) + magic_cli (stubs)
- severity: P2
- effort: medium
- status: open
- evidence: every uptizm MagicStatefulView repeats `Magic.findOrPut(X.new)` in
  initState (12x); the routes file repeats the static-before-dynamic ordering
  warning 5x, and `/incidents/digest` had to be registered before `/incidents/:id`.
- locations:
  - controller binding: `magic/lib/src/ui/magic_view.dart:123` (MagicStatefulViewState.initState `Magic.find<T>()` throws if unregistered); repeated in uptizm dashboard_view.dart:66, monitor_detail_view.dart:129, monitors_list_view.dart:79 (12 views)
  - route ordering: `magic/lib/src/routing/magic_router.dart:218-238` (`_buildRoutes` iterates in registration order, no sorting); uptizm app.dart:48-49,138-141,177-180 (repeated manual warnings)
  - stub gap: `magic/lib/src/cli/commands/make_view_command.dart:60-61` + `magic/assets/stubs/view.stateful.stub:8` (scaffolds plain StatefulWidget, not MagicStatefulView)
  - thin-controller boundary undocumented: `magic/lib/src/ui/magic_view.dart:49-137`
- problem: repeated controller-binding boilerplate not discoverable from the
  scaffold; a mis-ordered static route silently breaks (go_router first-match);
  "when do I need a controller" is undocumented.
- fix: add a `controllerFactory` param to MagicStatefulView (auto-register); make
  `_buildRoutes` sort static segments before dynamic (or warn at build); ship a
  MagicStatefulView stub; document the controller-vs-StatelessWidget boundary.
- external: none (framework API clarity; go_router first-match is upstream behavior).
- verification: a scaffolded view binds its controller with no initState boilerplate;
  registering `/x/:id` before `/x/new` still resolves `/x/new` (auto-ordered) or warns.

### [CLI-10] design:sync no custom token families; design:lint false positives

- package: magic_cli (design tooling)
- severity: P2
- effort: medium
- status: open
- evidence: uptizm's status token families (up/down/degraded/paused/info/ai) are
  hand-authored in `uptizm/lib/config/uptizm_status_tokens.dart` and merged in
  `uptizm/lib/main.dart:91-98`, because design:sync cannot emit custom families;
  design:lint then emits 17 false "unreferenced token" warnings.
- locations:
  - `magic/lib/src/cli/commands/design_sync_command.dart:45-63,133-176` (hardcoded 17-role mapping; no data-driven family emission)
  - `magic/lib/src/cli/helpers/design_md_parser.dart:102-111,160-189` (DesignSpec frozen at 5 keys; no `tokenFamilies`)
  - `magic/lib/src/cli/commands/design_lint_command.dart:254-287` (orphaned-token rule scans only `spec.components.values`, never code/view usage)
- problem: no first-class custom-token-family mechanism; lint's reference scan is
  too narrow, producing false positives for tokens used in recipes/views.
- fix: add a `tokenFamilies:` section to DESIGN.md + DesignSpec (each family carrying
  variant keys like solid/soft/soft-foreground); design:sync emits them as wind
  aliases; design:lint widens its reference scan to code/views and exempts families.
- external: none.
- verification: uptizm's status tokens are emitted by design:sync (delete the
  hand-authored file + main.dart merge); design:lint reports 0 false positives.

### [DEV-11] Web render-errors under-surfaced to agents

- package: dusk + telescope (+ magic_devtools)
- severity: P2
- effort: varied
- status: open
- evidence: the WIND-4 LayoutBuilder crash was web-only and invisible to widget
  tests; it was only caught by launching dusk-controlled Chrome and reading
  `dusk:exceptions`.
- locations:
  - capture works: `dusk/lib/src/dusk_error_capture.dart:42-89` (FlutterError.onError ring buffer), `telescope/lib/src/watchers/exception_watcher.dart:33-88` (FlutterError + PlatformDispatcher), `dusk/lib/src/extensions/ext_snapshot.dart:141-167` (renderErrors in snapshot)
  - gaps: `telescope/lib/src/records/exception_record.dart` (no `platform` field), `dusk/lib/src/dusk_error_capture.dart:128-134` (only `overflow` tag + generic fallback), `dusk/lib/src/commands/dusk_doctor_command.dart` (env-only, no runtime screen-health)
- problem: fatal web crashes can bypass the buffer; no platform field to branch on
  web-only asserts; coarse error tags; no one-call "is the current screen broken"
  signal; ErrorWidget invisible in the semantics snapshot.
- fix: add a `platform` field (kIsWeb) to ExceptionRecord; expand render-error tags
  (`layout-builder-intrinsic`, `constraints-violation`, `parent-data-widget-misuse`);
  add a `dusk:screen-health` extension (hasErrors / semanticsHealthy / telescopeWired /
  recentErrorTypes in one call); flag ErrorWidget nodes in the snapshot.
- external: none (greenfield telemetry).
- verification: `dusk:screen-health` returns a broken-screen signal after a render
  assertion; exception records carry `platform: web`.

---

## Session provenance (why each finding is trusted)

These were all hit first-hand while working uptizm this session, not speculative:

- WIND-1 / MS-2: Welcome CTA styling loss.
- WIND-3: full-screen blue-flash investigation -> WSelect hardcoded blue.
- WIND-4: recent-checks table web crash (LayoutBuilder under items-stretch).
- WIND-5: Welcome sign-in switch `flex-wrap` no-op.
- MS-6: weekly-digest test "magic_starter not registered".
- MS-7: `hide EmptyState` across uptizm; main.dart partial theming.
- MAGIC-8: raw-key overflow in tests; mock language switcher.
- MAGIC-9: repeated controller binding + route ordering comments.
- CLI-10: uptizm_status_tokens.dart workaround + 17 lint warnings.
- DEV-11: LayoutBuilder crash caught only via dusk, invisible to tests.

## Recommended sequencing

1. WIND-1 + MS-2 together (one recipe-layer change; highest leverage; externally confirmed).
2. WIND-3 (token integrity) and WIND-5 (cheap DX win).
3. MS-6, MS-7 (consumer ergonomics).
4. WIND-4 (hardest; deserves its own plan).
5. MAGIC-8/9, CLI-10, DEV-11 (framework maturity, as capacity allows).
