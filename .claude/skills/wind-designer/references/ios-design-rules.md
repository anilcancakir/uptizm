# Apple iOS HIG Rules for Flutter/Wind UI

Compressed, actionable iOS HIG. Flutter/Wind-focused.

[Design Philosophy](#design-philosophy) | [Accessibility](#accessibility) | [Color System](#color-system) | [Typography](#typography) | [Layout](#layout) | [Navigation](#navigation) | [Components](#components) | [Gestures](#gestures) | [Anti-Patterns](#anti-patterns)

## Design Philosophy

**Clarity**: Make text readable at every size. Keep decoration minimal. Use spacing, color, font weight for hierarchy.
**Deference**: Let content fill the screen. Use translucency/blur for context. Minimize bezels, gradients, decorative shadows.
**Depth**: Use layered surfaces and realistic motion for hierarchy. Transitions reinforce spatial relationships.

## Accessibility

Touch targets and contrast ratios: see `wind-ui/references/design-tokens.md` (Touch Target Rules, Color System).

| Rule | Requirement |
|:-----|:------------|
| Dynamic Type | Support all 12 sizes. Layouts must reflow without truncation |
| VoiceOver | Label every interactive element. Navigation order = reading order |
| Color as signal | Never use color alone. Pair with text, shape, or icon |
| Reduce Motion | Respect system setting. Provide static alternatives for all animations |
| Modal focus | Trap VoiceOver focus inside modals. Background content unreachable |
| Disabled state | Use `opacity: 0.3-0.4`, never remove controls entirely |

## Color System

| Role | iOS Color (light/dark) | Wind Mapping | Usage |
|:-----|:-----------------------|:-------------|:------|
| Primary | systemBlue #007AFF/#0A84FF | `bg-primary`, `text-primary` | Links, buttons, tint |
| Success | systemGreen #34C759/#30D158 | `bg-green-500`, `text-green-600` | Confirmation |
| Error | systemRed #FF3B30/#FF453A | `bg-red-500`, `text-red-600` | Deletion, errors |
| Warning | systemOrange #FF9500/#FF9F0A | `bg-yellow-500`, `text-yellow-600` | Caution |
| Premium | systemPurple #AF52DE/#BF5AF2 | `bg-purple-500`, `text-purple-600` | Special features |
| Info | systemTeal #5AC8FA/#64D2FF | `bg-blue-500`, `text-blue-600` | Informational |

- Always define light + dark: `bg-white dark:bg-gray-900`
- Dark mode layers (lighter = more elevated): `gray-900` (base) > `gray-800` (surface) > `gray-700` (elevated)
- Never hardcode hex for UI chrome. Use semantic Wind tokens
- Preserve user content colors. Only adapt your own UI surfaces

## Typography

| Style | Size | Weight | Wind Equivalent |
|:------|:-----|:-------|:----------------|
| Large Title | 34pt | Bold | `text-2xl font-bold` |
| Title 1 | 28pt | Regular | `text-xl` |
| Title 2 | 22pt | Regular | `text-lg` |
| Title 3 | 20pt | Regular | `text-lg` |
| Headline | 17pt | Semibold | `text-base font-semibold` |
| Body | 17pt | Regular | `text-base` |
| Callout | 16pt | Regular | `text-base` |
| Subheadline | 15pt | Regular | `text-sm` |
| Footnote | 13pt | Regular | `text-sm` |
| Caption 1 | 12pt | Regular | `text-xs` |
| Caption 2 | 11pt | Regular | `text-xs` |

- Min 11pt. Bold/semibold for titles, regular for body. Heavier weights at larger sizes, lighter at smaller

## Layout

| Rule | Value |
|:-----|:------|
| Standard margin (compact) | 16pt (`p-4`) |
| Standard margin (regular) | 20pt (`p-5`) |
| Intra-group spacing | 8pt (`gap-2`) |
| Section spacing | 20pt (`gap-5`) |
| Min touch area | 44x44pt |
| Safe area | Always constrain primary content inside safe area |
| Readable width | Limit text width on large screens |

**Size classes**: iPhone Portrait = Compact width, Regular height. iPhone Landscape = Compact/Compact. iPad = Regular/Regular.

**Concentric shapes**: Nest radii as `inner_radius = parent_radius - padding`. Use `rounded-full` for iPhone edge elements. Center elements optically.

## Navigation

| Pattern | When | Rules |
|:--------|:-----|:------|
| **Tab Bar** | Top-level sections (3-5 tabs) | Persist everywhere. Never hide. Never switch programmatically. Each tab = distinct area |
| **Hierarchical Push** | Parent-detail drill-down | Back button = previous title. Keep tab bar visible. Chevron = push |
| **Modal Sheet** | Focused tasks | Present from bottom. Explicit dismiss (Cancel/Done/swipe). Medium detent for quick info |

- Limit modal stacking. Disable swipe-dismiss with unsaved data. Action disabled until required fields filled
- Modal nav bar: title (center), action (right, bold verb), Cancel (left)

## Components

### Buttons

| Style | Emphasis | Wind className |
|:------|:---------|:---------------|
| Filled | Highest | `bg-primary text-white rounded-lg py-3 px-5` |
| Tinted | High | `bg-primary/10 text-primary rounded-lg py-3 px-5` |
| Gray | Medium | `bg-gray-200 dark:bg-gray-700 rounded-lg py-3 px-5` |
| Plain | Lowest | `text-primary` (no background) |

- Filled = primary action. Verb phrases, Title Case. Never make destructive the primary
- Destructive: `bg-red-500 text-white`, always paired with Cancel

### Text Fields
- Single-line, fixed height, rounded corners. Placeholder describes expected input. Clear button for reset
- Match keyboard type (email/number/phone/URL). Secure field for passwords

### Lists

| Style | Wind Approach |
|:------|:--------------|
| Plain | `flex flex-col` with dividers |
| Grouped | Sections with `bg-gray-50 dark:bg-gray-800` headers |
| Inset Grouped | `mx-4 rounded-xl bg-white dark:bg-gray-800` |

- Min row height: 44pt. Async images. Pull-to-refresh. Swipe-to-delete

### Alerts
- Two buttons preferred. Primary right, Cancel left. Destructive = red, Cancel = bold default
- 3+ options: use action sheet instead

### Sheets
- Medium detent (~50%) for contextual info. Large for complex forms. Show grabber. Explicit close

## Gestures

| Gesture | Standard Behavior |
|:--------|:-----------------|
| Tap | Activate control, select item |
| Drag | Move element, scroll content |
| Swipe (horizontal) | Navigate back, reveal delete |
| Pinch | Zoom in/out |
| Double tap | Toggle zoom |
| Long press | Context menu, reorder mode |

- Never override system gestures (bottom/top swipe). Shortcuts supplement controls, never replace

## Anti-Patterns

| Mistake | Fix |
|:--------|:----|
| Touch target below 44pt | `py-3 px-4` minimum on interactive elements |
| Color-only status | Add icon or text label alongside color |
| Hardcoded hex in UI chrome | Use Wind tokens (`bg-primary`, `text-gray-900`) |
| Missing dark mode | Always pair: `bg-white dark:bg-gray-900` |
| Modal without dismiss | Add Cancel/Done + swipe-to-dismiss |
| Horizontal scroll on main content | Fit layouts to screen width |
| Truncating at large text sizes | Vertical reflow, never clip |
| Destructive as primary button | `bg-red-500` with separate Cancel |
| Lost swipe-back gesture | Preserve edge swipe on all push screens |
| Tab bar hidden mid-navigation | Keep visible across all pushes |
| Splash screen on launch | Match launch screen to first app screen |
| Font below 11pt | `text-xs` (12px) as absolute minimum |
