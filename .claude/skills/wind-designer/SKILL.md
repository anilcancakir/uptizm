---
name: wind-designer
description: "Design system creation and enforcement for Wind UI Flutter apps. Generates wind.md + WindThemeData, enforces design tokens across codebase, audits compliance."
when_to_use: |
  TRIGGER: "set up design system", "create wind.md", "design this app", "build theme", "audit design consistency", "update design language", "color palette for", "typography for", "visual tone", "app atmosphere", "brand style", "design review", any WindThemeData customization beyond defaults.
  DO NOT TRIGGER: className syntax questions (wind-ui), widget API usage (wind-ui), layout debugging (wind-ui), documentation writing (wind-doc-writer), example page creation (wind-example-builder).
---

# Wind Designer

Design system creation, enforcement, and evolution for Wind UI Flutter apps. This skill owns design decisions (atmosphere, color roles, typography choices, style direction, layout philosophy). wind-ui owns implementation (className tokens, widget API, layout gotchas).

**MANDATORY**: Load `wind-ui` skill before any DESIGN mode work.

## Mode Detection

| Mode | Trigger Signals | Output |
|------|----------------|--------|
| SETUP | "set up design", "create theme", "new app style", no wind.md exists | wind.md + app_theme.dart |
| DESIGN | "build this screen", "create component", "design the login page" | Wind UI code following wind.md |
| UPDATE | "change primary color", "update typography", "add new color role" | Updated wind.md + synced app_theme.dart |
| AUDIT | "check design consistency", "audit styles", "design review" | Report (no auto-fix) |

---

## SETUP Mode

Goal: establish the project design language from scratch.

### Step 1: Scan Project

Read pubspec.yaml, scan lib/ structure, check for existing WindThemeData or wind.md. Note app name, dependencies, current state.

### Step 2: Interview (5 Questions)

Ask all 5 via structured question format with header + options:

1. **Purpose and audience**: What does this app do? Who uses it?
2. **Visual tone**: Pick one: minimal, bold, playful, luxury, editorial, organic, brutalist, industrial
3. **Color direction**: Provide brand hex, describe palette feel, or name a reference app
4. **Reference apps**: 1-3 apps whose style you admire (for mood alignment, not copying)
5. **Special needs**: Accessibility requirements, offline-first, heavy data tables, media-rich

Present all 5 in a single structured prompt. Do not ask one at a time.

### Step 3: Generate wind.md

Create `wind.md` at project root following `references/wind-md-template.md`. Write ALL content in English regardless of conversation language. wind.md is an LLM-consumed reference, not user-facing prose.

Fill every section based on interview answers:

- **Color Palette**: Generate full MaterialColor 10-shade palette from brand hex. Read `references/design-principles.md` for HSL shade generation process.
- **Typography**: Map roles (Page Title through Metadata) to Wind size/weight tokens.
- **General Style**: Use the Tone-to-Style Mapping table below for defaults.
- **Layout**: Define screen padding, section gaps, navigation pattern.

**wind.md content boundary**: wind.md is a design language document, not an implementation cookbook. Enforce strictly:

| Belongs in wind.md | Does NOT belong in wind.md |
|-------------------|---------------------------|
| Color roles as className tokens | Dart code or code blocks |
| Typography roles as className combos | Flutter widget names (Scaffold, SafeArea, Center) |
| Spacing/radius/shadow tokens | Flutter API calls (showModalBottomSheet, MaterialPageRoute) |
| Navigation pattern names + behavior rules | Widget-level workarounds or gotchas |
| Status/semantic color tables | Component implementation details |
| Visual descriptions ("pill badge, rounded card") | Pixel-level component specs with layout code |
| Domain-specific color mappings (status=green) | Third-party library configuration |

If the user asks to add implementation patterns to wind.md, redirect to project CLAUDE.md or component files instead. wind.md stays pure design language.

### Step 4: Generate lib/config/app_theme.dart

Create WindThemeData configuration implementing wind.md decisions. Reference `wind-ui references/theme-setup.md` for constructor signature.

- Custom colors via `colors` map (MaterialColor with generated shades)
- Font families via `fontFamilies` map if custom fonts specified
- Border radius and shadow overrides if non-default

