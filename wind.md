# Design System: Uptizm

## 1. Atmosphere & Visual Tone

Minimal, clean, and confident. A monitoring dashboard that prioritizes clarity and quick comprehension over decoration. Content-first with generous whitespace, subtle borders, and restrained color usage. Feels professional yet approachable for both DevOps engineers scanning incident timelines and startup founders checking uptime at a glance. Data speaks, chrome stays silent. Cards use tinted gray surfaces (bg-gray-50) rather than pure white to create subtle depth hierarchy against the white page background.

## 2. Color Palette & Roles

### Brand Palette: Primary (Sage Green, hue 155)

| Shade | Hex | HSL | Usage |
|:------|:----|:----|:------|
| 50 | #ECFDF5 | 152, 81%, 96% | Tinted backgrounds, hover states |
| 100 | #D1FAE5 | 149, 80%, 90% | Light accent backgrounds |
| 200 | #A7F3D0 | 152, 76%, 80% | Soft badges, progress fills |
| 300 | #6EE7B7 | 156, 72%, 67% | Secondary accents |
| 400 | #34D399 | 160, 64%, 52% | Hover/active states |
| 500 | #009E60 | 155, 100%, 31% | Primary actions, CTAs, brand anchor |
| 600 | #008750 | 153, 100%, 26% | Pressed states |
| 700 | #006D40 | 151, 100%, 21% | Dark mode primary text on light surfaces |
| 800 | #005430 | 149, 100%, 16% | Dark mode pressed states |
| 900 | #003D23 | 150, 100%, 12% | Dark mode deep backgrounds |
| 950 | #002414 | 147, 100%, 7% | Darkest tint |

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
| Section Title (Card) | text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400 | ContentSection header, uppercase text |
| Subsection | text-base font-semibold text-gray-800 dark:text-gray-100 | Nested section titles |
| Body | text-base font-normal text-gray-600 dark:text-gray-400 | Paragraphs, descriptions |
| Secondary | text-sm font-normal text-gray-500 dark:text-gray-400 | Supporting details, captions |
| Label | text-sm font-medium text-gray-700 dark:text-gray-300 | Form labels, stat labels |
| Metadata | text-xs font-normal text-gray-400 dark:text-gray-500 | Timestamps, counts, IDs |
| Link | text-sm font-medium text-primary dark:text-primary-400 | Inline navigation, clickable text |
| Error | text-sm font-medium text-red-600 dark:text-red-400 | Validation messages |
| Value Large | text-2xl font-bold text-gray-900 dark:text-white | Dashboard stat values, stat card values |
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
- Navigation pattern: fixed sidebar w-64 (desktop >=lg), bottom tab-bar h-[49px] (mobile \<lg, max 5)
- Bottom tab: icon text-[22px] + label text-[10px], active state via primary color
- Sidebar: brand header h-16 + nav items with rounded-lg hover, active state bg-primary-50 text-primary
- App shell: Scaffold with wColor(context, 'white', darkColorName: 'gray', darkShade: 900) background
- Mobile body: SafeArea wrapping content
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

### Cards (ContentSection pattern)

- className: bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700
- No shadow by default (flat with border, tinted surface)
- Section header: icon text-[16px] + uppercase title text-xs font-bold tracking-wide text-gray-500, separated by border-b
- Supports noPadding mode for list content (check rows, config rows)
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
| Success | bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 text-xs font-semibold px-2.5 py-1 rounded-full |
| Warning | bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400 text-xs font-semibold px-2.5 py-1 rounded-full |
| Error | bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 text-xs font-semibold px-2.5 py-1 rounded-full |
| Info | bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 text-xs font-semibold px-2.5 py-1 rounded-full |
| Neutral | bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 text-xs font-semibold px-2.5 py-1 rounded-full |

### Inputs

- className: w-full h-11 px-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-base placeholder:text-gray-400 dark:placeholder:text-gray-500 focus:border-primary dark:focus:border-primary-400 focus:ring-2 focus:ring-primary/20

### Stat Cards (Compact)

- className: bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4
- Label: text-xs text-gray-500 dark:text-gray-400
- Value: text-2xl font-bold text-gray-900 dark:text-white
- Icon: inline text-[16px] text-gray-400 dark:text-gray-500 (no container, placed in header row)
- Trend (optional): text-xs font-medium, positive green-600/green-400, negative red-600/red-400, with directional dot (w-1.5 h-1.5 rounded-full)
- Layout: flex flex-col gap-2, header row has icon + label, value row has value + trend

### Empty States

- Container: w-full flex flex-col items-center justify-center py-16
- Icon container: w-16 h-16 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center mb-4
- Icon: text-3xl text-gray-400 dark:text-gray-500
- Title: Section Heading typography
- Description: Secondary typography, text-center max-w-sm
- CTA: Primary button variant

### Info Badges (Pill-shaped metadata)

- className: flex flex-row items-center gap-1 px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-800
- Icon: text-[12px] text-gray-400 dark:text-gray-500
- Label: text-xs text-gray-600 dark:text-gray-400
- Usage: monitor URL, response time, check interval, locations

### Status Badge (Dot + Label)

- className: flex flex-row items-center gap-1.5 px-2.5 py-1 rounded-full
- Dot: w-2 h-2 rounded-full, color matches status (green/red/yellow/gray)
- Label: text-xs font-semibold
- Backgrounds: up:bg-green-100, down:bg-red-100, degraded:bg-yellow-100, paused:bg-gray-100
- Dark backgrounds: up:dark:bg-green-900/30, down:dark:bg-red-900/30, etc.

### Check History Row

- className: flex flex-row items-center py-3.5 px-4 gap-3 border-b border-gray-200 dark:border-gray-700
- Status dot: w-2.5 h-2.5 rounded-full (green/red/yellow by status)
- Response time: w-[60px] fixed width, font-mono
- Status code: px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800, font-mono text-xs
- Error state: full-width error message in red replaces response time / status code

### Config Row

- className: flex flex-row items-center justify-between py-3 px-4 border-b border-gray-200 dark:border-gray-700
- Label: text-sm text-gray-500 dark:text-gray-400
- Value: text-sm font-mono font-medium text-gray-900 dark:text-white

### Skeleton / Loading

- Shimmer base: bg-gray-200 dark:bg-gray-700 animate-pulse rounded-lg
- Match target element dimensions
- No spinners; skeleton screens only
