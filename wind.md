# Design System: Uptizm

## 1. Atmosphere & Visual Tone

Minimal, clean, and confident. A monitoring dashboard that prioritizes clarity and quick comprehension over decoration. Content-first with generous whitespace, subtle borders, and restrained color usage. Feels professional yet approachable for both DevOps engineers scanning incident timelines and startup founders checking uptime at a glance. Inspired by BetterStack's quiet confidence: data speaks, chrome stays silent.

## 2. Color Palette & Roles

### Brand Palette: Primary (Sage Green, hue 155)

| Shade | Hex | HSL | Usage |
|:------|:----|:----|:------|
| 50 | #EDF8F3 | 155, 50%, 96% | Tinted backgrounds, hover states |
| 100 | #D2EDDF | 155, 45%, 88% | Light accent backgrounds |
| 200 | #A3D9BF | 155, 40%, 75% | Soft badges, progress fills |
| 300 | #5EBD96 | 155, 48%, 55% | Secondary accents |
| 400 | #20AF74 | 155, 70%, 41% | Hover/active states |
| 500 | #009E60 | 155, 100%, 31% | Primary actions, CTAs, brand anchor |
| 600 | #008551 | 153, 100%, 26% | Pressed states |
| 700 | #006B3F | 151, 100%, 21% | Dark mode primary text on light surfaces |
| 800 | #00522F | 149, 100%, 16% | Dark mode pressed states |
| 900 | #013820 | 147, 95%, 11% | Dark mode deep backgrounds |
| 950 | #022113 | 145, 90%, 7% | Darkest tint |

### Color Roles

| Role | Light | Dark | Usage |
|:-----|:------|:-----|:------|
| Primary | bg-primary | dark:bg-primary-400 | Main brand action, CTAs, active navigation |
| Primary Text | text-white | dark:text-gray-900 | Text on primary backgrounds |
| Primary Subtle | bg-primary-50 | dark:bg-primary-900/30 | Tinted surfaces, selected rows |
| Surface | bg-white | dark:bg-gray-900 | App background |
| Elevated Surface | bg-white | dark:bg-gray-800 | Cards, sheets, dialogs |
| Elevated Surface Alt | bg-gray-50 | dark:bg-gray-800/50 | Nested cards, table rows |
| Text Primary | text-gray-900 | dark:text-white | Headings, key values, high-emphasis |
| Text Secondary | text-gray-600 | dark:text-gray-400 | Body text, supporting details |
| Text Muted | text-gray-400 | dark:text-gray-500 | Placeholders, timestamps, disabled |
| Border Default | border-gray-200 | dark:border-gray-700 | Dividers, card outlines, separators |
| Border Input | border-gray-300 | dark:border-gray-600 | Form field outlines |
| Border Focus | border-primary | dark:border-primary-400 | Focused input outlines |

### Semantic / Status Colors

| Role | Light | Dark | Usage |
|:-----|:------|:-----|:------|
| Success / Up | bg-green-500 text-green-700 | dark:bg-green-500 dark:text-green-400 | Monitor up, healthy, confirmations |
| Success Subtle | bg-green-50 | dark:bg-green-900/30 | Success badge background |
| Warning / Degraded | bg-amber-500 text-amber-700 | dark:bg-amber-500 dark:text-amber-400 | Degraded performance, cautions |
| Warning Subtle | bg-amber-50 | dark:bg-amber-900/30 | Warning badge background |
| Error / Down | bg-red-500 text-red-700 | dark:bg-red-500 dark:text-red-400 | Monitor down, critical, destructive |
| Error Subtle | bg-red-50 | dark:bg-red-900/30 | Error badge background |
| Info | bg-blue-500 text-blue-700 | dark:bg-blue-500 dark:text-blue-400 | Informational, maintenance, tips |
| Info Subtle | bg-blue-50 | dark:bg-blue-900/30 | Info badge background |
| Paused / Unknown | bg-gray-400 text-gray-600 | dark:bg-gray-500 dark:text-gray-400 | Paused monitors, unknown states |

### Domain-Specific Mappings

| Monitor State | Color Role | Icon Signal |
|:-------------|:-----------|:------------|
| Up | Success | check_circle |
| Down | Error | error |
| Degraded | Warning | warning |
| Maintenance | Info | build |
| Paused | Paused | pause_circle |
| Pending | Paused | schedule |

## 3. Typography

| Role | className | Usage |
|:-----|:----------|:------|
| Page Title | text-2xl font-bold text-gray-900 dark:text-white | Screen heading (one per page) |
| Section Heading | text-lg font-semibold text-gray-900 dark:text-white | Card titles, group headings |
| Subsection | text-base font-semibold text-gray-800 dark:text-gray-100 | Nested section titles |
| Body | text-base font-normal text-gray-600 dark:text-gray-400 | Paragraphs, descriptions |
| Secondary | text-sm font-normal text-gray-500 dark:text-gray-400 | Supporting details, captions |
| Label | text-sm font-medium text-gray-700 dark:text-gray-300 | Form labels, stat labels |
| Metadata | text-xs font-normal text-gray-400 dark:text-gray-500 | Timestamps, counts, IDs |
| Link | text-sm font-medium text-primary dark:text-primary-400 | Inline navigation, clickable text |
| Error | text-sm font-medium text-red-600 dark:text-red-400 | Validation messages |
| Value Large | text-3xl font-bold text-gray-900 dark:text-white | Dashboard stat values |
| Value Medium | text-xl font-semibold text-gray-900 dark:text-white | Card stat values |
| Code | text-sm font-mono text-gray-800 dark:text-gray-200 | URLs, technical identifiers |