### Step 5: User Approval

Present wind.md summary and app_theme.dart. Wait for confirmation before finalizing.

---

## DESIGN Mode

Goal: produce Wind UI code that follows wind.md exactly. Zero tolerance for deviation.

### Step 1: Extract Design Tokens

Read wind.md. Build a concrete token checklist:

```
COLOR ROLES: Primary={token} dark:{token}, Secondary={token} dark:{token}, ...
TYPOGRAPHY: PageTitle={size} {weight}, SectionHeading={size} {weight}, Body={size} {weight}, ...
STYLE: corners={rounded-token}, shadows={shadow-token}, depth={approach}
LAYOUT: screen-padding={p-token}, section-gap={gap-token}, component-gap={gap-token}
```

Every className you write must trace back to this checklist. If a token you need does not exist, stop and suggest an UPDATE before proceeding.

### Step 2: Load wind-ui Skill

Load wind-ui for className syntax, widget API, and layout patterns.

### Step 3: Inventory Existing Components

Before writing ANY code, scan the project for reusable components:

1. Grep `lib/` for existing widget classes (StatelessWidget, StatefulWidget). List all custom components with their file paths.
2. Read each component's className and props to understand what it already provides.
3. Map existing components to the current task. If a component covers 80%+ of what you need, reuse it. Do not rebuild.

### Step 4: Decompose (Component-First, Always)

Never build a page directly. Every task follows atomic design: Components -> Layouts -> Pages.

**For any task (even "design the settings page"):**

1. **Identify atoms**: What visual elements does this screen need? (status badge, stat card, action button, list row, input field, section header)
2. **Check inventory**: Which atoms already exist from Step 3? Reuse them.
3. **Build missing components first**: Each as a separate file in `lib/widgets/` or `lib/components/`. Every component gets its own file, own className binding, own dark: support.
4. **Compose layouts**: Combine components into layout sections (header, content list, action bar). Each layout is a reusable widget.
5. **Assemble page last**: The page file imports components and layouts. Page code is mostly composition, minimal logic. If the page file exceeds 150 lines, extract more components.

| Task keyword | What to build | Build order |
|-------------|--------------|-------------|
| button, card, badge, input, tag, avatar, chip | Component only | Single component file |
| list, grid, scroll, sidebar, nav, header, footer | Layout + components | Missing components first, then layout |
| screen, page, view, tab, dashboard, profile, settings | Page + layouts + components | Components -> Layouts -> Page |

FAILED if: page file contains inline widget trees that should be separate components, any visual element is duplicated instead of extracted, existing components are rebuilt instead of reused.

### Step 5: Build

CRITICAL: Every line of code must pass these 7 gates. Violating any gate is a build failure.

**Gate 1 — Wind widgets only.** Zero native Flutter layout/styling widgets in output.

| Banned | Wind Equivalent |
|--------|----------------|
| Container, SizedBox, DecoratedBox, Spacer | `WDiv` with className |
| Text, RichText | `WText` with className |
| ElevatedButton, TextButton, GestureDetector | `WButton` with className |
| TextField | `WFormInput` with className |
| Expanded | `WDiv(className: 'flex-1')` |
| Padding | `WDiv(className: 'p-4')` |
| Row | `WDiv(className: 'flex flex-row')` |
| Column | `WDiv(className: 'flex flex-col')` |

Only exception: third-party library callbacks requiring native widgets (e.g., fl_chart axis labels). Mark with `// native: required by {library}` comment.

**Gate 2 — Color compliance.** Every bg-*, text-*, border-* token must match a wind.md Color Palette role. No improvised colors. No Color() literals, no hex values, no raw ARGB in Dart code. Dart code needing Color values uses Wind helpers: `wColor(context, 'primary')`, `context.wColorExt('brand', shade: 600)`, `context.windColors['primary']`.

**Gate 3 — Typography compliance.** Every text size + weight combo must match a wind.md Typography role. Do not invent new combos.

**Gate 4 — Dark mode.** Every bg-*, text-*, border-* class has a dark: pair. No exceptions.

**Gate 5 — Touch targets.** All interactive elements >= 44pt. Buttons with text: py-3.5 minimum. Icon buttons: p-3 minimum (12px + 20px icon = 44pt). Calculate and verify for every tappable element.

**Gate 6 — Spacing compliance.** All p-*, gap-*, m-* values from wind.md Layout Principles. No ad-hoc spacing values.

**Gate 7 — Style compliance.** Corner radii (rounded-*) and shadows (shadow-*) match wind.md General Style Direction.

**Gate 8 — No string interpolation in className.** Never use `'${condition ? "classA" : "classB"}'` in className. Use Wind `states` parameter with state-prefixed classes. className must be a static string for cacheability.

Wrong:
```dart
WDiv(className: 'w-1.5 h-1.5 ${isPositive ? "bg-green-500" : "bg-red-500"}')
```

Right:
```dart
WDiv(
  className: '''
    w-1.5 h-1.5 rounded-full
    bg-red-500 dark:bg-red-400
    positive:bg-green-500 positive:dark:bg-green-400
  ''',
  states: isPositive ? {'positive'} : {},
)
```

Define ALL visual variants in one static className. Use `states: Set<String>` to toggle. Multiple states combine: `states: {if (isActive) 'active', if (isError) 'error'}`.

**Gate 9 — Multi-line className formatting.** When className exceeds 60 characters or has 3+ class groups (layout, color, state), use Dart triple-quote `'''` with one group per line:

```dart
// Short (under 60 chars, single-line OK)
WDiv(className: 'flex flex-row gap-4 p-4')

// Long (use triple-quote, group by concern)
WButton(
  className: '''
    py-3.5 px-5 rounded-xl
    bg-primary text-white dark:bg-primary-400
    hover:bg-primary-600 active:bg-primary-700
    disabled:opacity-50
  ''',
  states: {if (isDisabled) 'disabled'},
  child: WText('Submit'),
)
```

Group order: layout/size -> color/bg -> dark: variants -> state prefixes (hover/active/disabled/custom).

### Step 6: Verify Before Delivery

Do NOT deliver without running every check. Fix violations, then re-verify.

For every file written or modified, run these scans:

1. Grep for banned widgets: `Container(`, `Expanded(`, `Spacer(`, `Padding(`, `Row(`, `Column(`, `SizedBox(`, `Text(`, `RichText(`, `ElevatedButton(`, `TextButton(`. Replace any found with Wind equivalent.
2. Grep for hardcoded colors: `Color(`, `0xFF`, `0xff`. Replace with Wind helpers.
3. Grep for className interpolation: `'${` or `"${` inside className strings. Replace with `states` parameter + state-prefixed classes.
4. List every bg-*, text-*, border-* token. Cross-check against wind.md Color Palette. Replace any not in table.
5. List every text-* font-* combo. Cross-check against wind.md Typography. Replace mismatches.
6. Calculate touch target for every WButton/WAnchor: padding + content >= 44pt. Fix any below.
7. Verify dark: pair exists for every color class. Add missing pairs.

8. Component reuse check: any inline widget tree in a page file that could be a separate reusable component? Extract it. Any visual element rebuilt that already exists in `lib/`? Replace with import.

FAILED if: any banned widget remains, any hardcoded color found, any className string interpolation found, any token not traceable to wind.md, any touch target below 44pt, any color class missing dark: pair, any page file over 150 lines without component extraction, any existing component rebuilt instead of reused.

### Step 7: Evolve

When a new reusable component emerges, suggest adding it to wind.md via UPDATE mode.

---

## UPDATE Mode

Goal: modify the design language and keep implementation in sync.

### Step 1: Read Current State

Read wind.md and lib/config/app_theme.dart.

### Step 2: Apply Changes

All wind.md content stays in English regardless of conversation language.

Supported changes: new color role, palette adjustment, typography modification, corner/shadow change, component style revision. Reject Dart code, Flutter API references, or implementation patterns. Redirect those to project CLAUDE.md or code files.

### Step 3: Sync Theme

Update app_theme.dart WindThemeData to match every wind.md change. Color changes require MaterialColor shade regeneration.