## 4. General Style Direction

- Corner radius (cards): rounded-xl
- Corner radius (buttons): rounded-lg
- Corner radius (inputs): rounded-lg
- Corner radius (badges): rounded-full
- Corner radius (avatars): rounded-full
- Shadow depth: shadow-sm
- Border style: Thin and subtle, 1px gray-200/gray-700
- Depth approach: Flat with border separation (not shadow-driven)
- Icon style: outlined (Material Icons outlined variants)
- Transition feel: snappy (150ms, ease-out)

## 5. Layout Principles

- Screen padding: p-4 lg:p-6
- Section gap: gap-6
- Component gap: gap-4
- Card padding: p-4 lg:p-6
- Input height: h-11 (44pt minimum touch target)
- Button height: h-11 (44pt minimum, py-3 px-4 for text buttons)
- Icon button size: p-3 (44pt with 20px icon)
- Navigation pattern: sidebar (desktop), bottom tab-bar (mobile, max 5)
- Max content width: full (no max-w constraints)
- Grid columns (mobile): 1, (tablet): 2, (desktop): 2-4
- List item spacing: gap-3
- Empty state alignment: center
- Chart minimum height: h-64 (256px)
- Chart padding: p-4

### Spacing Scale Reference

| Token | Value | Usage |
|:------|:------|:------|
| gap-1 / p-1 | 4px | Tight inline spacing (icon+text) |
| gap-2 / p-2 | 8px | Compact element spacing |
| gap-3 / p-3 | 12px | List items, form fields |
| gap-4 / p-4 | 16px | Component padding, card mobile |
| gap-5 / p-5 | 20px | Medium sections |
| gap-6 / p-6 | 24px | Section gaps, card desktop |
| gap-8 / p-8 | 32px | Major section separators |

### Responsive Breakpoints

| Name | Width | Target |
|:-----|:------|:-------|
| sm | 640px | Large phones |
| md | 768px | Tablets |
| lg | 1024px | Desktop |
| xl | 1280px | Large desktop |

## 6. Component Style Tokens

### Cards

- className: bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700
- No shadow by default (flat with border)
- Hover state (if interactive): hover:border-gray-300 dark:hover:border-gray-600

### Buttons

| Variant | className |
|:--------|:----------|
| Primary | bg-primary text-white rounded-lg py-3 px-4 font-medium hover:bg-primary-600 active:bg-primary-700 |
| Secondary | bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg py-3 px-4 font-medium hover:bg-gray-200 dark:hover:bg-gray-600 |
| Outline | border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg py-3 px-4 font-medium hover:bg-gray-50 dark:hover:bg-gray-800 |
| Ghost | text-gray-600 dark:text-gray-400 rounded-lg py-3 px-4 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 |
| Destructive | bg-red-500 text-white rounded-lg py-3 px-4 font-medium hover:bg-red-600 active:bg-red-700 |
| Icon | p-3 rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 |

### Badges / Status Indicators

| Variant | className |
|:--------|:----------|
| Success | bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-400 text-xs font-medium px-2.5 py-1 rounded-full |
| Warning | bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 text-xs font-medium px-2.5 py-1 rounded-full |
| Error | bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-400 text-xs font-medium px-2.5 py-1 rounded-full |
| Info | bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 text-xs font-medium px-2.5 py-1 rounded-full |
| Neutral | bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 text-xs font-medium px-2.5 py-1 rounded-full |

### Inputs

- className: w-full h-11 px-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-base placeholder:text-gray-400 dark:placeholder:text-gray-500 focus:border-primary dark:focus:border-primary-400 focus:ring-2 focus:ring-primary/20

### Stat Cards (Dashboard)

- className: bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 lg:p-6
- Label: Label typography role
- Value: Value Large or Value Medium typography role
- Icon container: w-10 h-10 rounded-lg bg-primary-50 dark:bg-primary-900/30 flex items-center justify-center

### Empty States

- Container: w-full flex flex-col items-center justify-center py-16
- Icon container: w-16 h-16 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center mb-4
- Icon: text-3xl text-gray-400 dark:text-gray-500
- Title: Section Heading typography
- Description: Secondary typography, text-center max-w-sm
- CTA: Primary button variant

### Skeleton / Loading

- Shimmer base: bg-gray-200 dark:bg-gray-700 animate-pulse rounded-lg
- Match target element dimensions
- No spinners; skeleton screens only