### Step 4: Validate Consistency

- Every color role used or planned for use
- Typography roles cover all standard cases (title, heading, body, secondary, label, metadata, link, error)
- Spacing scale coherent
- app_theme.dart matches wind.md exactly

---

## AUDIT Mode

Goal: detect design language violations. Report only, no auto-fix.

### Step 1: Load Design Language

Read wind.md. Extract expected tokens for each role.

### Step 2: Scan Codebase

Grep lib/ for Wind widget usage and className strings.

### Step 3: Compare

| Check | What to flag |
|-------|-------------|
| Native Flutter widgets | Container, Expanded, Spacer, Padding, Row, Column, SizedBox, Text(, ElevatedButton used instead of Wind equivalents |
| Hardcoded colors | Color(), hex literals, raw ARGB in Dart code. Fix: className tokens or Wind helpers (wColor, wColorExt, windColors) |
| className interpolation | `'${condition ? "classA" : "classB"}'` in className. Fix: use `states` param + state-prefixed classes |
| Off-palette colors | bg-*, text-*, border-* tokens not in wind.md palette |
| Missing dark: variants | Any color class without dark: counterpart |
| Typography drift | Size/weight combos not matching wind.md roles |
| Touch target violations | Interactive elements below 44pt |
| Radius mismatch | rounded-* inconsistent with wind.md general style |
| Spacing outliers | p-* or gap-* outside wind.md defined scale |

### Step 4: Report

Output a table per severity:

| Severity | Meaning |
|----------|---------|
| Error | Direct wind.md violation or native widget usage. Fix before shipping. |
| Warning | Convention deviation (non-standard spacing, touch target risk). Review required. |
| Info | Consolidation opportunity. Optional cleanup. |

Report format per violation:

```
| File:Line | Found | Expected (per wind.md) | Fix |
```

---

## Tone-to-Style Mapping

| Tone | Corners | Shadow | Border | Depth | Spacing |
|------|---------|--------|--------|-------|---------|
| Minimal | rounded-lg | shadow-sm | Thin, subtle | Flat | Generous |
| Bold | rounded-xl | shadow-md | Thick, high-contrast | Elevated | Compact |
| Playful | rounded-2xl/full | shadow-lg | Colorful, rounded | Elevated | Generous |
| Luxury | rounded-md | shadow-sm | Thin, gold/muted | Subtle-shadow | Very generous |
| Editorial | rounded-none/sm | shadow-none | Strong, black | Flat | Structured grid |
| Organic | rounded-xl/2xl | shadow-md | Soft, muted | Subtle-shadow | Generous |
| Brutalist | rounded-none | shadow-none | Thick, black | Flat | Tight |
| Industrial | rounded-sm | shadow-sm | Medium, gray | Flat | Compact |

---

## Core Design Rules

1. Mobile-first. Design for smallest screen, enhance upward.
2. Dark mode mandatory. Every color includes both modes.
3. Visual hierarchy: 3 levels (primary/secondary/tertiary). Emphasize by de-emphasizing.
4. Button hierarchy: solid > outline > text. One primary per visible section.
5. iOS navigation: tab bar bottom (max 5), hierarchical push, modal bottom-up.
6. Contrast: 4.5:1 normal text, 3:1 large text (WCAG AA).
7. Color never the sole signal for meaning.
8. More space between groups than within groups.
9. Skeleton screens over spinners. Empty states are first impressions.

Load `references/ios-design-rules.md` for full iOS HIG rules. Load `references/design-principles.md` for visual hierarchy, color theory, typography, and mobile patterns.

---

## Reference Index

| File | Content | Load When |
|------|---------|-----------|
| `references/ios-design-rules.md` | Apple HIG compressed for Flutter/Wind | DESIGN mode (always), AUDIT mode, iOS navigation/component decisions |
| `references/design-principles.md` | Visual hierarchy, color system, HSL shade generation, spacing, mobile patterns | SETUP mode (tone/palette), DESIGN mode (any design decision), AUDIT mode |
| `references/wind-md-template.md` | wind.md skeleton with section structure and placeholders | SETUP mode Step 3, UPDATE mode structural changes |
